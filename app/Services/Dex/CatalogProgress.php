<?php

namespace App\Services\Dex;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pokédex-style progress: an entry (number) is "seen" once a diecast linked to any of its variants
 * was spotted or owned, and "caught" once owned (sold still counts: it was caught once).
 */
class CatalogProgress
{
    public const SEEN = ['owned', 'spotted', 'sold'];

    public const CAUGHT = ['owned', 'sold'];

    /**
     * Counts per catalog id.
     *
     * @return Collection<int, array{total: int, seen: int, caught: int, wanted: int}>
     */
    public function perCatalog(User $user): Collection
    {
        return DB::table('catalog_items')
            ->join('catalogs', 'catalogs.id', '=', 'catalog_items.catalog_id')
            ->leftJoin('diecasts', 'diecasts.catalog_item_id', '=', 'catalog_items.id')
            ->where('catalogs.user_id', $user->id)
            ->get(['catalog_items.catalog_id', 'catalog_items.number', 'diecasts.status'])
            ->groupBy('catalog_id')
            ->map(fn (Collection $rows) => self::count(
                $rows->groupBy('number')->map(fn (Collection $entry) => $entry->pluck('status')->filter()),
            ));
    }

    /**
     * One entry per number with its variants and linked diecasts, in dex order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function entries(Catalog $catalog): Collection
    {
        $items = $catalog->items()
            ->with(['diecasts' => fn ($query) => $query->select(['id', 'user_id', 'catalog_item_id', 'catalog_match', 'name', 'status'])])
            ->get();

        return $items->groupBy('number')->map(function (Collection $variants, string $number) {
            /** @var CatalogItem $main */
            $main = $variants->first();
            $diecasts = $variants->flatMap(fn (CatalogItem $v) => $v->diecasts);
            $statuses = $diecasts->pluck('status');

            return [
                'number' => (string) $number,
                'sort' => $main->sort,
                'name' => $main->name,
                'series' => $main->series,
                'series_number' => $main->series_number,
                'photo_url' => $variants->first(fn (CatalogItem $v) => $v->photo_url)?->photo_url,
                'flags' => $variants->reduce(fn (array $all, CatalogItem $v) => [...$all, ...($v->flags ?? [])], []),
                ...self::state($statuses),
                'variants' => $variants->map(fn (CatalogItem $v) => [
                    'id' => $v->id,
                    'variant' => $v->variant,
                    'variant_label' => $v->variant_label,
                    'name' => $v->name,
                    'toy_code' => $v->toy_code,
                    'flags' => $v->flags ?? (object) [],
                    'photo_url' => $v->photo_url,
                    ...self::state($v->diecasts->pluck('status')),
                ])->values(),
                'diecasts' => $diecasts->map(fn (Diecast $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'status' => $d->status,
                    'photo_url' => $d->photo_url,
                    'catalog_item_id' => $d->catalog_item_id,
                    'catalog_match' => $d->catalog_match,
                ])->values(),
            ];
        })->values();
    }

    /**
     * Completion per series ("HW J-Imports 3/10"), in the order the series first appear.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array{series: string, total: int, seen: int, caught: int}>
     */
    public static function series(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $entry) => $entry['series'] ?? 'Lainnya')
            ->map(fn (Collection $group, string $series) => [
                'series' => $series,
                'total' => $group->count(),
                'seen' => $group->where('seen', true)->count(),
                'caught' => $group->where('caught', true)->count(),
            ])
            ->values();
    }

    /**
     * @param  Collection<string, Collection<int, string>>  $statusesByNumber
     * @return array{total: int, seen: int, caught: int, wanted: int}
     */
    public static function count(Collection $statusesByNumber): array
    {
        $states = $statusesByNumber->map(fn (Collection $statuses) => self::state($statuses));

        return [
            'total' => $states->count(),
            'seen' => $states->where('seen', true)->count(),
            'caught' => $states->where('caught', true)->count(),
            'wanted' => $states->where('wanted', true)->count(),
        ];
    }

    /**
     * @param  Collection<int, string>  $statuses
     * @return array{seen: bool, caught: bool, wanted: bool}
     */
    private static function state(Collection $statuses): array
    {
        $seen = $statuses->intersect(self::SEEN)->isNotEmpty();

        return [
            'seen' => $seen,
            'caught' => $statuses->intersect(self::CAUGHT)->isNotEmpty(),
            // On the wishlist and not seen yet: something to hunt for.
            'wanted' => ! $seen && $statuses->contains('wishlist'),
        ];
    }
}
