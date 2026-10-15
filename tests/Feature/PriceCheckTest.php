<?php

namespace Tests\Feature;

use App\Models\Diecast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PriceCheckTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();
        config(['services.scan.provider' => 'gemini', 'services.gemini.key' => 'test-key']);
    }

    public function test_screenshots_are_read_and_median_computed()
    {
        $this->fakeListings([
            ['title' => "Hot Wheels '68 Dodge Dart HW Flames", 'price_idr' => 35000, 'shop' => 'Garasi A', 'sold' => 10, 'match' => 'match'],
            ['title' => "HW '68 Dodge Dart orange", 'price_idr' => 40000, 'shop' => 'Toko B', 'sold' => 5, 'match' => 'match'],
            ['title' => 'Hot Wheels 68 Dodge Dart carded', 'price_idr' => 45000, 'shop' => 'Toko C', 'sold' => 30, 'match' => 'match'],
            ['title' => 'Dodge Dart LOOSE', 'price_idr' => 15000, 'shop' => 'Toko D', 'sold' => null, 'match' => 'different_condition'],
        ], note: 'Kebanyakan listing carded.', hint: null);

        [$user, $diecast] = $this->diecast();

        $check = $this->actingAs($user)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [
                UploadedFile::fake()->image('a.png', 1080, 2400),
                UploadedFile::fake()->image('b.png', 1080, 2400),
            ]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('stats.median', 40000)
            ->assertJsonPath('stats.count', 3)
            ->assertJsonPath('ai_note', 'Kebanyakan listing carded.')
            ->assertJsonCount(2, 'screenshot_urls')
            ->json();

        // Screenshots are kept larger than product photos so small price text stays readable.
        [, $height] = getimagesizefromstring(Storage::disk('public')->get(str_replace('/storage/', '', $check['screenshot_urls'][0])));
        $this->assertSame(2400, $height);

        Http::assertSent(fn ($request) => str_contains($request->url(), ':generateContent')
            && str_contains($request['contents'][0]['parts'][2]['text'], "Name: '68 Dodge Dart")
            && isset($request['generationConfig']['responseSchema']['properties']['listings']['items']));
    }

    public function test_applying_sets_market_value_from_the_check()
    {
        $this->fakeListings([
            ['title' => 'A', 'price_idr' => 36000, 'match' => 'match'],
            ['title' => 'B', 'price_idr' => 38000, 'match' => 'match'],
        ]);
        [$user, $diecast] = $this->diecast();
        $check = $this->actingAs($user)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])
            ->json();

        $this->actingAs($user)
            ->postJson(route('price-checks.apply', $check['id']))
            ->assertOk()
            ->assertJsonPath('market_value', '37000.00')
            ->assertJsonPath('market_value_source', 'price_check');

        // Saving the form with the same value keeps the source; typing a new one marks it manual.
        $payload = ['name' => $diecast->name, 'rarity' => 'regular', 'packaging' => 'carded', 'status' => 'owned', 'quantity' => 1];
        $this->actingAs($user)->put(route('diecasts.update', $diecast), [...$payload, 'market_value' => 37000]);
        $this->assertSame('price_check', $diecast->fresh()->market_value_source);

        $this->actingAs($user)->put(route('diecasts.update', $diecast), [...$payload, 'market_value' => 50000]);
        $this->assertSame('manual', $diecast->fresh()->market_value_source);
    }

    public function test_ai_quota_error_is_reported_and_screenshots_cleaned_up()
    {
        Http::fake(['*' => Http::response([], 429)]);
        [$user, $diecast] = $this->diecast();

        $this->actingAs($user)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Kuota gratis Gemini habis untuk saat ini. Tunggu sebentar (atau besok) lalu coba lagi.');

        $this->assertSame([], Storage::disk('public')->allFiles('price-checks'));
        $this->assertSame(0, $diecast->priceChecks()->count());
    }

    public function test_price_checks_are_private()
    {
        $this->fakeListings([['title' => 'A', 'price_idr' => 36000, 'match' => 'match']]);
        [$owner, $diecast] = $this->diecast();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])
            ->assertNotFound();

        $check = $this->actingAs($owner)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json'])
            ->json();

        $this->actingAs($intruder)->postJson(route('price-checks.apply', $check['id']))->assertNotFound();
        $this->actingAs($intruder)->deleteJson(route('price-checks.destroy', $check['id']))->assertNotFound();
    }

    public function test_deleting_a_diecast_removes_its_screenshots()
    {
        $this->fakeListings([['title' => 'A', 'price_idr' => 36000, 'match' => 'match']]);
        [$user, $diecast] = $this->diecast();
        $this->actingAs($user)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json']);
        $this->assertCount(1, Storage::disk('public')->allFiles('price-checks'));

        $this->actingAs($user)->delete(route('diecasts.destroy', $diecast));

        $this->assertSame([], Storage::disk('public')->allFiles('price-checks'));
    }

    public function test_edit_page_shows_recent_checks()
    {
        $this->fakeListings([['title' => 'A', 'price_idr' => 36000, 'match' => 'match']]);
        [$user, $diecast] = $this->diecast();
        $this->actingAs($user)
            ->post(route('price-checks.store', $diecast), ['screenshots' => [UploadedFile::fake()->image('a.png')]], ['Accept' => 'application/json']);

        $this->actingAs($user)
            ->get(route('diecasts.edit', $diecast))
            ->assertInertia(fn ($page) => $page->has('priceChecks', 1)->where('priceChecks.0.accuracy', 'insufficient'));
    }

    /**
     * @return array{0: User, 1: Diecast}
     */
    private function diecast(): array
    {
        $user = User::factory()->create();

        return [$user, $user->diecasts()->create([
            'name' => "'68 Dodge Dart", 'brand' => 'Hot Wheels', 'series' => 'HW Flames', 'collection_number' => '6/10',
        ])];
    }

    /**
     * @param  array<int, array<string, mixed>>  $listings
     */
    private function fakeListings(array $listings, ?string $note = null, ?string $hint = null): void
    {
        $listings = array_map(fn (array $listing) => [
            'is_price_range' => false, 'shop' => null, 'location' => null, 'sold' => null, 'rating' => null,
            'is_ad' => false, 'marketplace' => 'tokopedia', 'reason' => 'ok', ...$listing,
        ], $listings);

        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'listings' => $listings, 'note' => $note, 'search_hint' => $hint,
            ])]]]]],
        ])]);
    }
}
