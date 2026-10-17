<?php

namespace App\Services;

use App\Models\Diecast;
use App\Models\User;
use App\Services\Dex\CatalogMatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates/updates a diecast together with its photos and tags. Shared by the form, bulk scan and imports.
 */
class DiecastWriter
{
    /** Changing any of these can change which dex entry a diecast is. */
    private const MATCH_FIELDS = ['name', 'brand', 'series', 'collection_number', 'year', 'rarity'];

    public function __construct(
        private PhotoStorage $photos,
        private CatalogMatcher $catalog,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated diecast attributes
     * @param  array<int, string>|null  $photoPaths  ordered photo paths (first = cover); null leaves photos untouched
     * @param  array<int, string>|null  $tagNames  null leaves tags untouched
     */
    public function save(User $user, ?Diecast $diecast, array $data, ?array $photoPaths = null, ?array $tagNames = null): Diecast
    {
        $filesToDelete = [];

        $diecast = DB::transaction(function () use ($user, $diecast, $data, $photoPaths, $tagNames, &$filesToDelete) {
            $diecast ??= $user->diecasts()->make();
            $diecast->fill($data)->save();

            if ($photoPaths !== null) {
                $filesToDelete = $this->syncPhotos($diecast, $photoPaths);
            }

            if ($tagNames !== null) {
                $this->syncTags($user, $diecast, $tagNames);
            }

            return $diecast;
        });

        foreach ($filesToDelete as $path) {
            $this->photos->delete($path);
        }

        if ($diecast->wasRecentlyCreated || $diecast->wasChanged(self::MATCH_FIELDS)) {
            $this->catalog->autoLink($diecast);
        }

        return $diecast->load(['photos', 'tags']);
    }

    public function delete(Diecast $diecast): void
    {
        $paths = [
            ...$diecast->photos->pluck('path'),
            ...$diecast->priceChecks()->pluck('screenshots')->flatten(),
            ...$diecast->sightings()->whereNotNull('photo_path')->pluck('photo_path'),
        ];
        $user = $diecast->user;

        $diecast->delete();

        foreach ($paths as $path) {
            $this->photos->delete($path);
        }

        $this->pruneTags($user);
    }

    /**
     * Keep listed existing photos, promote new uploads, drop the rest. Returns files to delete after commit.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function syncPhotos(Diecast $diecast, array $paths): array
    {
        $existing = $diecast->photos()->get()->keyBy('path');
        $keep = [];
        $position = 0;

        foreach (array_unique($paths) as $path) {
            if ($photo = $existing->get($path)) {
                $photo->update(['position' => $position++]);
                $keep[] = $path;
            } elseif ($this->photos->isPending($path)) {
                $diecast->photos()->create(['path' => $this->photos->promote($path), 'position' => $position++]);
            }
            // Anything else (someone else's file, a made-up path) is ignored.
        }

        // Not except(): it reads "file.jpg" keys as dot-notation paths.
        $removed = $existing->reject(fn ($photo, string $path) => in_array($path, $keep, true));
        $diecast->photos()->whereKey($removed->pluck('id'))->delete();
        $diecast->unsetRelation('photos');

        return $removed->pluck('path')->values()->all();
    }

    /**
     * @param  array<int, string>  $names
     */
    private function syncTags(User $user, Diecast $diecast, array $names): void
    {
        $names = collect($names)
            ->map(fn ($name) => Str::limit(trim((string) $name), 50, ''))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name));

        $ids = $names->map(function (string $name) use ($user) {
            $tag = $user->tags()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

            return ($tag ?? $user->tags()->create(['name' => $name]))->id;
        });

        $diecast->tags()->sync($ids->all());
        $this->pruneTags($user);
    }

    private function pruneTags(User $user): void
    {
        $user->tags()->whereDoesntHave('diecasts')->delete();
    }
}
