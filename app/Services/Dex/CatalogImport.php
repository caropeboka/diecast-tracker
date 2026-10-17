<?php

namespace App\Services\Dex;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Services\AiVision;
use App\Services\CollectionSpreadsheet;
use App\Services\PhotoStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Fills a dex the user builds for brands without a wiki list (Mini GT, Tarmac, Roll Speed…):
 * by hand, from a spreadsheet, from screenshots of a checklist (read by the AI), or from the collection.
 */
class CatalogImport
{
    public const MAX_SCREENSHOTS = 6;

    /** Spreadsheet headers (lowercased, letters/digits only) → item field. */
    private const HEADERS = [
        'nomor' => 'number', 'no' => 'number', 'number' => 'number', '#' => 'number', 'kode' => 'number', 'code' => 'number',
        'nama' => 'name', 'name' => 'name', 'model' => 'name', 'namamodel' => 'name', 'modelname' => 'name',
        'seri' => 'series', 'series' => 'series', 'subseri' => 'series',
        'nomorseri' => 'series_number', 'seriesnumber' => 'series_number', 'series#' => 'series_number',
        'varian' => 'variant_label', 'variant' => 'variant_label', 'warna' => 'variant_label', 'color' => 'variant_label',
    ];

    private const SYSTEM_PROMPT = <<<'PROMPT'
        You read checklists of diecast releases for a collector: screenshots or photos of a brand's release list,
        an Instagram checklist post, a catalog page, a store's pre-order list, or a printed sheet.

        List EVERY release shown, in the order shown. Never invent releases that aren't visible. If the same release
        appears twice (overlapping screenshots), list it once.

        For each release:
        - number: the product / catalog number exactly as printed (e.g. "MGT00512", "T64-084", "No. 23", "#12"); null if none.
        - name: the model name as printed (e.g. "Nissan Skyline GT-R (R34) Nismo"), without the brand.
        - series: the line or sub-series if shown, else null.
        - series_number: a number within a series like "3/5" if shown, else null.
        - variant: what tells this release apart from others of the same model (color, livery, "LHD", "Chase",
          "Exclusive"), else null.
        Ignore prices, dates, hashtags and other text that isn't a release.
        PROMPT;

    public function __construct(
        private CatalogMatcher $matcher,
        private PhotoStorage $photos,
    ) {}

    /**
     * Add releases; exact duplicates (same number, name and variant) are skipped.
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array{added: int, skipped: int, items: list<CatalogItem>}
     */
    public function add(Catalog $catalog, iterable $rows, bool $relink = true): array
    {
        $added = 0;
        $skipped = 0;
        $created = [];

        DB::transaction(function () use ($catalog, $rows, &$added, &$skipped, &$created) {
            $items = $catalog->items()->get(['id', 'number', 'name', 'variant', 'variant_label', 'sort']);
            $known = $items->mapWithKeys(fn (CatalogItem $i) => [$this->key($i->number, $i->name, $i->variant_label) => true])->all();
            $variants = $items->groupBy('number')->map(fn ($group) => (int) $group->max('variant'))->all();
            // Variants share their number's place in the list.
            $sorts = $items->groupBy('number')->map(fn ($group) => (int) $group->first()->sort)->all();
            $nextSort = (int) $items->max('sort');

            foreach ($rows as $row) {
                $name = mb_substr(trim((string) ($row['name'] ?? '')), 0, 255);

                if ($name === '') {
                    $skipped++;

                    continue;
                }

                $number = $this->number($row['number'] ?? null);
                $label = $this->text($row['variant_label'] ?? $row['variant'] ?? null, 255);

                // No number on the list: number them in order.
                if ($number === null) {
                    $number = (string) ($nextSort + 1);
                }

                $key = $this->key($number, $name, $label);

                if (isset($known[$key])) {
                    $skipped++;

                    continue;
                }

                $sort = $sorts[$number] ?? (ctype_digit($number) ? (int) $number : $nextSort + 1);
                $sorts[$number] = $sort;
                $nextSort = max($nextSort, $sort);
                $variant = ($variants[$number] ?? 0) + 1;
                $variants[$number] = $variant;
                $known[$key] = true;

                $created[] = $catalog->items()->create([
                    'number' => $number,
                    'sort' => $sort,
                    'variant' => $variant,
                    'variant_label' => $label,
                    'name' => $name,
                    'series' => $this->text($row['series'] ?? null, 255),
                    'series_number' => $this->text($row['series_number'] ?? null, 32),
                    'image_path' => $row['image_path'] ?? null,
                ]);
                $added++;
            }
        });

        if ($relink && $added) {
            $this->matcher->autoLinkAll($catalog->user);
        }

        return ['added' => $added, 'skipped' => $skipped, 'items' => $created];
    }

    /**
     * One release typed in by hand, with an optional photo.
     *
     * @param  array<string, mixed>  $data
     */
    public function addOne(Catalog $catalog, array $data, ?UploadedFile $photo): ?CatalogItem
    {
        $path = $photo ? $this->photos->store($photo, $this->dir($catalog), 800) : null;
        $result = $this->add($catalog, [[...$data, 'image_path' => $path]]);

        if (! $result['added'] && $path) {
            $this->photos->delete($path);
        }

        return $result['items'][0] ?? null;
    }

