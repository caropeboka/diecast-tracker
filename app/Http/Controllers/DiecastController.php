<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiecastRequest;
use App\Models\Diecast;
use App\Services\CollectionMatcher;
use App\Services\DiecastScanner;
use App\Services\DiecastWriter;
use App\Services\PhotoStorage;
use App\Services\SightingLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class DiecastController extends Controller
{
    public function __construct(
        private PhotoStorage $photos,
        private DiecastWriter $writer,
        private CollectionMatcher $matcher,
    ) {}

    /**
     * Show the collection with search, filters and sorting.
     */
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'brand', 'status', 'rarity', 'tag', 'missing', 'sort']);
        $diecasts = $request->user()->diecasts();

        $diecasts
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    foreach (['name', 'brand', 'make', 'series', 'color', 'collection_number', 'storage_location', 'notes'] as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }

                    $query->orWhereHas('tags', fn (Builder $tags) => $tags->where('name', 'like', "%{$search}%"));
                });
            })
            ->when($filters['brand'] ?? null, fn (Builder $query, string $brand) => $query->where('brand', $brand))
            // Spotted-only entries have their own list; keep them out of the default collection view.
            ->when(
                $filters['status'] ?? null,
                fn (Builder $query, string $status) => $query->where('status', $status),
                fn (Builder $query) => $query->where('status', '!=', 'spotted'),
            )
            ->when($filters['rarity'] ?? null, fn (Builder $query, string $rarity) => $query->where('rarity', $rarity))
            ->when($filters['tag'] ?? null, fn (Builder $query, string $tag) => $query->whereHas('tags', fn (Builder $tags) => $tags->where('name', $tag)))
            // "Lengkapi" links from the dashboard: diecasts still missing a piece of data.
            ->when($filters['missing'] ?? null, fn (Builder $query, string $missing) => match ($missing) {
                'photo' => $query->where('status', 'owned')->doesntHave('photos'),
                'tags' => $query->where('status', 'owned')->doesntHave('tags'),
                'purchase_price', 'market_value', 'storage_location' => $query->where('status', 'owned')->whereNull($missing),
                default => $query,
            });

        match ($filters['sort'] ?? 'newest') {
            'oldest' => $diecasts->oldest(),
            'name' => $diecasts->orderBy('name'),
            'brand' => $diecasts->orderBy('brand')->orderBy('name'),
            'year' => $diecasts->orderByDesc('year'),
            'price' => $diecasts->orderByDesc('purchase_price'),
            'value' => $diecasts->orderByRaw('market_value IS NULL')->orderByDesc('market_value'),
            default => $diecasts->latest(),
        };

        return Inertia::render('diecasts/index', [
            'diecasts' => $diecasts
                ->withCount('sightings')
                ->withMin('sightings', 'price')
                ->paginate(24)
                ->withQueryString(),
            // An object even when empty: as a JSON array, filters.sort would be Array.prototype.sort.
            'filters' => (object) $filters,
            'brands' => $this->distinct($request, 'brand'),
            'tags' => $request->user()->tags()->orderBy('name')->pluck('name'),
        ]);
    }

    public function create(Request $request): Response
    {
        $scanItem = $request->user()->scanItems()
            ->whereKey($request->integer('scan_item'))
            ->whereIn('status', ['done', 'failed'])
            ->first();

        return Inertia::render('diecasts/create', [
            ...$this->formOptions($request),
            'status' => $request->query('status') === 'wishlist' ? 'wishlist' : 'owned',
            'scanItem' => $scanItem ? [
                'id' => $scanItem->id,
                'photo_path' => $scanItem->photo_path,
                'photo_url' => $scanItem->photo_url,
                'fields' => $scanItem->fields,
                'matches' => $scanItem->fields ? $this->matcher->find($request->user(), $scanItem->fields) : [],
            ] : null,
        ]);
    }

    public function store(DiecastRequest $request, SightingLog $sightings): RedirectResponse
    {
        $diecast = $this->writer->save(
            $request->user(),
            null,
            $request->diecastData(),
            $request->input('photos', []),
            $request->input('tags', []),
        );

        if ($request->filled('scan_item_id')) {
            $request->user()->scanItems()
                ->whereKey($request->integer('scan_item_id'))
                ->update(['status' => 'saved', 'diecast_id' => $diecast->id]);
        }

        if ($diecast->status === 'spotted') {
            $sightings->record($diecast, $request->validated('sighting') ?? []);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $diecast->status === 'spotted'
            ? "{$diecast->name} dicatat sebagai temuan."
            : "{$diecast->name} ditambahkan ke koleksi."]);

        return match (true) {
            $request->boolean('add_another') => to_route('diecasts.create', ['status' => $diecast->status]),
            $request->filled('scan_item_id') => to_route($request->input('return_to') === 'check' ? 'check.index' : 'bulk.index'),
            default => to_route('diecasts.index'),
        };
    }

    public function edit(Request $request, Diecast $diecast): Response
    {
        $this->authorizeOwner($request, $diecast);

        return Inertia::render('diecasts/edit', [
            ...$this->formOptions($request),
            'diecast' => $diecast->load(['tags', 'sightings']),
            'priceChecks' => $diecast->priceChecks()->limit(5)->get(),
        ]);
    }

    public function update(DiecastRequest $request, Diecast $diecast): RedirectResponse
    {
        $this->authorizeOwner($request, $diecast);

        $this->writer->save(
            $request->user(),
            $diecast,
            $request->diecastData(),
            $request->input('photos', []),
            $request->input('tags', []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Perubahan disimpan.']);

        return to_route('diecasts.index');
    }

    public function destroy(Request $request, Diecast $diecast): RedirectResponse
    {
        $this->authorizeOwner($request, $diecast);

        $this->writer->delete($diecast);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$diecast->name} dihapus."]);

        return to_route('diecasts.index');
    }

    /**
     * Upload one photo from the form. It stays temporary until the diecast is saved.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $request->validate(['photo' => self::photoRule()], self::photoMessages());

        try {
            $path = $this->photos->store($request->file('photo'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['path' => $path, 'url' => '/storage/'.$path]);
    }

    /**
     * Let the AI read the given photos and return suggested field values plus look-alikes in the collection.
     */
    public function scan(Request $request, DiecastScanner $scanner): JsonResponse
    {
        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.DiecastScanner::MAX_PHOTOS],
            'photos.*' => ['string', 'max:255'],
        ]);

        $paths = array_values(array_filter($data['photos'], fn (string $path) => $this->canRead($request, $path)));

        if ($paths === []) {
            return response()->json(['message' => 'Foto tidak ditemukan. Upload ulang fotonya.'], 422);
        }

        if (! $scanner->isEnabled()) {
            return response()->json(['fields' => null, 'matches' => [], 'error' => $this->disabledMessage($request)]);
        }

        set_time_limit(120);

        try {
            $fields = $scanner->scan($paths);
        } catch (RuntimeException $e) {
            return response()->json(['fields' => null, 'matches' => [], 'error' => $e->getMessage()]);
        }

        return response()->json([
            'fields' => $fields,
            'matches' => $this->matcher->find($request->user(), $fields),
            'error' => null,
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    public static function photoRule(): array
    {
        return ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:20480'];
    }

    /**
     * @return array<string, string>
     */
    public static function photoMessages(string $field = 'photo'): array
    {
        return [
            "{$field}.image" => 'File harus berupa foto.',
            "{$field}.mimes" => 'Foto harus JPG, PNG, atau WEBP.',
            "{$field}.max" => 'Ukuran foto maksimal 20 MB.',
        ];
    }

    private function disabledMessage(Request $request): string
    {
        return $request->user()->is_admin
            ? 'Scan AI belum aktif. Isi API key di Settings → AI Scan.'
            : 'Scan AI belum aktif. Minta admin mengisi API key.';
    }

    /**
     * A photo the user may send to the AI: a fresh upload, or one of their own saved photos.
     */
    private function canRead(Request $request, string $path): bool
    {
        if ($this->photos->isPending($path)) {
            return true;
        }

        return $request->user()->diecasts()
            ->whereHas('photos', fn (Builder $query) => $query->where('path', $path))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(Request $request): array
    {
        return [
            'suggestions' => [
                'brand' => $this->distinct($request, 'brand'),
                'series' => $this->distinct($request, 'series'),
                'purchase_place' => $request->user()->knownPlaces(),
                'storage_location' => $this->distinct($request, 'storage_location'),
                'tags' => $request->user()->tags()->orderBy('name')->pluck('name'),
            ],
            'scanEnabled' => app(DiecastScanner::class)->isEnabled(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function distinct(Request $request, string $column): array
    {
        return $request->user()->diecasts()
            ->setEagerLoads([])
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    private function authorizeOwner(Request $request, Diecast $diecast): void
    {
        abort_unless($diecast->user_id === $request->user()->id, 404);
    }
}
