<?php

namespace App\Http\Controllers;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Services\AiVision;
use App\Services\Dex\CatalogMatcher;
use App\Services\Dex\CatalogProgress;
use App\Services\Dex\CatalogSync;
use App\Services\Dex\CatalogSyncException;
use App\Services\Dex\WikiCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Dex": how much of each line (Hot Wheels mainline per year, Matchbox, own lists) has been seen and owned.
 */
class DexController extends Controller
{
    public function __construct(
        private CatalogProgress $progress,
        private CatalogMatcher $matcher,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $counts = $this->progress->perCatalog($user);
        $empty = ['total' => 0, 'seen' => 0, 'caught' => 0, 'wanted' => 0];

        $catalogs = $user->catalogs()
            ->orderBy('brand')
            ->orderByDesc('year')
            ->orderBy('name')
            ->get()
            ->map(fn (Catalog $catalog) => [
                'id' => $catalog->id,
                'brand' => $catalog->brand,
                'name' => $catalog->name,
                'year' => $catalog->year,
                'source' => $catalog->source,
                'synced_at' => $catalog->synced_at?->toIso8601String(),
                ...($counts[$catalog->id] ?? $empty),
            ]);

        return Inertia::render('dex/index', [
            'catalogs' => $catalogs,
            'brands' => $catalogs->groupBy('brand')->map(fn (Collection $group, string $brand) => [
                'brand' => $brand,
                'catalogs' => $group->count(),
                ...collect($empty)->map(fn ($zero, string $key) => $group->sum($key))->all(),
            ])->values(),
            'sources' => collect(WikiCatalog::SOURCES)->map(fn (array $source, string $key) => [
                'key' => $key,
                'brand' => $source['brand'],
                'label' => $source['label'],
                'first_year' => $source['first_year'],
                'last_year' => (int) date('Y'),
                'synced' => $catalogs->where('source', $key)->pluck('year')->values(),
            ])->values(),
            'imagesPending' => CatalogItem::whereIn('catalog_id', $user->catalogs()->select('id'))
                ->whereNotNull('image_url')->whereNull('image_path')->count(),
            'toReview' => $this->reviewQuery($request)->count(),
            // For "Buat dex sendiri": brands in the collection without a wiki list.
            'collectionBrands' => $user->diecasts()->setEagerLoads([])->whereNotNull('brand')->distinct()->orderBy('brand')->pluck('brand')
                ->reject(fn (string $brand) => collect(WikiCatalog::SOURCES)->contains(fn (array $s) => strcasecmp($s['brand'], $brand) === 0))
                ->values(),
        ]);
    }

    public function show(Request $request, Catalog $catalog): Response
    {
        $this->authorizeOwner($request, $catalog);

        $entries = $this->progress->entries($catalog);

        return Inertia::render('dex/show', [
            'catalog' => [
                'id' => $catalog->id,
                'brand' => $catalog->brand,
                'name' => $catalog->name,
                'year' => $catalog->year,
                'source' => $catalog->source,
                'source_url' => $catalog->source_page && isset(WikiCatalog::SOURCES[$catalog->source])
                    ? WikiCatalog::wikiUrl($catalog->source, $catalog->source_page)
                    : null,
                'synced_at' => $catalog->synced_at?->toIso8601String(),
            ],
            'entries' => $entries,
            'series' => CatalogProgress::series($entries),
            'stats' => [
                'total' => $entries->count(),
                'seen' => $entries->where('seen', true)->count(),
                'caught' => $entries->where('caught', true)->count(),
                'wanted' => $entries->where('wanted', true)->count(),
            ],
            'imagesPending' => $catalog->items()->whereNotNull('image_url')->whereNull('image_path')->count(),
            'aiEnabled' => $catalog->isCustom() && app(AiVision::class)->isEnabled(),
        ]);
    }

