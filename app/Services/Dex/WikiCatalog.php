<?php

namespace App\Services\Dex;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Year lists from the fan wikis (Hot Wheels Wiki, Matchbox Wiki) through their MediaWiki API.
 * Content is CC BY-SA; requests are paced and identified, and only made when the user syncs.
 */
class WikiCatalog
{
    public const SOURCES = [
        'hotwheels_wiki' => [
            'brand' => 'Hot Wheels',
            'label' => 'Hot Wheels Mainline',
            'wiki' => 'hotwheels',
            'page' => 'List of %d Hot Wheels',
            'first_year' => 2015,
        ],
        'matchbox_wiki' => [
            'brand' => 'Matchbox',
            'label' => 'Matchbox Mainline',
            'wiki' => 'matchbox',
            'page' => 'List of %d Matchbox',
            'first_year' => 2015,
        ],
    ];

    /** Store-exclusive templates on the Hot Wheels Wiki. */
    private const EXCLUSIVES = [
        'KR' => 'Kroger', 'WM' => 'Walmart', 'TG' => 'Target', 'DG' => 'Dollar General', 'DT' => 'Dollar Tree',
    ];

    /** Pause between API calls, to stay a polite client. */
    private const PAUSE_MS = 400;

    private bool $called = false;

    public function __construct(private WikiTableParser $parser) {}

    public static function wikiUrl(string $source, string $page): string
    {
        return sprintf('https://%s.fandom.com/wiki/%s', self::SOURCES[$source]['wiki'], str_replace(' ', '_', $page));
    }

    /**
     * Every numbered release of a year, one row per variant.
     *
     * @return array{page: string, items: list<array<string, mixed>>}
     */
    public function fetch(string $source, int $year): array
    {
        $config = self::SOURCES[$source] ?? throw new CatalogSyncException('Sumber katalog tidak dikenal.');
        $page = sprintf($config['page'], $year);

        $response = $this->api($config['wiki'], ['action' => 'parse', 'page' => $page, 'prop' => 'wikitext']);

        if (isset($response['error'])) {
            throw new CatalogSyncException($response['error']['code'] === 'missingtitle'
                ? "Daftar {$year} belum ada di wiki."
                : 'Wiki menolak permintaan: '.($response['error']['info'] ?? $response['error']['code']));
        }

        $wikitext = $response['parse']['wikitext'] ?? '';
        $items = $source === 'matchbox_wiki' ? $this->matchboxItems($wikitext) : $this->hotWheelsItems($wikitext);

        return ['page' => $page, 'items' => $items];
    }

