<?php

namespace App\Services;

use App\Http\Requests\DiecastRequest;
use App\Models\Diecast;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Validator;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Collection <-> Excel. Column headers are in Indonesian; import also accepts the English field names.
 */
class CollectionSpreadsheet
{
    /** header => attribute */
    public const COLUMNS = [
        'Nama' => 'name',
        'Merek' => 'brand',
        'Pabrikan' => 'make',
        'Seri' => 'series',
        'Nomor Koleksi' => 'collection_number',
        'Tahun' => 'year',
        'Skala' => 'scale',
        'Warna' => 'color',
        'Kelangkaan' => 'rarity',
        'Kemasan' => 'packaging',
        'Kondisi' => 'condition',
        'Status' => 'status',
        'Jumlah' => 'quantity',
        'Harga Beli' => 'purchase_price',
        'Harga Pasaran' => 'market_value',
        'Target Harga' => 'target_price',
        'Tanggal Beli' => 'purchase_date',
        'Beli Di' => 'purchase_place',
        'Lokasi Simpan' => 'storage_location',
        'Tag' => 'tags',
        'Catatan' => 'notes',
    ];

    /** Human labels for the option columns (export) — import accepts labels or codes. */
    public const LABELS = [
        'rarity' => [
            'regular' => 'Regular', 'treasure_hunt' => 'Treasure Hunt', 'super_treasure_hunt' => 'Super Treasure Hunt',
            'chase' => 'Chase', 'limited' => 'Limited', 'exclusive' => 'Exclusive',
        ],
        'packaging' => ['carded' => 'Carded', 'boxed' => 'Box', 'loose' => 'Loose'],
        'condition' => ['mint' => 'Mint', 'near_mint' => 'Near mint', 'good' => 'Good', 'fair' => 'Fair', 'damaged' => 'Rusak'],
        'status' => ['owned' => 'Dimiliki', 'wishlist' => 'Wishlist', 'spotted' => 'Ditemui', 'sold' => 'Terjual'],
    ];

    public function __construct(private DiecastWriter $writer) {}

    /**
     * Write the user's collection (or just the header + an example row) to an .xlsx file.
     */
    public function export(User $user, string $path, bool $template = false): void
    {
        $options = new XlsxOptions;
        $options->setColumnWidth(32, 1);
        $options->setColumnWidthForRange(16, 2, count(self::COLUMNS));

        $writer = new XlsxWriter($options);
        $writer->openToFile($path);
        $writer->addRow(Row::fromValuesWithStyle(array_keys(self::COLUMNS), new Style(fontBold: true, backgroundColor: 'E5E7EB')));

        if ($template) {
            $writer->addRow(Row::fromValues([
                "'71 Datsun 510", 'Hot Wheels', 'Datsun', 'HW J-Imports', '123/250', 2026, '1:64', 'Blue',
                'Treasure Hunt', 'Carded', 'Mint', 'Dimiliki', 1, 35000, 150000, null, '2026-10-01',
                'Toys Kingdom', 'Rak A', 'JDM, Favorit', 'Contoh baris — hapus sebelum import',
            ]));
        } else {
            $user->diecasts()->setEagerLoads([])->with('tags')->orderBy('id')
                ->chunk(200, function ($diecasts) use ($writer) {
                    foreach ($diecasts as $diecast) {
                        $writer->addRow(Row::fromValues($this->toRow($diecast)));
                    }
                });
        }

        $writer->close();
    }