    /**
     * Sync one year from a wiki (the page calls this once per year, showing progress).
     */
    public function sync(Request $request, CatalogSync $sync): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(WikiCatalog::SOURCES))],
            'year' => ['required', 'integer', 'min:1968', 'max:'.(date('Y') + 1)],
        ]);

        set_time_limit(180);

        try {
            $result = $sync->sync($request->user(), $data['source'], (int) $data['year']);
        } catch (CatalogSyncException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'catalog_id' => $result['catalog']->id,
            'name' => $result['catalog']->name,
            'items' => $result['catalog']->items()->count(),
            'added' => $result['added'],
            'removed' => $result['removed'],
            'linked' => $result['linked'],
        ]);
    }

    /**
     * Download the next few thumbnails; the page repeats this until none are left.
     */
    public function images(Request $request, CatalogSync $sync): JsonResponse
    {
        $data = $request->validate(['catalog_id' => ['nullable', 'integer']]);

        set_time_limit(180);

        return response()->json([
            'remaining' => $sync->downloadImages($request->user(), 12, $data['catalog_id'] ?? null),
        ]);
    }

    public function destroy(Request $request, Catalog $catalog, CatalogSync $sync): RedirectResponse
    {
        $this->authorizeOwner($request, $catalog);

        $sync->delete($catalog);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$catalog->name} dihapus dari Dex."]);

        return to_route('dex.index');
    }

    /**
     * Diecasts to check: linked automatically (confirm) or not linked yet (pick a suggestion).
     */
    public function matches(Request $request): Response
    {
        $diecasts = $this->reviewQuery($request)
            ->with(['catalogItem.catalog', 'user'])
            ->orderByRaw("case when catalog_match = 'auto' then 0 else 1 end")
            ->latest('id')
            ->limit(60)
            ->get();

        return Inertia::render('dex/matches', [
            'items' => $diecasts->map(fn (Diecast $diecast) => [
                'diecast' => [
                    'id' => $diecast->id,
                    'name' => $diecast->name,
                    'brand' => $diecast->brand,
                    'series' => $diecast->series,
                    'collection_number' => $diecast->collection_number,
                    'year' => $diecast->year,
                    'status' => $diecast->status,
                    'photo_url' => $diecast->photo_url,
                    'catalog_match' => $diecast->catalog_match,
                ],
                'linked' => $diecast->catalogItem ? CatalogMatcher::present(
                    $diecast->catalogItem,
                    yearFix: $diecast->catalogItem->catalog->year !== $diecast->year ? $diecast->catalogItem->catalog->year : null,
                ) : null,
                'suggestions' => $diecast->catalogItem ? [] : $this->matcher->suggest($diecast)
                    ->map(fn (array $s) => CatalogMatcher::present($s['item'], $s['score'], $s['year_fix'])),
            ]),
            'total' => $this->reviewQuery($request)->count(),
        ]);
    }

    /**
     * Confirm, change or drop a diecast's dex entry; optionally take the entry's release year.
     */
    public function link(Request $request, Diecast $diecast): JsonResponse
    {
        abort_unless($diecast->user_id === $request->user()->id, 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(['confirm', 'link', 'ignore'])],
            'catalog_item_id' => ['required_if:action,link', 'nullable', 'integer', Rule::exists('catalog_items', 'id')->where(
                fn ($query) => $query->whereIn('catalog_id', $request->user()->catalogs()->select('id')),
            )],
            'fix_year' => ['boolean'],
        ]);

        if ($data['action'] === 'confirm') {
            abort_unless($diecast->catalog_item_id, 422, 'Belum ada entri Dex untuk dikonfirmasi.');
        }

        $diecast->forceFill(match ($data['action']) {
            'confirm' => ['catalog_match' => 'confirmed'],
            'link' => ['catalog_item_id' => $data['catalog_item_id'], 'catalog_match' => 'confirmed'],
            'ignore' => ['catalog_item_id' => null, 'catalog_match' => 'ignored'],
        });

        if (($data['fix_year'] ?? false) && $diecast->catalog_item_id) {
            $diecast->year = CatalogItem::with('catalog')->find($diecast->catalog_item_id)->catalog->year ?? $diecast->year;
        }

        $diecast->save();

        return response()->json([
            'id' => $diecast->id,
            'year' => $diecast->year,
            'catalog_match' => $diecast->catalog_match,
            'linked' => $diecast->catalogItem ? CatalogMatcher::present($diecast->catalogItem->load('catalog')) : null,
        ]);
    }

    /**
     * Collection items of a brand that has a dex, still auto-linked or not linked at all.
     *
     * @return Builder<Diecast>
     */
    private function reviewQuery(Request $request): Builder
    {
        $brands = $request->user()->catalogs()->distinct()->pluck('brand')->map(fn (string $b) => mb_strtolower($b));

        return Diecast::query()
            ->where('user_id', $request->user()->id)
            ->whereIn(DB::raw('lower(brand)'), $brands->all() ?: [''])
            ->where(fn ($query) => $query->whereNull('catalog_match')->orWhere('catalog_match', 'auto'));
    }

    private function authorizeOwner(Request $request, Catalog $catalog): void
    {
        abort_unless($catalog->user_id === $request->user()->id, 404);
    }
}
