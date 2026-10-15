<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Exceptions\ScanRetryLaterException;
use App\Models\AppSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends images + instructions to the configured vision model (Gemini or Claude) and returns structured JSON.
 * Provider, key and model come from Settings → AI Scan (falling back to .env).
 */
class AiVision
{
    /**
     * When the chosen Gemini model is overloaded or out of free quota, up to this many other models are tried
     * (free-tier quota is counted per model, so this also stretches the daily allowance).
     */
    private const GEMINI_MAX_FALLBACKS = 4;

    /**
     * Seconds per Gemini call (an overloaded model sometimes hangs instead of answering 503), and the total
     * before giving up. Controllers allow 120s.
     */
    private const GEMINI_TIMEOUT = 40;

    private const GEMINI_FALLBACK_TIMEOUT = 25;

    private const GEMINI_TIME_BUDGET = 80;

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
     * Check a key by listing the models it can use.
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
                && ! preg_match('/(embedding|tts|image|banana|live|audio|robotics|computer-use|transcribe|customtools)/', $name))
            ->values()
            ->all();
    }

    /**
     * Ask the model about some images and get JSON matching $schema back.
     *
     * @param  array<int, string>  $imagePaths  JPEGs on the public disk
     * @param  array<string, mixed>  $schema  JSON Schema (nullable fields as anyOf [..., {type: null}])
     * @return array<string, mixed>
     */
    public function extract(array $imagePaths, string $system, string $instruction, array $schema): array
    {
        return $this->provider() === 'anthropic'
            ? $this->withClaude($imagePaths, $system, $instruction, $schema)
            : $this->withGemini($imagePaths, $system, $instruction, $schema);
    }

    /**
     * Google Gemini via the REST generateContent endpoint (has a free tier).
     *
     * @param  array<int, string>  $imagePaths
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withGemini(array $imagePaths, string $system, string $instruction, array $schema): array
    {
        $model = $this->model('gemini');

        $parts = array_map(fn (string $path) => [
            'inline_data' => ['mime_type' => 'image/jpeg', 'data' => $this->photos->base64($path)],
        ], $imagePaths);
        $parts[] = ['text' => $instruction];

        $payload = [
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => $parts]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->toGeminiSchema($schema),
            ],
        ];

        // Busy, timed out, out of (per-model) free quota, or retired for this key: move on to the next model,
        // within an overall time budget so the page doesn't hang.
        $started = microtime(true);
        $response = null;
        $outcomes = []; // model => HTTP status, or "timeout"

        foreach ($this->geminiCandidates($model) as $index => $candidate) {
            if ($index > 0 && microtime(true) - $started > self::GEMINI_TIME_BUDGET) {
                break;
            }

            $response = $this->callGemini($candidate, $payload, retry: $index === 0);
            $outcomes[$candidate] = $response?->status() ?? 'timeout';

            if ($response?->successful() || ($response && ! in_array($response->status(), [404, 429, 500, 503], true))) {
                break;
            }
        }

        if (! $response?->successful()) {
            Log::warning('Gemini request failed', ['outcomes' => $outcomes, 'error' => $response?->json('error')]);

            // Report the most useful reason across all models tried, not just the last fallback's.
            $seen = fn (array $statuses) => array_intersect($outcomes, $statuses) !== [];

            throw match (true) {
                $response?->json('error.details.0.reason') === 'API_KEY_INVALID',
                $seen([403]) => new RuntimeException('API key Gemini tidak valid. Cek Settings → AI Scan.'),
                $seen([429]) => new ScanRetryLaterException(
                    'Kuota gratis Gemini habis untuk saat ini. Tunggu sebentar (atau besok) lalu coba lagi.',
                ),
                $seen([500, 503]) => new ScanRetryLaterException(
                    'Server Gemini sedang sibuk (banyak pengguna). Coba lagi sebentar lagi.',
                    retryAfter: 30,
                ),
                $seen(['timeout']) => new ScanRetryLaterException(
                    'Gemini tidak merespons (server penuh atau koneksi lambat). Coba lagi sebentar lagi.',
                    retryAfter: 30,
                ),
                ($outcomes[$model] ?? null) === 404 => new RuntimeException("Model Gemini \"{$model}\" tidak ditemukan. Pilih model lain di Settings → AI Scan."),
                default => new RuntimeException('Gemini sedang bermasalah. Isi manual dulu atau coba lagi.'),
            };
        }

        if ($response->json('promptFeedback.blockReason')) {
            throw new RuntimeException('AI tidak bisa memproses gambar ini.');
        }

        $text = collect($response->json('candidates.0.content.parts', []))
            ->pluck('text')
            ->filter()
            ->implode('');

        return $this->decode($text);
    }

    /**
     * One generateContent call. Null means it timed out / couldn't connect.
     * The chosen model gets one quick retry on a brief overload (500/503); fallbacks don't, to keep it fast.
     *
     * @param  array<string, mixed>  $payload
     */
    private function callGemini(string $model, array $payload, bool $retry): ?Response
    {
        try {
            return Http::timeout($retry ? self::GEMINI_TIMEOUT : self::GEMINI_FALLBACK_TIMEOUT)
                ->withHeaders(['x-goog-api-key' => $this->key('gemini')])
                ->retry($retry ? 2 : 1, 1500, fn ($exception) => $exception instanceof RequestException
                    && in_array($exception->response->status(), [500, 503], true), throw: false)
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * The chosen model first, then the newest "flash" models this key can use.
     * Picked from the live model list (cached), because Google retires and adds models often.
     *
     * @return array<int, string>
     */
    private function geminiCandidates(string $model): array
    {
        $key = (string) $this->key('gemini');

        $fallbacks = Cache::remember('gemini-fallbacks:'.md5($key), now()->addHours(12), function () use ($key) {
            try {
                $available = $this->availableModels('gemini', $key);
            } catch (RuntimeException) {
                return [];
            }

            return collect($available)
                ->filter(fn (string $name) => preg_match('/^gemini-\d+(\.\d+)?-flash(-lite)?$/', $name))
                ->sortBy([
                    fn (string $a, string $b) => str_ends_with($b, '-lite') <=> str_ends_with($a, '-lite'),
                    fn (string $a, string $b) => version_compare($this->geminiVersion($b), $this->geminiVersion($a)),
                ])
                ->values()
                ->all();
        });

        // Lite models first: in a demand spike all regular flash models tend to be busy together,
        // while lite runs on separate capacity and still reads printed text well.
        $others = array_values(array_diff($fallbacks, [$model]));
        $lite = array_values(array_filter($others, fn (string $name) => str_ends_with($name, '-lite')));
        $regular = array_values(array_diff($others, $lite));

        return array_slice(array_values(array_unique([
            $model,
            $lite[0] ?? 'gemini-flash-lite-latest',
            'gemini-flash-lite-latest',
            ...$regular,
            ...array_slice($lite, 1),
        ])), 0, self::GEMINI_MAX_FALLBACKS + 1);
    }

    private function geminiVersion(string $name): string
    {
        return preg_match('/^gemini-(\d+(?:\.\d+)?)/', $name, $match) ? $match[1] : '0';
    }

    /**
     * @param  array<int, string>  $imagePaths
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withClaude(array $imagePaths, string $system, string $instruction, array $schema): array
    {
        $client = new Client(apiKey: $this->key('anthropic'));

        $content = array_map(fn (string $path) => [
            'type' => 'image',
            'source' => ['type' => 'base64', 'mediaType' => 'image/jpeg', 'data' => $this->photos->base64($path)],
        ], $imagePaths);
        $content[] = ['type' => 'text', 'text' => $instruction];

        try {
            $message = $client->beta->messages->create(
                model: $this->model('anthropic'),
                maxTokens: 16000,
                betas: ['server-side-fallback-2026-07-01'],
                fallbacks: 'default',
                outputConfig: [
                    'effort' => 'medium',
                    'format' => ['type' => 'json_schema', 'schema' => $schema],
                ],
                system: $system,
                messages: [['role' => 'user', 'content' => $content]],
                requestOptions: ['timeout' => 90],
            );
        } catch (AuthenticationException) {
            throw new RuntimeException('API key Claude tidak valid. Cek Settings → AI Scan.');
        } catch (RateLimitException) {
            throw new ScanRetryLaterException('Terlalu banyak permintaan sekaligus. Tunggu sebentar lalu coba lagi.');
        } catch (APIStatusException $e) {
            Log::warning('Claude request failed', ['status' => $e->status, 'message' => $e->getMessage()]);
            throw new RuntimeException('Layanan AI sedang bermasalah. Isi manual dulu atau coba lagi.');
        } catch (APIConnectionException) {
            throw new RuntimeException('Tidak bisa terhubung ke layanan AI. Cek koneksi internet.');
        }

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('AI tidak bisa memproses gambar ini.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $this->decode($block->text);
            }
        }

        return $this->decode('');
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
     * JSON Schema → Gemini's OpenAPI-style responseSchema (uppercase types, "nullable" instead of anyOf-null).
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function toGeminiSchema(array $schema): array
    {
        if (isset($schema['anyOf'])) {
            $types = array_values(array_filter($schema['anyOf'], fn ($s) => ($s['type'] ?? null) !== 'null'));

            return [...$this->toGeminiSchema($types[0]), 'nullable' => true];
        }

        $converted = ['type' => strtoupper($schema['type'])];

        foreach (['enum', 'description', 'required'] as $key) {
            if (isset($schema[$key])) {
                $converted[$key] = $schema[$key];
            }
        }

        if (isset($schema['properties'])) {
            $converted['properties'] = array_map(fn (array $property) => $this->toGeminiSchema($property), $schema['properties']);
            $converted['propertyOrdering'] = array_keys($schema['properties']);
        }

        if (isset($schema['items'])) {
            $converted['items'] = $this->toGeminiSchema($schema['items']);
        }

        return $converted;
    }
}
