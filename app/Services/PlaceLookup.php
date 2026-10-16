<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns GPS coordinates into a place name: the user's own name for a spot they logged before
 * (so "Toys Kingdom GI" only has to be typed once), otherwise OpenStreetMap's reverse geocoding.
 */
class PlaceLookup
{
    /** A previously logged place counts as "here" within this distance. */
    public const NEARBY_METERS = 150;

    /** Search results within this distance of the map beat ones further away. */
    public const NEARBY_KM = 150;

    /**
     * @return array{name: ?string, address: ?string, source: 'history'|'osm'|'none', distance: ?int}
     */
    public function lookup(User $user, float $lat, float $lng): array
    {
        if ($known = $this->nearestKnown($user, $lat, $lng)) {
            return $known;
        }

        $osm = $this->reverseGeocode($lat, $lng);

        return $osm ?? ['name' => null, 'address' => null, 'source' => 'none', 'distance' => null];
    }

    /**
     * Great-circle distance in meters.
     */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    /**
     * @return array{name: string, address: null, source: 'history', distance: int}|null
     */
    private function nearestKnown(User $user, float $lat, float $lng): ?array
    {
        // ~0.002° ≈ 220 m: a cheap box first, exact distance after. Spots come from sightings and purchases.
        $spotted = $user->sightings()
            ->whereNotNull('place')
            ->whereBetween('latitude', [$lat - 0.002, $lat + 0.002])
            ->whereBetween('longitude', [$lng - 0.002, $lng + 0.002])
            ->get(['place', 'latitude', 'longitude'])
            ->map(fn ($s) => ['place' => $s->place, 'lat' => $s->latitude, 'lng' => $s->longitude]);

        $bought = $user->diecasts()->setEagerLoads([])
            ->whereNotNull('purchase_place')
            ->whereBetween('purchase_latitude', [$lat - 0.002, $lat + 0.002])
            ->whereBetween('purchase_longitude', [$lng - 0.002, $lng + 0.002])
            ->get(['purchase_place', 'purchase_latitude', 'purchase_longitude'])
            ->map(fn ($d) => ['place' => $d->purchase_place, 'lat' => $d->purchase_latitude, 'lng' => $d->purchase_longitude]);

        $nearest = $spotted->concat($bought)
            ->map(fn ($s) => ['place' => $s['place'], 'distance' => self::distance($lat, $lng, $s['lat'], $s['lng'])])
            ->filter(fn ($s) => $s['distance'] <= self::NEARBY_METERS)
            ->sortBy('distance')
            ->first();

        return $nearest
            ? ['name' => $nearest['place'], 'address' => null, 'source' => 'history', 'distance' => (int) round($nearest['distance'])]
            : null;
    }

