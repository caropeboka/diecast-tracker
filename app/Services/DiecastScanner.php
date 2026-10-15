<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\ScanQuotaException;
use App\Models\AppSetting;
use App\Models\Diecast;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reads diecast photos with an AI vision model (Gemini or Claude) and returns the form fields it can identify.
 */
class DiecastScanner
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are an expert diecast car collector who identifies model cars from photos for a collection tracker.

        Read everything visible: the car itself, the blister card or box, printed text, logos, numbers and stickers.
        Fill in only what you can see or confidently recognize. Use null for anything you cannot determine. A wrong
        value is worse than an empty one, because the collector will trust what you fill in.

        Field guidance:
        - name: the casting name exactly as printed on the package (e.g. "'71 Datsun 510", "Nissan Skyline GT-R (BNR34)").
          For a loose car, use the most specific name you recognize.
        - brand: the diecast maker (Hot Wheels, Matchbox, Tomica, Mini GT, Inno64, Tarmac Works, Pop Race, Kaido House,
          Majorette, Greenlight, Maisto, Johnny Lightning, M2 Machines, Auto World, Bburago, etc.).
        - make: the real-world car manufacturer (Nissan, Toyota, Porsche, ...).
        - series: the line or sub-series printed on the card (e.g. "HW J-Imports", "Car Culture: Modern Classics", "Boulevard").
        - collection_number: numbers as printed, e.g. "123/250" or "3/10"; for Tomica use "No. 23"; for Mini GT "MGT00512".
          If both a mainline number and a series number are visible, give both separated by " · ".
        - year: the release year from the card's copyright/date code if visible. Not the real car's model year.
        - scale: e.g. "1:64". Hot Wheels, Matchbox, Mini GT, Inno64 and Tarmac Works are 1:64 unless the package says otherwise.
        - color: main body color in English, short (e.g. "Metallic Blue", "White with red stripes").
        - rarity: "treasure_hunt" for a Hot Wheels mainline with the low-production circle-flame logo on the card or car;
          "super_treasure_hunt" for Spectraflame paint + Real Riders rubber tires + "TH" tampo / gold flame on the card;
          "chase" for a chase piece (Mini GT/Matchbox/Greenlight chase stickers, green machines, raw/zamac chase);
          "limited" for numbered limited runs; "exclusive" for store/event exclusives (e.g. RLC, convention, Target red);
          otherwise "regular".
        - packaging: "carded" (blister card), "boxed" (window box / box), or "loose".
        - condition: judge the packaging and car: "mint" (perfect), "near_mint" (tiny flaws), "good" (visible wear,
          soft corners, small blister dents), "fair" (creases, cracked blister), "damaged". null if you can't tell.
        - notes: one or two short sentences in Indonesian about notable details (variations, tampo, wheel type, card
          damage). null if nothing notable.
        - confidence: your overall confidence in the identification.
        PROMPT;

    /** Photos sent per scan; front + back of card + loose car is plenty. */
    public const MAX_PHOTOS = 4;

    public function __construct(private PhotoStorage $photos) {}

    public function provider(): string
    {
        return AppSetting::get('scan_provider', config('services.scan.provider')) === 'anthropic' ? 'anthropic' : 'gemini';
    }

    public function isEnabled(): bool
    {
        return filled($this->key($this->provider()));
    }

    /**
     * API key from the admin AI settings page, falling back to .env.
     */
    public function key(string $provider): ?string
    {
        return AppSetting::get("{$provider}_api_key", config("services.{$provider}.key"));
    }

    public function model(string $provider): string
    {
        return AppSetting::get("{$provider}_model", config("services.{$provider}.model"));
    }

    /**
     * Check a key by listing the models it can use. Returns model ids, newest first where the API orders them.
     *
     * @return array<int, string>
     */
    public function availableModels(string $provider, string $key): array
    {
        if ($provider === 'anthropic') {
            try {
                $page = (new Client(apiKey: $key))->models->list(limit: 100);
            } catch (AuthenticationException) {
                throw new RuntimeException('API key Claude tidak valid.');
            } catch (APIStatusException|APIConnectionException) {
                throw new RuntimeException('Tidak bisa menghubungi Claude. Cek koneksi internet.');
            }

            return array_map(fn ($model) => $model->id, $page->getItems());
        }

        try {
            $response = Http::timeout(20)
                ->withHeaders(['x-goog-api-key' => $key])
                ->get('https://generativelanguage.googleapis.com/v1beta/models', ['pageSize' => 1000]);
        } catch (ConnectionException) {
            throw new RuntimeException('Tidak bisa menghubungi Gemini. Cek koneksi internet.');
        }

        if ($response->failed()) {
            throw new RuntimeException(in_array($response->status(), [400, 401, 403])
                ? 'API key Gemini tidak valid.'
                : 'Gemini sedang bermasalah. Coba lagi nanti.');
        }

        // Keep only Gemini models that can read an image and answer (generateContent).
        return collect($response->json('models', []))
            ->filter(fn (array $model) => in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true))
            ->pluck('name')
            ->map(fn (string $name) => str_replace('models/', '', $name))
            ->filter(fn (string $name) => str_starts_with($name, 'gemini-')
                && ! preg_match('/(embedding|tts|image-generation|live|audio)/', $name))
            ->values()
            ->all();
    }

    /**
     * Identify a diecast from one or more photos of it (e.g. front and back of the card).
     *
     * @param  array<int, string>  $photoPaths
     * @return array<string, mixed>
     */
    public function scan(array $photoPaths): array
    {
        $photoPaths = array_slice(array_values($photoPaths), 0, self::MAX_PHOTOS);

        $data = $this->provider() === 'anthropic'
            ? $this->scanWithClaude($photoPaths)
            : $this->scanWithGemini($photoPaths);

        return $this->clean($data);
    }

    /**
     * Google Gemini via the REST generateContent endpoint (has a free tier).
     *
     * @param  array<int, string>  $photoPaths
     * @return array<string, mixed>
     */
    private function scanWithGemini(array $photoPaths): array
    {
        $model = $this->model('gemini');

        $parts = array_map(fn (string $path) => [
            'inline_data' => ['mime_type' => 'image/jpeg', 'data' => $this->photos->base64($path)],
        ], $photoPaths);
        $parts[] = ['text' => $this->instruction(count($photoPaths))];

        try {
            $response = Http::timeout(90)
                ->withHeaders(['x-goog-api-key' => $this->key('gemini')])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => [
                        'responseMimeType' => 'application/json',
                        'responseSchema' => $this->geminiSchema(),
                    ],
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Tidak bisa terhubung ke Gemini. Cek koneksi internet.');
        }

        if ($response->status() === 429) {
            throw new ScanQuotaException('Kuota gratis Gemini habis untuk saat ini. Tunggu sebentar (atau besok) lalu coba lagi.');
        }

        if ($response->failed()) {
            Log::warning('Gemini scan failed', ['status' => $response->status(), 'body' => $response->json('error')]);

            throw new RuntimeException(match (true) {
                $response->json('error.details.0.reason') === 'API_KEY_INVALID',
                $response->status() === 403 => 'API key Gemini tidak valid. Cek Settings → AI Scan.',
                $response->status() === 404 => "Model Gemini \"{$model}\" tidak ditemukan. Pilih model lain di Settings → AI Scan.",
                default => 'Gemini sedang bermasalah. Isi manual dulu atau coba lagi.',
            });
        }

        if ($response->json('promptFeedback.blockReason')) {
            throw new RuntimeException('AI tidak bisa memproses foto ini. Silakan isi manual.');
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');

        return $this->decode($text);
    }

    /**
     * @param  array<int, string>  $photoPaths
     * @return array<string, mixed>
     */
    private function scanWithClaude(array $photoPaths): array
    {
        $client = new Client(apiKey: $this->key('anthropic'));

        $content = array_map(fn (string $path) => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $this->photos->base64($path)],
        ], $photoPaths);
        $content[] = ['type' => 'text', 'text' => $this->instruction(count($photoPaths))];

        try {
            $message = $client->beta->messages->create(
                model: $this->model('anthropic'),
                maxTokens: 16000,
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => $this->schema()],
                ],
                system: self::SYSTEM_PROMPT,
                messages: [['role' => 'user', 'content' => $content]],
                requestOptions: ['timeout' => 90],
            );
        } catch (AuthenticationException) {
            throw new RuntimeException('API key Claude tidak valid. Cek Settings → AI Scan.');
        } catch (RateLimitException) {
            throw new ScanQuotaException('Terlalu banyak scan sekaligus. Tunggu sebentar lalu coba lagi.');
        } catch (APIStatusException $e) {
            Log::warning('Diecast scan failed', ['status' => $e->status, 'message' => $e->getMessage()]);
            throw new RuntimeException('Layanan AI sedang bermasalah. Isi manual dulu atau coba lagi.');
        } catch (APIConnectionException) {
            throw new RuntimeException('Tidak bisa terhubung ke layanan AI. Cek koneksi internet.');
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('AI tidak bisa memproses foto ini. Silakan isi manual.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $this->decode($block->text);
            }
        }

        return $this->decode('');
    }

    private function instruction(int $photoCount): string
    {
        return $photoCount > 1
            ? "These {$photoCount} photos all show the same diecast (for example the front and back of the card, or the car out of the package). Combine what you can read from all of them, identify it and fill in the fields."
            : 'Identify this diecast and fill in the fields.';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $text): array
    {
        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException('Hasil AI tidak terbaca. Coba foto ulang dengan cahaya lebih terang.');
        }

        return $data;
    }

    /**
     * Drop values outside the allowed options so the form never receives junk.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $fields = array_intersect_key($data, array_flip([
            'name', 'brand', 'make', 'series', 'collection_number', 'year', 'scale',
            'color', 'rarity', 'packaging', 'condition', 'notes', 'confidence',
        ]));

        foreach (['rarity' => Diecast::RARITIES, 'packaging' => Diecast::PACKAGINGS, 'condition' => Diecast::CONDITIONS] as $key => $allowed) {
            if (isset($fields[$key]) && ! in_array($fields[$key], $allowed, true)) {
                $fields[$key] = null;
            }
        }

        if (isset($fields['year']) && ($fields['year'] < 1950 || $fields['year'] > (int) date('Y') + 1)) {
            $fields['year'] = null;
        }

        return $fields;
    }

    /**
     * Same fields as schema(), in Gemini's OpenAPI-style responseSchema format.
     *
     * @return array<string, mixed>
     */
    private function geminiSchema(): array
    {
        $string = ['type' => 'STRING', 'nullable' => true];
        $enum = fn (array $values, bool $nullable = false) => ['type' => 'STRING', 'enum' => $values, 'nullable' => $nullable];

        $properties = [
            'name' => $string,
            'brand' => $string,
            'make' => $string,
            'series' => $string,
            'collection_number' => $string,
            'year' => ['type' => 'INTEGER', 'nullable' => true],
            'scale' => $string,
            'color' => $string,
            'rarity' => $enum(Diecast::RARITIES),
            'packaging' => $enum(Diecast::PACKAGINGS),
            'condition' => $enum(Diecast::CONDITIONS, true),
            'notes' => $string,
            'confidence' => $enum(['high', 'medium', 'low']),
        ];

        return [
            'type' => 'OBJECT',
            'properties' => $properties,
            'required' => array_keys($properties),
            'propertyOrdering' => array_keys($properties),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullable = fn (array $type) => ['anyOf' => [$type, ['type' => 'null']]];
        $string = $nullable(['type' => 'string']);

        $properties = [
            'name' => $string,
            'brand' => $string,
            'make' => $string,
            'series' => $string,
            'collection_number' => $string,
            'year' => $nullable(['type' => 'integer']),
            'scale' => $string,
            'color' => $string,
            'rarity' => ['type' => 'string', 'enum' => Diecast::RARITIES],
            'packaging' => ['type' => 'string', 'enum' => Diecast::PACKAGINGS],
            'condition' => $nullable(['type' => 'string', 'enum' => Diecast::CONDITIONS]),
            'notes' => $string,
            'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
        ];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => array_keys($properties),
            'additionalProperties' => false,
        ];
    }
}
