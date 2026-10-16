<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PlaceLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExploreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_lookup_prefers_the_users_own_name_for_a_nearby_spot()
    {
        Http::fake();
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'A', 'status' => 'spotted'])->sightings()->create([
            'user_id' => $user->id, 'seen_on' => now(), 'place' => 'Toys Kingdom GI',
            'latitude' => -6.195100, 'longitude' => 106.821900,
        ]);

        // ~60 m away.
        $this->actingAs($user)
            ->getJson(route('places.lookup', ['lat' => -6.1956, 'lng' => 106.8221]))
            ->assertOk()
            ->assertJsonPath('name', 'Toys Kingdom GI')
            ->assertJsonPath('source', 'history');

        Http::assertNothingSent();
    }

    public function test_lookup_falls_back_to_openstreetmap()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'name' => 'Grand Indonesia',
            'display_name' => 'Grand Indonesia, Jalan M.H. Thamrin, Menteng, Jakarta Pusat, Indonesia',
            'address' => ['mall' => 'Grand Indonesia', 'suburb' => 'Menteng'],
        ])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('places.lookup', ['lat' => -6.1951, 'lng' => 106.8219]))
            ->assertJsonPath('name', 'Grand Indonesia')
            ->assertJsonPath('source', 'osm')
            ->assertJsonPath('address', 'Grand Indonesia, Jalan M.H. Thamrin, Menteng, Jakarta Pusat');

        Http::assertSent(fn ($request) => $request->hasHeader('User-Agent') && $request['zoom'] == 18);
    }

    public function test_lookup_without_a_named_place_uses_road_and_area()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'name' => '', 'display_name' => 'Jalan Kemang Raya, Bangka, Jakarta Selatan',
            'address' => ['road' => 'Jalan Kemang Raya', 'suburb' => 'Bangka'],
        ])]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('places.lookup', ['lat' => -6.26, 'lng' => 106.81]))
            ->assertJsonPath('name', 'Jalan Kemang Raya, Bangka');
    }

    public function test_lookup_on_an_unnamed_street_uses_the_neighbourhood()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([
            'name' => '', 'display_name' => 'RW 05, Bangka, Mampang Prapatan, Jakarta Selatan, Indonesia',
            'address' => ['city_block' => 'RW 05', 'village' => 'Bangka'],
        ])]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('places.lookup', ['lat' => -6.2601, 'lng' => 106.8135]))
            ->assertJsonPath('name', 'RW 05, Bangka')
            ->assertJsonPath('source', 'osm');
    }

    public function test_lookup_survives_openstreetmap_being_down()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 503)]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('places.lookup', ['lat' => -6.26, 'lng' => 106.81]))
            ->assertOk()
            ->assertJsonPath('name', null)
            ->assertJsonPath('source', 'none');
    }

    public function test_sightings_store_coordinates()
    {
        $user = User::factory()->create();
        $diecast = $user->diecasts()->create(['name' => 'RX-7', 'status' => 'wishlist']);

        $this->actingAs($user)
            ->postJson(route('sightings.store', $diecast), ['place' => 'Gramedia', 'latitude' => -6.2, 'longitude' => 106.85])
            ->assertOk()
            ->assertJsonPath('latitude', -6.2);

        $this->actingAs($user)
            ->postJson(route('sightings.store', $diecast), ['place' => 'x', 'latitude' => -6.2])
            ->assertJsonValidationErrors('longitude');
    }

    public function test_map_groups_sightings_by_place_with_dates_and_items()
    {
        $user = User::factory()->create();
        $a = $user->diecasts()->create(['name' => 'Datsun 510', 'status' => 'spotted']);
        $b = $user->diecasts()->create(['name' => 'RX-7', 'status' => 'wishlist']);
        $at = fn (string $place, string $date, ?float $lat, ?float $lng, $diecast) => $diecast->sightings()->create([
            'user_id' => $user->id, 'place' => $place, 'seen_on' => $date, 'latitude' => $lat, 'longitude' => $lng, 'price' => 40000,
        ]);
        $at('Toys Kingdom GI', '2026-09-01', -6.1950, 106.8218, $a);
        $at('toys kingdom gi', '2026-10-01', -6.1952, 106.8220, $b);
        $at('Pasar Gembrong', '2026-10-05', null, null, $a);

        $this->actingAs($user)->get(route('explore.index'))
            ->assertInertia(fn ($page) => $page
                ->component('explore/index')
                ->where('stats.places', 2)
                ->where('stats.mapped', 1)
                ->where('stats.sightings', 3)
                ->where('stats.diecasts', 2)
                ->where('stats.first', '2026-09-01')
                ->where('places.1.name', 'Toys Kingdom GI')
                ->where('places.1.count', 2)
                ->where('places.1.visits', 2)
                ->where('places.1.lat', -6.1951)
                ->where('places.0.name', 'Pasar Gembrong')
                ->where('places.0.lat', null)
                ->has('timeline', 3));

        $this->actingAs($user)->get(route('explore.index', ['range' => '30']))
            ->assertInertia(fn ($page) => $page->where('range', '30')->where('stats.sightings', fn ($n) => $n <= 3));
    }

    public function test_diecasts_store_purchase_coordinates()
    {
        $user = User::factory()->create();
        $payload = ['name' => 'Nissan Skyline', 'rarity' => 'regular', 'packaging' => 'carded', 'status' => 'owned', 'quantity' => 1,
            'purchase_place' => 'Hobby Shop Blok M'];

        $this->actingAs($user)
            ->post(route('diecasts.store'), [...$payload, 'purchase_latitude' => -6.2443, 'purchase_longitude' => 106.8006])
            ->assertRedirect(route('diecasts.index'));

        $diecast = $user->diecasts()->first();
        $this->assertEqualsWithDelta(-6.2443, $diecast->purchase_latitude, 0.00001);
        $this->assertEqualsWithDelta(106.8006, $diecast->purchase_longitude, 0.00001);

        $this->actingAs($user)
            ->post(route('diecasts.store'), [...$payload, 'purchase_latitude' => -6.2443])
            ->assertSessionHasErrors('purchase_longitude');
    }

    public function test_lookup_recognises_a_place_where_you_bought_something()
    {
        Http::fake();
        $user = User::factory()->create();
        $user->diecasts()->create([
            'name' => 'Skyline', 'status' => 'owned', 'purchase_place' => 'Hobby Shop Blok M',
            'purchase_latitude' => -6.244300, 'purchase_longitude' => 106.800600,
        ]);

        // ~40 m away.
        $this->actingAs($user)
            ->getJson(route('places.lookup', ['lat' => -6.2446, 'lng' => 106.8008]))
            ->assertJsonPath('name', 'Hobby Shop Blok M')
            ->assertJsonPath('source', 'history');

        Http::assertNothingSent();
    }

    public function test_map_shows_purchases_next_to_sightings()
    {
        $user = User::factory()->create();
        $bought = $user->diecasts()->create([
            'name' => 'Datsun 510', 'status' => 'owned', 'purchase_place' => 'Toys Kingdom GI', 'purchase_price' => 35000,
            'purchase_date' => '2026-09-15', 'purchase_latitude' => -6.1950, 'purchase_longitude' => 106.8218,
        ]);
        $user->diecasts()->create(['name' => 'RX-7', 'status' => 'spotted'])->sightings()->create([
            'user_id' => $user->id, 'place' => 'toys kingdom gi', 'seen_on' => '2026-10-01', 'latitude' => -6.1952, 'longitude' => 106.8220,
        ]);
        // No purchase date: dated by when it was added.
        $user->diecasts()->create(['name' => 'Supra', 'status' => 'sold', 'purchase_place' => 'Tokopedia']);
        // Not bought yet, or bought somewhere unknown: not on the map.
        $user->diecasts()->create(['name' => 'Wish', 'status' => 'wishlist', 'purchase_place' => 'Somewhere']);
        $user->diecasts()->create(['name' => 'Unknown', 'status' => 'owned']);

        $this->actingAs($user)->get(route('explore.index'))
            ->assertInertia(fn ($page) => $page
                ->where('type', 'all')
                ->where('stats.places', 2)
                ->where('stats.mapped', 1)
                ->where('stats.purchases', 2)
                ->where('stats.sightings', 1)
                ->where('stats.diecasts', 3)
                ->where('places.0.name', 'Tokopedia')
                ->where('places.0.items.0.date', now()->toDateString())
                ->where('places.1.name', 'Toys Kingdom GI')
                ->where('places.1.count', 2)
                ->where('places.1.purchases', 1)
                ->where('places.1.sightings', 1)
                ->where('places.1.items.1.type', 'purchase')
                ->where('places.1.items.1.id', 'p'.$bought->id)
                ->where('places.1.items.1.date', '2026-09-15')
                ->where('timeline.2.places.0.purchases', 1));

        $this->actingAs($user)->get(route('explore.index', ['type' => 'purchase']))
            ->assertInertia(fn ($page) => $page
                ->where('type', 'purchase')
                ->where('stats.sightings', 0)
                ->where('stats.purchases', 2));

        $this->actingAs($user)->get(route('explore.index', ['type' => 'sighting']))
            ->assertInertia(fn ($page) => $page
                ->where('stats.places', 1)
                ->where('stats.purchases', 0)
                ->where('places.0.sightings', 1));

        $this->actingAs($user)->get(route('explore.index', ['type' => 'bogus']))
            ->assertInertia(fn ($page) => $page->where('type', 'all'));
    }

    public function test_search_without_a_query_lists_your_recent_places()
    {
        Http::fake();
        $user = User::factory()->create();
        $user->diecasts()->create([
            'name' => 'A', 'status' => 'owned', 'purchase_place' => 'Hobby Shop Blok M', 'purchase_date' => '2026-09-01',
            'purchase_latitude' => -6.2443, 'purchase_longitude' => 106.8006,
        ]);
        $user->diecasts()->create(['name' => 'B', 'status' => 'spotted'])->sightings()->create([
            'user_id' => $user->id, 'place' => 'Toys Kingdom GI', 'seen_on' => '2026-10-01', 'latitude' => -6.1951, 'longitude' => 106.8219,
        ]);
        // No point: can't be jumped to.
        $user->diecasts()->create(['name' => 'C', 'status' => 'owned', 'purchase_place' => 'Tokopedia']);

        $this->actingAs($user)->getJson(route('places.search'))
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Toys Kingdom GI')
            ->assertJsonPath('0.source', 'history')
            ->assertJsonPath('1.name', 'Hobby Shop Blok M')
            ->assertJsonPath('1.lat', -6.2443);

        Http::assertNothingSent();
    }

    public function test_search_puts_your_own_places_before_openstreetmap()
    {
        Http::fake(['nominatim.openstreetmap.org/search*' => Http::response([
            [
                'name' => 'Toys Kingdom', 'lat' => '-6.1580', 'lon' => '106.9080',
                'display_name' => 'Toys Kingdom, Mall Kelapa Gading, Kelapa Gading, Jakarta Utara, Indonesia',
                'address' => ['shop' => 'Toys Kingdom'],
            ],
            ['name' => '', 'lat' => '1', 'lon' => '2', 'display_name' => 'x', 'address' => []],
            // Another country: dropped because something nearby was found.
            ['name' => 'Toys R Us', 'lat' => '19.2', 'lon' => '72.97', 'display_name' => 'Toys R Us, Thane, India', 'address' => []],
        ])]);
        $user = User::factory()->create();
        $spot = fn (float $lat, float $lng) => $user->diecasts()->create([
            'name' => 'X', 'status' => 'owned', 'purchase_place' => 'Toys Kingdom GI', 'purchase_latitude' => $lat, 'purchase_longitude' => $lng,
        ]);
        $spot(-6.1951, 106.8219);
        $spot(-6.1953, 106.8221);
        // Same name in another part of town: a different branch.
        $spot(-6.2600, 106.7800);

        $this->actingAs($user)->getJson(route('places.search', ['q' => 'toys', 'lat' => -6.2, 'lng' => 106.82]))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.source', 'history')
            ->assertJsonPath('1.source', 'history')
            ->assertJsonPath('2.name', 'Toys Kingdom')
            ->assertJsonPath('2.source', 'osm')
            ->assertJsonPath('2.lat', -6.158)
            ->assertJsonPath('2.address', 'Toys Kingdom, Mall Kelapa Gading, Kelapa Gading, Jakarta Utara');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/search')
            && $request['q'] === 'toys'
            && $request['viewbox'] === '106.52,-5.9,107.12,-6.5'
            && $request->hasHeader('User-Agent'));
    }

    public function test_search_survives_openstreetmap_being_down()
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([], 503)]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('places.search', ['q' => 'gramedia']))
            ->assertOk()
            ->assertExactJson([]);
    }

    public function test_distance_helper()
    {
        // Monas to Bundaran HI is roughly 2.1 km.
        $meters = PlaceLookup::distance(-6.1754, 106.8272, -6.1950, 106.8231);

        $this->assertEqualsWithDelta(2200, $meters, 150);
    }
}