    /**
     * Rows from a CSV/XLSX with a header row (Nomor, Nama, Seri, Nomor seri, Varian).
     *
     * @return array{added: int, skipped: int, items: list<CatalogItem>}|array{error: string}
     */
    public function fromSpreadsheet(Catalog $catalog, string $path, string $extension): array
    {
        $reader = $extension === 'xlsx'
            ? new XlsxReader
            : new CsvReader(new CsvOptions(FIELD_DELIMITER: CollectionSpreadsheet::detectDelimiter($path)));
        $reader->open($path);

        $rows = [];
        $headers = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => is_scalar($v) ? trim((string) $v) : '', $row->toArray());

                if ($headers === null) {
                    $headers = array_map(fn (string $h) => self::HEADERS[preg_replace('/[^a-z0-9#]/', '', strtolower($h))] ?? null, $values);

                    continue;
                }

                $rows[] = collect($headers)->filter()->mapWithKeys(fn (string $field, int $i) => [$field => $values[$i] ?? null])->all();
            }

            break; // first sheet only
        }

        $reader->close();

        if (! in_array('name', $headers ?? [], true)) {
            return ['error' => 'Kolom "Nama" tidak ditemukan di baris pertama.'];
        }

        return $this->add($catalog, array_filter($rows, fn (array $row) => array_filter($row) !== []));
    }

    /**
     * Read a checklist from screenshots with the AI. The screenshots aren't kept.
     *
     * @param  list<UploadedFile>  $screenshots
     * @return array{added: int, skipped: int, items: list<CatalogItem>}
     */
    public function fromScreenshots(Catalog $catalog, array $screenshots, AiVision $ai): array
    {
        $paths = array_map(
            fn (UploadedFile $file) => $this->photos->store($file, PhotoStorage::TMP_DIR, PhotoStorage::SCREENSHOT_MAX_EDGE),
            array_slice($screenshots, 0, self::MAX_SCREENSHOTS),
        );

        try {
            $read = $ai->extract($paths, self::SYSTEM_PROMPT, $this->instruction($catalog, count($paths)), $this->schema());
        } finally {
            foreach ($paths as $path) {
                $this->photos->delete($path);
            }
        }

        return $this->add($catalog, is_array($read['items'] ?? null) ? $read['items'] : []);
    }

    /**
     * The collection's diecasts of this brand become the dex's entries, linked to them (with their photos).
     *
     * @return array{added: int, skipped: int, items: list<CatalogItem>}
     */
    public function fromCollection(Catalog $catalog): array
    {
        $diecasts = $catalog->user->diecasts()
            ->whereRaw('lower(brand) = ?', [mb_strtolower($catalog->brand)])
            ->where(fn ($query) => $query->whereNull('catalog_match')->orWhere('catalog_match', '!=', 'confirmed'))
            ->orderBy('id')
            ->get();

        $rows = $diecasts->map(function (Diecast $diecast) use ($catalog) {
            $numbers = CatalogMatcher::numbers($diecast->collection_number);
            $cover = $diecast->photos->first()?->path;
            $copy = null;

            if ($cover && Storage::disk('public')->exists($cover)) {
                $copy = $this->dir($catalog).'/'.basename($cover);
                Storage::disk('public')->copy($cover, $copy);
            }

            return [
                'number' => $numbers['code'] ?? ($numbers['main'] !== null ? (string) $numbers['main'] : null),
                'name' => $diecast->name,
                'series' => $diecast->series,
                'series_number' => $numbers['series'],
                'variant_label' => $diecast->color,
                'image_path' => $copy,
                'diecast' => $diecast,
            ];
        });

        $result = $this->add($catalog, $rows->all(), relink: false);

        // Link each diecast to the entry made from it (or the existing one it duplicated).
        $items = $catalog->items()->get();

        foreach ($rows as $row) {
            $item = $items->first(fn (CatalogItem $i) => mb_strtolower($i->name) === mb_strtolower(trim($row['name']))
                && ($row['number'] === null || $i->number === $this->number($row['number']))
                && mb_strtolower((string) $i->variant_label) === mb_strtolower((string) $row['variant_label']));

            if ($item) {
                $row['diecast']->forceFill(['catalog_item_id' => $item->id, 'catalog_match' => 'confirmed'])->saveQuietly();
            } elseif ($row['image_path']) {
                Storage::disk('public')->delete($row['image_path']);
            }
        }

        return $result;
    }

    public function deleteItem(CatalogItem $item): void
    {
        Diecast::where('catalog_item_id', $item->id)->update(['catalog_item_id' => null, 'catalog_match' => null]);
        $this->photos->delete($item->image_path);
        $item->delete();
    }

    private function dir(Catalog $catalog): string
    {
        return 'catalog/'.$catalog->id;
    }

    /** "#12" / "No. 12" → "12"; codes kept as printed, uppercased. */
    private function number(mixed $value): ?string
    {
        $number = preg_replace('/^(?:#|no\.?)\s*/i', '', trim((string) $value));

        return $number === '' ? null : mb_substr(strtoupper($number), 0, 32);
    }

    private function text(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    private function key(string $number, string $name, ?string $variant): string
    {
        return mb_strtolower($number.'|'.trim($name).'|'.trim((string) $variant));
    }

    private function instruction(Catalog $catalog, int $count): string
    {
        $what = trim($catalog->brand.' — '.$catalog->name.($catalog->year ? " ({$catalog->year})" : ''));

        return "These {$count} image(s) show a release checklist for: {$what}. List every release.";
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        $nullable = fn () => ['anyOf' => [['type' => 'string'], ['type' => 'null']]];
        $item = [
            'number' => $nullable(),
            'name' => ['type' => 'string'],
            'series' => $nullable(),
            'series_number' => $nullable(),
            'variant' => $nullable(),
        ];

        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => $item,
                        'required' => array_keys($item),
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['items'],
            'additionalProperties' => false,
        ];
    }
}
