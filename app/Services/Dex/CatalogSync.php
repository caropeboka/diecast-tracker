<?php

namespace App\Services\Dex;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Brings a wiki year list into the user's dex, keeping item ids (and so the links to the
 * collection) stable across re-syncs, then downloads the thumbnails a few at a time.
 */
class CatalogSync
{
    public function __construct(
        private WikiCatalog $wiki,
        private CatalogMatcher $matcher,
    ) {}

    /**
     * @return array{catalog: Catalog, added: int, removed: int, linked: int}
     */
    public function sync(User $user, string $source, int $year): array
    {
        $data = $this->wiki->fetch($source, $year);

        if (! $data['items']) {
            throw new CatalogSyncException("Daftar {$year} di wiki belum berisi data.");
        }

        $config = WikiCatalog::SOURCES[$source];
        $staleImages = [];

        [$catalog, $added, $removed] = DB::transaction(function () use ($user, $source, $year, $config, $data, &$staleImages) {
            $catalog = $user->catalogs()->firstOrNew(['source' => $source, 'year' => $year]);
            $catalog->fill([
                'brand' => $config['brand'],
                'name' => "{$config['label']} {$year}",
                'source_page' => $data['page'],
            ])->save();

            $existing = $catalog->items()->get()->keyBy(fn (CatalogItem $item) => $item->number.'|'.$item->variant);
            $seen = [];
            $added = 0;

            foreach ($data['items'] as $row) {
                $key = $row['number'].'|'.$row['variant'];
                $seen[$key] = true;
                $item = $existing->get($key) ?? $catalog->items()->make();
                $added += $item->exists ? 0 : 1;

                // A new photo on the wiki: fetch it again.
                if ($item->exists && $item->image_file !== $row['image_file']) {
                    $staleImages[] = $item->image_path;
                    $row += ['image_url' => null, 'image_path' => null];
                }

                $item->fill($row)->save();
            }

            $gone = $existing->reject(fn (CatalogItem $item, string $key) => isset($seen[$key]));
            $staleImages = [...$staleImages, ...$gone->pluck('image_path')];
            $this->forget($gone->modelKeys());

            return [$catalog, $added, $gone->count()];
        });

        Storage::disk('public')->delete(array_filter($staleImages));

        $this->resolveImageUrls($catalog);
        $catalog->update(['synced_at' => now()]);

        return [
            'catalog' => $catalog,
            'added' => $added,
            'removed' => $removed,
            'linked' => $this->matcher->autoLinkAll($user),
        ];
    }

    /**
     * Download up to $limit missing thumbnails (the open dex first), stopping after about $seconds so
     * a single-threaded dev server (`artisan serve` on Windows) stays responsive between batches.
     * Returns how many are still missing.
     */
    public function downloadImages(User $user, int $limit = 12, ?int $preferCatalog = null): int
    {
        $missing = fn () => CatalogItem::query()
            ->whereIn('catalog_id', $user->catalogs()->select('id'))
            ->whereNotNull('image_url')
            ->whereNull('image_path');

        $items = $missing()
            ->when($preferCatalog, fn ($query) => $query->orderByRaw('case when catalog_id = ? then 0 else 1 end', [$preferCatalog]))
            ->orderBy('catalog_id')
            ->orderBy('sort')
            ->orderBy('variant')
            ->limit($limit)
            ->get();

        // All at once: one round trip for the batch keeps each request short.
        $images = $this->wiki->downloadMany($items->pluck('image_url', 'id')->all());

        foreach ($items as $item) {
            $image = $images[$item->id] ?? 'retry';

            if ($image === 'retry') {
                continue; // connection trouble: try again in a later batch
            }

            if ($image === 'missing') {
                // Not on the CDN: don't ask again (a re-sync looks it up anew).
                $item->update(['image_url' => null]);

                continue;
            }

            $path = sprintf('catalog/%d/%d-%s.%s', $item->catalog_id, $item->id, substr(md5((string) $item->image_file), 0, 8), $image['extension']);
            Storage::disk('public')->put($path, $image['bytes']);
            $item->update(['image_path' => $path]);
        }

        return $missing()->count();
    }

    public function delete(Catalog $catalog): void
    {
        DB::transaction(function () use ($catalog) {
            $this->forget($catalog->items()->pluck('id')->all());
            $catalog->delete();
        });

        Storage::disk('public')->deleteDirectory('catalog/'.$catalog->id);
    }

    /**
     * Remove items; diecasts linked to them become unmatched (so they can be matched again).
     *
     * @param  list<int>  $ids
     */
    private function forget(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            Diecast::whereIn('catalog_item_id', $chunk)->update(['catalog_item_id' => null, 'catalog_match' => null]);
            CatalogItem::whereKey($chunk)->delete();
        }
    }

    private function resolveImageUrls(Catalog $catalog): void
    {
        $files = $catalog->items()
            ->whereNotNull('image_file')
            ->whereNull('image_url')
            ->whereNull('image_path')
            ->pluck('image_file')
            ->unique()
            ->values();

        if ($files->isEmpty()) {
            return;
        }

        $urls = $this->wiki->imageUrls($catalog->source, $files->all());

        foreach ($urls as $file => $url) {
            CatalogItem::where('catalog_id', $catalog->id)->where('image_file', $file)->update(['image_url' => $url]);
        }
    }
}
