<?php

namespace App\Http\Controllers;

use App\Models\Catalog;
use App\Services\Dex\CatalogProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $owned = fn () => $user->diecasts()->setEagerLoads([])->where('status', 'owned');
        $valued = fn () => $owned()->whereNotNull('market_value');

        $uniqueOwned = $owned()->count();

        return Inertia::render('dashboard', [
            'stats' => [
                'total_items' => (int) $owned()->sum('quantity'),
                'unique_items' => $uniqueOwned,
                'total_spent' => (float) $owned()->sum(DB::raw('COALESCE(purchase_price, 0) * quantity')),
                'priced_items' => $owned()->whereNotNull('purchase_price')->count(),
                'total_value' => (float) $valued()->sum(DB::raw('market_value * quantity')),
                'valued_items' => $valued()->count(),
                // Only items with both prices count towards gain, so missing values don't look like losses.
                'value_gain' => (float) $valued()->whereNotNull('purchase_price')->sum(DB::raw('(market_value - purchase_price) * quantity')),
                'gain_items' => $valued()->whereNotNull('purchase_price')->count(),
                'special_items' => (int) $owned()->where('rarity', '!=', 'regular')->sum('quantity'),
                'super_treasure_hunts' => (int) $owned()->where('rarity', 'super_treasure_hunt')->sum('quantity'),
                'wishlist' => $user->diecasts()->setEagerLoads([])->where('status', 'wishlist')->count(),
                'spotted' => $user->diecasts()->setEagerLoads([])->where('status', 'spotted')->count(),
                'brands' => $owned()->whereNotNull('brand')->distinct()->count('brand'),
                'pending_scans' => $user->scanItems()->where('source', 'bulk')->whereIn('status', ['pending', 'done', 'failed'])->count(),
            ],
            'completeness' => $uniqueOwned === 0 ? [] : [
                ['key' => 'photo', 'label' => 'Foto', 'done' => $owned()->has('photos')->count()],
                ['key' => 'purchase_price', 'label' => 'Harga beli', 'done' => $owned()->whereNotNull('purchase_price')->count()],
                ['key' => 'market_value', 'label' => 'Harga pasaran', 'done' => $valued()->count()],
                ['key' => 'storage_location', 'label' => 'Lokasi simpan', 'done' => $owned()->whereNotNull('storage_location')->count()],
                ['key' => 'tags', 'label' => 'Tag', 'done' => $owned()->has('tags')->count()],
            ],
            'byBrand' => $owned()
                ->selectRaw("COALESCE(brand, 'Tanpa merek') as label, SUM(quantity) as total, SUM(COALESCE(market_value, 0) * quantity) as value")
                ->groupBy('label')
                ->orderByDesc('total')
                ->get(),
            'byMake' => $owned()
                ->whereNotNull('make')
                ->selectRaw('make as label, SUM(quantity) as total')
                ->groupBy('make')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
            'byRarity' => $owned()
                ->selectRaw('rarity as label, SUM(quantity) as total')
                ->groupBy('rarity')
                ->get(),
            'byYear' => $owned()
                ->selectRaw('year as label, SUM(quantity) as total')
                ->groupBy('year')
                ->orderBy('year')
                ->get(),
            'byScale' => $owned()
                ->selectRaw("COALESCE(scale, '?') as label, SUM(quantity) as total")
                ->groupBy('label')
                ->orderByDesc('total')
                ->get(),
            'mostValuable' => $user->diecasts()->where('status', 'owned')->whereNotNull('market_value')
                ->orderByDesc('market_value')->limit(5)->get(),
            'topGainers' => $user->diecasts()
                ->where('status', 'owned')
                ->whereNotNull('market_value')
                ->whereNotNull('purchase_price')
                ->whereColumn('market_value', '>', 'purchase_price')
                ->orderByRaw('(market_value - purchase_price) DESC')
                ->limit(5)
                ->get(),
            'showcase' => $user->diecasts()
                ->whereIn('status', ['owned', 'sold'])
                ->has('photos')
                ->orderByRaw('market_value IS NULL')
                ->orderByDesc('market_value')
                ->latest('id')
                ->limit(6)
                ->get(),
            'wishlist' => $user->diecasts()->where('status', 'wishlist')->latest()->limit(5)->get(),
            'recent' => $user->diecasts()->whereIn('status', ['owned', 'sold'])->latest()->limit(6)->get(),
            'dex' => $this->dex($request),
        ]);
    }

    /**
     * The dex lists furthest along (seen share), for the dashboard's Dex panel.
     *
     * @return array<int, array<string, mixed>>
     */
    private function dex(Request $request): array
    {
        $counts = app(CatalogProgress::class)->perCatalog($request->user());

        return $request->user()->catalogs()->get(['id', 'name'])
            ->map(fn (Catalog $catalog) => [
                'id' => $catalog->id,
                'name' => $catalog->name,
                ...($counts[$catalog->id] ?? ['total' => 0, 'seen' => 0, 'caught' => 0, 'wanted' => 0]),
            ])
            ->filter(fn (array $catalog) => $catalog['total'] > 0)
            ->sortByDesc(fn (array $catalog) => $catalog['seen'] / $catalog['total'])
            ->take(4)
            ->values()
            ->all();
    }
}