    /**
     * OpenStreetMap Nominatim (free; max ~1 request/second, so results are cached per ~10 m cell).
     *
     * @return array{name: ?string, address: ?string, source: 'osm', distance: null}|null
     */
    private function reverseGeocode(float $lat, float $lng): ?array
    {
        $key = sprintf('osm-reverse:%.4f,%.4f', $lat, $lng);

        $result = Cache::remember($key, now()->addDays(30), function () use ($lat, $lng) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => config('app.name').'/1.0 (personal diecast collection tracker)'])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'format' => 'jsonv2',
                        'lat' => $lat,
                        'lon' => $lng,
                        'zoom' => 18,
                        'addressdetails' => 1,
                        'accept-language' => 'id',
                    ]);
            } catch (ConnectionException) {
                return null;
            }

            if ($response->failed() || $response->json('error')) {
                Log::info('Reverse geocoding failed', ['status' => $response->status()]);

                return null;
            }

            return $response->json();
        });

        if (! is_array($result)) {
            Cache::forget($key); // don't remember failures

            return null;
        }

        return [...$this->describe($result), 'source' => 'osm', 'distance' => null];
    }

    /**
     * Places matching a search: the user's own spots first, then OpenStreetMap (biased towards
     * where the map is, so "Toys Kingdom" finds the nearby branch). An empty query lists recent spots.
     *
     * @return list<array{name: string, address: ?string, lat: float, lng: float, source: 'history'|'osm'}>
     */
    public function search(User $user, string $query, ?float $lat = null, ?float $lng = null): array
    {
        $needle = mb_strtolower(trim($query));

        $known = $this->knownSpots($user)
            ->filter(fn (array $spot) => $needle === '' || str_contains(mb_strtolower($spot['place']), $needle))
            // Same name ~1 km apart = same place; chains with many branches stay separate.
            ->groupBy(fn (array $spot) => sprintf('%s|%.2f,%.2f', mb_strtolower(trim($spot['place'])), $spot['lat'], $spot['lng']))
            ->map(fn ($spots) => [
                'name' => $spots->sortByDesc('date')->first()['place'],
                'address' => null,
                'lat' => round($spots->avg('lat'), 6),
                'lng' => round($spots->avg('lng'), 6),
                'source' => 'history',
                'last' => $spots->max('date'),
            ])
            ->sortByDesc('last')
            ->take($needle === '' ? 8 : 5)
            ->map(fn (array $place) => Arr::except($place, 'last'))
            ->values();

        if (mb_strlen($needle) < 3) {
            return $known->all();
        }

        return $known->concat($this->forwardGeocode($needle, $lat, $lng))->values()->all();
    }

    /**
     * Every place with coordinates the user logged: sightings and purchases.
     *
     * @return Collection<int, array{place: string, lat: float, lng: float, date: string}>
     */
    private function knownSpots(User $user): Collection
    {
        $spotted = $user->sightings()
            ->whereNotNull('place')
            ->whereNotNull('latitude')
            ->get(['place', 'latitude', 'longitude', 'seen_on'])
            ->map(fn ($s) => ['place' => $s->place, 'lat' => $s->latitude, 'lng' => $s->longitude, 'date' => $s->seen_on->toDateString()]);

        $bought = $user->diecasts()->setEagerLoads([])
            ->whereNotNull('purchase_place')
            ->whereNotNull('purchase_latitude')
            ->get(['purchase_place', 'purchase_latitude', 'purchase_longitude', 'purchase_date', 'created_at'])
            ->map(fn ($d) => [
                'place' => $d->purchase_place, 'lat' => $d->purchase_latitude, 'lng' => $d->purchase_longitude,
                'date' => ($d->purchase_date ?? $d->created_at)->toDateString(),
            ]);

        return $spotted->concat($bought);
    }

    /**
     * @return list<array{name: string, address: ?string, lat: float, lng: float, source: 'osm'}>
     */
    private function forwardGeocode(string $query, ?float $lat, ?float $lng): array
    {
        $near = $lat !== null && $lng !== null;
        $key = 'osm-search:'.md5($query.'|'.($near ? sprintf('%.1f,%.1f', $lat, $lng) : ''));

        $results = Cache::remember($key, now()->addDays(7), function () use ($query, $near, $lat, $lng) {
            try {
                $response = Http::timeout(8)
                    ->withHeaders(['User-Agent' => config('app.name').'/1.0 (personal diecast collection tracker)'])
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q' => $query,
                        'format' => 'jsonv2',
                        'limit' => 6,
                        'addressdetails' => 1,
                        'accept-language' => 'id',
                        // Prefer (not limit to) ~30 km around the map.
                        ...($near ? ['viewbox' => implode(',', [$lng - 0.3, $lat + 0.3, $lng + 0.3, $lat - 0.3])] : []),
                    ]);
            } catch (ConnectionException) {
                return null;
            }

            if ($response->failed() || ! is_array($response->json())) {
                Log::info('Place search failed', ['status' => $response->status()]);

                return null;
            }

            return $response->json();
        });

        if (! is_array($results)) {
            Cache::forget($key);

            return [];
        }

        $places = collect($results)
            ->filter(fn ($result) => isset($result['lat'], $result['lon']) && filled($result['name'] ?? null))
            ->map(fn (array $result) => [
                ...$this->describe($result),
                'lat' => (float) $result['lat'],
                'lng' => (float) $result['lon'],
                'source' => 'osm',
            ]);

        if ($near) {
            // The viewbox only biases; nearest first, and drop other countries when something is close.
            $places = $places
                ->map(fn (array $place) => [...$place, 'km' => self::distance($lat, $lng, $place['lat'], $place['lng']) / 1000])
                ->sortBy('km');

            if ($places->contains(fn (array $place) => $place['km'] <= self::NEARBY_KM)) {
                $places = $places->filter(fn (array $place) => $place['km'] <= self::NEARBY_KM);
            }

            $places = $places->map(fn (array $place) => Arr::except($place, 'km'));
        }

        return $places->values()->all();
    }

    /**
     * Short name + address for a Nominatim result.
     *
     * @param  array<string, mixed>  $result
     * @return array{name: ?string, address: ?string}
     */
    private function describe(array $result): array
    {
        $address = $result['address'] ?? [];
        $area = $address['suburb'] ?? $address['city_district'] ?? $address['village'] ?? $address['city'] ?? null;
        $name = filled($result['name'] ?? null)
            ? $result['name']
            : collect(['shop', 'amenity', 'mall', 'building', 'tourism', 'leisure', 'road'])
                ->map(fn ($k) => $address[$k] ?? null)
                ->first(fn ($v) => filled($v));

        if ($name && $area && ! str_contains($name, $area) && empty($result['name'])) {
            $name .= ', '.$area;
        }

        // Nothing named here (e.g. a small unnamed street): the neighbourhood, like "RW 05, Bangka".
        if (! $name && filled($result['display_name'] ?? null)) {
            $name = implode(', ', array_slice(explode(', ', $result['display_name']), 0, 2));
        }

        return [
            'name' => $name,
            'address' => isset($result['display_name'])
                ? implode(', ', array_slice(explode(', ', $result['display_name']), 0, 4))
                : null,
        ];
    }
}