    /**
     * Import rows from .xlsx or .csv. Returns how many were added and why others were skipped.
     *
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function import(User $user, string $path, string $extension): array
    {
        $reader = $extension === 'csv'
            ? new CsvReader(new CsvOptions(FIELD_DELIMITER: $this->detectDelimiter($path)))
            : new XlsxReader;

        $reader->open($path);

        $imported = 0;
        $skipped = [];
        $headers = null;
        $rules = collect((new DiecastRequest)->rules())->except(['photos', 'photos.*', 'scan_item_id'])->all();

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $index => $row) {
                $values = $row->toArray();

                if ($headers === null) {
                    $headers = $this->mapHeaders($values);

                    if (! in_array('name', $headers, true)) {
                        $reader->close();

                        return ['imported' => 0, 'skipped' => ['Kolom "Nama" tidak ditemukan di baris pertama.']];
                    }

                    continue;
                }

                $data = $this->fromRow($headers, $values);

                if (collect($data)->filter(fn ($value) => filled($value))->isEmpty()) {
                    continue; // blank line
                }

                $validator = Validator::make($data, $rules, [], (new DiecastRequest)->attributes());

                if ($validator->fails()) {
                    $skipped[] = "Baris {$index}: ".$validator->errors()->first();

                    continue;
                }

                $valid = $validator->validated();
                $this->writer->save($user, null, collect($valid)->except('tags')->all(), null, $valid['tags'] ?? []);
                $imported++;
            }

            break; // first sheet only
        }

        $reader->close();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return array<int, mixed>
     */
    private function toRow(Diecast $diecast): array
    {
        return array_map(function (string $attribute) use ($diecast) {
            $value = match ($attribute) {
                'tags' => $diecast->tags->pluck('name')->implode(', '),
                'purchase_date' => $diecast->purchase_date?->format('Y-m-d'),
                'purchase_price', 'market_value', 'target_price' => $diecast->{$attribute} !== null ? (float) $diecast->{$attribute} : null,
                default => $diecast->{$attribute},
            };

            return self::LABELS[$attribute][$value] ?? $value;
        }, array_values(self::COLUMNS));
    }

    /**
     * @param  array<int, string|null>  $headers
     * @param  array<int, mixed>  $values
     * @return array<string, mixed>
     */
    private function fromRow(array $headers, array $values): array
    {
        $data = ['rarity' => 'regular', 'packaging' => 'carded', 'status' => 'owned', 'quantity' => 1];

        foreach ($headers as $i => $attribute) {
            if ($attribute === null) {
                continue;
            }

            $value = $values[$i] ?? null;

            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }

            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                continue;
            }

            $data[$attribute] = match ($attribute) {
                'tags' => array_map('trim', explode(',', (string) $value)),
                'purchase_price', 'market_value', 'target_price' => $this->parseMoney($value),
                'rarity', 'packaging', 'condition', 'status' => $this->parseOption($attribute, (string) $value),
                'year', 'quantity' => is_numeric($value) ? (int) $value : $value,
                default => (string) $value,
            };
        }

        return $data;
    }

    /**
     * @param  array<int, mixed>  $row
     * @return array<int, string|null>
     */
    private function mapHeaders(array $row): array
    {
        $lookup = [];

        foreach (self::COLUMNS as $header => $attribute) {
            $lookup[$this->key($header)] = $attribute;
            $lookup[$this->key($attribute)] = $attribute;
        }

        return array_map(fn ($cell) => $lookup[$this->key((string) $cell)] ?? null, $row);
    }

    private function parseOption(string $attribute, string $value): string
    {
        foreach (self::LABELS[$attribute] as $code => $label) {
            if (in_array($this->key($value), [$this->key($code), $this->key($label)], true)) {
                return $code;
            }
        }

        return $value; // fails validation with a clear message
    }

    /**
     * "Rp 35.000", "35000", "35,5" → number. Dots/commas in groups of three are thousand separators.
     */
    private function parseMoney(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $clean = preg_replace('/[^\d.,]/', '', (string) $value);

        if (preg_match('/^\d{1,3}([.,]\d{3})+$/', $clean)) {
            return (float) str_replace(['.', ','], '', $clean);
        }

        return is_numeric($normalized = str_replace(',', '.', $clean)) ? (float) $normalized : $value;
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $firstLine = (string) fgets($handle);
        fclose($handle);

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    private function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value)) ?? '';
    }
}