    /**
     * Thumbnail URLs for image files (50 per request, the API's limit).
     *
     * @param  list<string>  $files
     * @return array<string, string> file => thumbnail URL
     */
    public function imageUrls(string $source, array $files, int $width = 240): array
    {
        $urls = [];

        foreach (array_chunk(array_values(array_unique($files)), 50) as $chunk) {
            $response = $this->api(self::SOURCES[$source]['wiki'], [
                'action' => 'query',
                'titles' => implode('|', array_map(fn (string $file) => 'File:'.$file, $chunk)),
                'prop' => 'imageinfo',
                'iiprop' => 'url',
                'iiurlwidth' => $width,
            ]);

            // The API answers with normalized titles ("File:A_b.jpg" → "File:A b.jpg").
            $normalized = collect($response['query']['normalized'] ?? [])->pluck('from', 'to');

            foreach ($response['query']['pages'] ?? [] as $page) {
                $url = $page['imageinfo'][0]['thumburl'] ?? $page['imageinfo'][0]['url'] ?? null;

                if ($url) {
                    $title = $normalized[$page['title']] ?? $page['title'];
                    $urls[str_replace('_', ' ', substr($title, 5))] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Download a thumbnail. Returns the bytes and file extension, or null.
     *
     * @return array{bytes: string, extension: string}|null
     */
    public function download(string $url): ?array
    {
        $result = $this->downloadMany(['one' => $url])['one'];

        return is_array($result) ? $result : null;
    }

    /**
     * Download several thumbnails in parallel (one CDN round trip for the batch).
     * Per key: the image, 'missing' when the CDN says it isn't there (don't ask again),
     * or 'retry' when the connection failed (try again later).
     *
     * @param  array<array-key, string>  $urls
     * @return array<array-key, array{bytes: string, extension: string}|'missing'|'retry'>
     */
    public function downloadMany(array $urls): array
    {
        $responses = Http::pool(fn (Pool $pool) => collect($urls)
            ->map(fn (string $url, $key) => $pool->as((string) $key)
                ->withHeaders(['User-Agent' => config('app.name').'/1.0 (personal diecast collection tracker)'])
                ->timeout(10)
                ->get($url))
            ->all());

        $results = [];

        foreach ($urls as $key => $url) {
            $response = $responses[(string) $key] ?? null;

            if (! $response instanceof Response || $response->serverError()) {
                $results[$key] = 'retry';

                continue;
            }

            $type = strtolower(strtok((string) $response->header('Content-Type'), ';'));
            $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$type] ?? null;

            $results[$key] = $response->successful() && $extension && strlen($response->body()) <= 3 * 1024 * 1024
                ? ['bytes' => $response->body(), 'extension' => $extension]
                : 'missing';
        }

        return $results;
    }

    /**
     * Hot Wheels Wiki: Toy # | Col.# | Model Name | Series | Series # | Photo.
     *
     * @return list<array<string, mixed>>
     */
    private function hotWheelsItems(string $wikitext): array
    {
        $items = [];

        foreach ($this->parser->rows($wikitext) as $row) {
            $number = $row['col#']['text'] ?? '';

            // Unnumbered specials (store promos, "No #") aren't part of the numbered set.
            if (! preg_match('/^\d{1,3}$/', $number) || ! isset($row['modelname'])) {
                continue;
            }

            $items[] = $this->item(
                number: str_pad($number, 3, '0', STR_PAD_LEFT),
                sort: (int) $number,
                name: $row['modelname'],
                series: $row['series'] ?? null,
                seriesNumber: $row['series#']['text'] ?? null,
                toyCode: $row['toy#']['text'] ?? null,
                photo: $row['photo'] ?? null,
            );
        }

        return $this->numberVariants($items);
    }

    /**
     * Matchbox Wiki: Model # | Man # | Card # | Sub-series (or Segment) | Model Name | Photo.
     *
     * @return list<array<string, mixed>>
     */
    private function matchboxItems(string $wikitext): array
    {
        $items = [];

        foreach ($this->parser->rows($wikitext) as $row) {
            if (! preg_match('/^MB\s*0*(\d{1,3})$/i', $row['model#']['text'] ?? '', $match) || ! isset($row['modelname'])) {
                continue;
            }

            $items[] = $this->item(
                number: 'MB'.$match[1],
                sort: (int) $match[1],
                name: $row['modelname'],
                series: $row['subseries'] ?? $row['segment'] ?? $row['series'] ?? null,
                seriesNumber: null,
                // "JHM65 (USA) / JHP00 (ROW)": the first card code.
                toyCode: preg_match('/[A-Z0-9]{5}/', $row['card#']['text'] ?? '', $code) ? $code[0] : null,
                photo: $row['photo'] ?? $row['photoorcolor'] ?? null,
            );
        }

        return $this->numberVariants($items);
    }

    /**
     * @param  array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}  $name
     * @param  array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}|null  $series
     * @param  array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}|null  $photo
     * @return array<string, mixed>
     */
    private function item(string $number, int $sort, array $name, ?array $series, ?string $seriesNumber, ?string $toyCode, ?array $photo): array
    {
        $title = $name['text'];
        $variant = null;
        $label = null;

        // "Mazda RX-7 (2nd Color - Zamac)" → name + variant 2 + label.
        if (preg_match('/\s*\(((\d+)(?:st|nd|rd|th)\s+colou?r[^)]*)\)\s*$/i', $title, $match)) {
            $variant = (int) $match[2];
            $label = preg_replace('/\s+/', ' ', trim($match[1]));
            $title = trim(substr($title, 0, -strlen($match[0])));
        }

        $title = trim(preg_replace('/\s*\(new\)\s*$/i', '', $title));
        $notes = [...array_slice($name['lines'], 1), ...($series['lines'] ?? [])];
        $templates = collect([...$name['templates'], ...($series['templates'] ?? [])])->pluck('name');
        $text = implode(' ', $notes);

        $flags = array_filter([
            'new' => $templates->contains('NM') || preg_match('/\bnew\b/i', $text) === 1,
            'sth' => $templates->contains('STH') || stripos($text, 'super treasure hunt') !== false,
            'th' => $templates->contains('TH') || (stripos($text, 'treasure hunt') !== false && stripos($text, 'super treasure hunt') === false),
            'exclusive' => $templates->map(fn ($t) => self::EXCLUSIVES[$t] ?? null)->filter()->first()
                ?? (preg_match('/(\w[\w ]*?) exclusive/i', $text, $exclusive) ? $exclusive[1] : null),
        ]);

        $file = $photo['file'] ?? null;

        return [
            'number' => $number,
            'sort' => $sort,
            'variant' => $variant,
            'variant_label' => $label !== null ? mb_substr($label, 0, 255) : null,
            'name' => mb_substr($title, 0, 255),
            'series' => isset($series['text']) && $series['text'] !== '' ? mb_substr($series['text'], 0, 255) : null,
            'series_number' => $seriesNumber !== null && preg_match('/^\d+\s*\/\s*\d+$/', $seriesNumber) ? preg_replace('/\s+/', '', $seriesNumber) : null,
            'toy_code' => $toyCode !== null && preg_match('/^[A-Z0-9]{4,8}$/', $toyCode) ? $toyCode : null,
            'flags' => $flags ?: null,
            'image_file' => $file && ! str_starts_with(strtolower($file), 'image not available') ? mb_substr($file, 0, 255) : null,
        ];
    }

    /**
     * Variant numbers: from "(2nd Color)" when given, otherwise in page order — unique per number.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function numberVariants(array $items): array
    {
        $taken = [];

        foreach ($items as &$item) {
            $used = $taken[$item['number']] ?? [];
            $variant = $item['variant'];

            if ($variant === null || in_array($variant, $used, true)) {
                $variant = 1;

                while (in_array($variant, $used, true)) {
                    $variant++;
                }
            }

            $item['variant'] = $variant;
            $taken[$item['number']][] = $variant;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function api(string $wiki, array $params): array
    {
        if ($this->called) {
            Sleep::for(self::PAUSE_MS)->milliseconds();
        }

        $this->called = true;

        try {
            // A dropped connection (phone hotspot) is retried twice before the year counts as failed.
            $response = $this->http()
                ->timeout(30)
                ->retry(3, 2000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->get("https://{$wiki}.fandom.com/api.php", [
                    ...$params,
                    'format' => 'json',
                    'formatversion' => 2,
                ]);
        } catch (ConnectionException) {
            throw new CatalogSyncException('Tidak bisa menghubungi wiki. Cek koneksi internet lalu coba lagi.');
        }

        if ($response->failed() || ! is_array($response->json())) {
            throw new CatalogSyncException("Wiki sedang bermasalah (HTTP {$response->status()}). Coba lagi nanti.");
        }

        return $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => config('app.name').'/1.0 (personal diecast collection tracker)']);
    }
}
