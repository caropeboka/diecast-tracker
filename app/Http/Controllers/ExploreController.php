<?php

namespace App\Http\Controllers;

use App\Models\Diecast;
use App\Models\Sighting;
use App\Models\User;
use App\Services\PlaceLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Peta jelajah": every place where diecasts were spotted or bought, when, and what was there.
 */
class ExploreController extends Controller
{
    private const RANGES = ['all', '30', '90', '365'];

    private const TYPES = ['all', 'sighting', 'purchase'];

    public function index(Request $request): Response
    {
        $range = in_array($request->query('range'), self::RANGES, true) ? $request->query('range') : 'all';
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : 'all';
        $since = $range === 'all' ? null : now()->subDays((int) $range)->toDateString();

        $events = collect()
            ->when($type !== 'purchase', fn ($all) => $all->concat($this->sightingEvents($request->user(), $since)))
            ->when($type !== 'sighting', fn ($all) => $all->concat($this->purchaseEvents($request->user(), $since)))
            ->sortByDesc('date')
            ->values();

        $places = $this->groupByPlace($events);

        return Inertia::render('explore/index', [
            'range' => $range,
            'type' => $type,
            'places' => $places->values(),
            'stats' => [
                'places' => $places->count(),
                'mapped' => $places->whereNotNull('lat')->count(),
                'sightings' => $events->where('type', 'sighting')->count(),
                'purchases' => $events->where('type', 'purchase')->count(),
                'diecasts' => $events->pluck('diecast_id')->unique()->count(),
                'days' => $events->pluck('date')->unique()->count(),
                'first' => $events->last()['date'] ?? null,
                'last' => $events->first()['date'] ?? null,
                'top' => $places->sortByDesc('count')->first()['name'] ?? null,
            ],
            'timeline' => $events
                ->groupBy('date')
                ->take(30)
                ->map(fn (Collection $day, string $date) => [
                    'date' => $date,
                    'places' => $day->groupBy(fn (array $e) => $e['place'] ?? 'Tanpa nama tempat')
                        ->map(fn (Collection $items, string $name) => [
                            'name' => $name,
                            'sightings' => $items->where('type', 'sighting')->count(),
                            'purchases' => $items->where('type', 'purchase')->count(),
                        ])
                        ->values(),
                ])
                ->values(),
        ]);
    }

    /**
     * Name for the GPS position the phone just sent (used by the "Ambil lokasi" button).
     */
    public function lookup(Request $request, PlaceLookup $places): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        return response()->json($places->lookup($request->user(), (float) $data['lat'], (float) $data['lng']));
    }

    /**
     * "Cari tempat" in the location picker: own places first, then OpenStreetMap near the map center.
     */
    public function search(Request $request, PlaceLookup $places): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        return response()->json($places->search(
            $request->user(),
            $data['q'] ?? '',
            isset($data['lat']) ? (float) $data['lat'] : null,
            isset($data['lng']) ? (float) $data['lng'] : null,
        ));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function sightingEvents(User $user, ?string $since): Collection
    {
        return $user->sightings()
            ->with(['diecast' => fn ($query) => $query->select(['id', 'name', 'brand', 'status'])])
            ->when($since, fn ($query) => $query->where('seen_on', '>=', $since))
            ->get()
            ->map(fn (Sighting $s) => [
                'type' => 'sighting',
                'id' => 's'.$s->id,
                'diecast_id' => $s->diecast_id,
                'name' => $s->diecast?->name,
                'brand' => $s->diecast?->brand,
                'photo_url' => $s->photo_url ?? $s->diecast?->photo_url,
                'place' => $s->place,
                'lat' => $s->latitude,
                'lng' => $s->longitude,
                'date' => $s->seen_on->toDateString(),
                'price' => $s->price,
            ]);
    }

    /**
     * Diecasts in the garage with a known purchase place; dated by purchase date (or when added).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseEvents(User $user, ?string $since): Collection
    {
        return $user->diecasts()
            ->whereIn('status', ['owned', 'sold'])
            ->where(fn ($query) => $query->whereNotNull('purchase_place')->orWhereNotNull('purchase_latitude'))
            ->get()
            ->map(fn (Diecast $d) => [
                'type' => 'purchase',
                'id' => 'p'.$d->id,
                'diecast_id' => $d->id,
                'name' => $d->name,
                'brand' => $d->brand,
                'photo_url' => $d->photo_url,
                'place' => $d->purchase_place,
                'lat' => $d->purchase_latitude,
                'lng' => $d->purchase_longitude,
                'date' => ($d->purchase_date ?? $d->created_at)->toDateString(),
                'price' => $d->purchase_price,
            ])
            ->when($since, fn ($events) => $events->filter(fn (array $e) => $e['date'] >= $since))
            ->values();
    }

    /**
     * One marker per place: events grouped by (case-insensitive) place name; unnamed ones by ~100 m cell.
     *
     * @param  Collection<int, array<string, mixed>>  $events  newest first
     * @return Collection<string, array<string, mixed>>
     */
    private function groupByPlace(Collection $events): Collection
    {
        return $events
            ->groupBy(fn (array $e) => $e['place']
                ? 'name:'.mb_strtolower(trim($e['place']))
                : ($e['lat'] !== null ? sprintf('cell:%.3f,%.3f', $e['lat'], $e['lng']) : 'unknown'))
            ->map(function (Collection $items, string $key) {
                $located = $items->whereNotNull('lat');
                // Most used spelling of the name; on a tie, the one typed first.
                $spellings = $items->reverse()->pluck('place')->filter()->countBy();

                return [
                    'key' => $key,
                    'name' => $spellings->search($spellings->max())
                        ?: ($key === 'unknown' ? 'Tanpa nama tempat' : 'Titik tanpa nama'),
                    'lat' => $located->isNotEmpty() ? round($located->avg('lat'), 6) : null,
                    'lng' => $located->isNotEmpty() ? round($located->avg('lng'), 6) : null,
                    'count' => $items->count(),
                    'sightings' => $items->where('type', 'sighting')->count(),
                    'purchases' => $items->where('type', 'purchase')->count(),
                    'visits' => $items->pluck('date')->unique()->count(),
                    'first' => $items->last()['date'],
                    'last' => $items->first()['date'],
                    'items' => $items->take(12)->values(),
                ];
            })
            ->sortByDesc('last');
    }
}
