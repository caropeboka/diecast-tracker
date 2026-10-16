<?php

namespace App\Services;

use App\Http\Requests\DiecastRequest;
use App\Models\Diecast;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use ZipArchive;

/**
 * Full backup: one ZIP with diecasts.json, every photo, and an Excel copy for humans.
 */
class CollectionBackup
{
    private const FORMAT = 'diecast-tracker-backup';

    private const MAX_PHOTO_BYTES = 15 * 1024 * 1024;

    public function __construct(
        private PhotoStorage $photos,
        private DiecastWriter $writer,
        private CollectionSpreadsheet $spreadsheet,
        private SightingLog $sightings,
    ) {}

    /**
     * Copy a stored photo into the ZIP under photos/; returns its name there, or null if the file is gone.
     */
    private function addPhoto(ZipArchive $zip, string $path): ?string
    {
        if (! $this->photos->exists($path)) {
            return null;
        }

        $name = 'photos/'.basename($path);
        $zip->addFromString($name, $this->photos->contents($path));

        return $name;
    }

    public function create(User $user, string $zipPath): void
    {
        $this->ensureZip();

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $records = [];

        $user->diecasts()->with(['tags', 'sightings'])->orderBy('id')->chunk(200, function ($diecasts) use ($zip, &$records) {
            foreach ($diecasts as $diecast) {
                $records[] = [
                    ...collect($diecast->only($diecast->getFillable()))
                        ->map(fn ($value) => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value)
                        ->all(),
                    'tags' => $diecast->tags->pluck('name')->all(),
                    'photos' => $diecast->photos->map(fn ($photo) => $this->addPhoto($zip, $photo->path))->filter()->values()->all(),
                    'sightings' => $diecast->sightings->map(fn ($sighting) => [
                        'place' => $sighting->place,
                        'latitude' => $sighting->latitude,
                        'longitude' => $sighting->longitude,
                        'seen_on' => $sighting->seen_on->format('Y-m-d'),
                        'price' => $sighting->price,
                        'notes' => $sighting->notes,
                        'photo' => $sighting->photo_path ? $this->addPhoto($zip, $sighting->photo_path) : null,
                    ])->all(),
                ];
            }
        });

        $zip->addFromString('diecasts.json', json_encode([
            'format' => self::FORMAT,
            'version' => 1,
            'exported_at' => now()->toIso8601String(),
            'diecasts' => $records,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $xlsx = tempnam(sys_get_temp_dir(), 'diecast').'.xlsx';
        $this->spreadsheet->export($user, $xlsx);
        $zip->addFile($xlsx, 'koleksi.xlsx');

        $zip->close();
        @unlink($xlsx);
    }

    /**
     * Restore a backup ZIP. "replace" deletes the current collection first; "merge" adds to it.
     *
     * @return array{imported: int, skipped: array<int, string>}
     */
    public function restore(User $user, string $zipPath, bool $replace): array
    {
        $this->ensureZip();

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('File ZIP tidak bisa dibuka.');
        }

        $manifest = json_decode((string) $zip->getFromName('diecasts.json'), true);

        if (($manifest['format'] ?? null) !== self::FORMAT || ! is_array($manifest['diecasts'] ?? null)) {
            $zip->close();
            throw new RuntimeException('Ini bukan file backup Diecast Tracker.');
        }

        if ($replace) {
            $user->diecasts()->get()->each(fn (Diecast $diecast) => $this->writer->delete($diecast));
        }

        $rules = collect((new DiecastRequest)->rules())->except(['photos', 'photos.*', 'scan_item_id'])->all();
        $imported = 0;
        $skipped = [];

        foreach ($manifest['diecasts'] as $i => $record) {
            $validator = Validator::make(is_array($record) ? $record : [], $rules, [], (new DiecastRequest)->attributes());

            if ($validator->fails()) {
                $skipped[] = 'Item '.($i + 1).' ('.($record['name'] ?? '?').'): '.$validator->errors()->first();

                continue;
            }

            $photos = collect($record['photos'] ?? [])
                ->map(fn ($name) => $this->extractPhoto($zip, (string) $name))
                ->filter()
                ->values()
                ->all();

            $data = $validator->validated();
            $diecast = $this->writer->save($user, null, collect($data)->except(['tags', 'sighting'])->all(), $photos, $data['tags'] ?? []);

            foreach ($record['sightings'] ?? [] as $sighting) {
                if (is_array($sighting) && Validator::make($sighting, [...SightingLog::rules(), 'seen_on' => ['required', 'date']])->passes()) {
                    $photo = isset($sighting['photo']) ? $this->extractPhoto($zip, (string) $sighting['photo']) : null;
                    $this->sightings->record($diecast, $sighting, $photo);
                }
            }

            $imported++;
        }

        $zip->close();

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Copy one photo out of the ZIP into pending storage. Only real images under photos/ are accepted.
     */
    private function extractPhoto(ZipArchive $zip, string $name): ?string
    {
        if (! preg_match('#^photos/[A-Za-z0-9._-]+\.jpe?g$#', $name)) {
            return null;
        }

        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::MAX_PHOTO_BYTES) {
            return null;
        }

        $bytes = (string) $zip->getFromName($name);

        if (@getimagesizefromstring($bytes) === false) {
            return null;
        }

        return $this->photos->storeBytes($bytes);
    }

    private function ensureZip(): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi PHP "zip" belum aktif. Aktifkan lewat Laragon → PHP → Extensions → zip.');
        }
    }
}
