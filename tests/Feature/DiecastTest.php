<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DiecastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();
        config(['services.anthropic.key' => null, 'services.gemini.key' => null, 'services.scan.provider' => 'gemini']);
    }

    public function test_collection_page_lists_only_own_diecasts()
    {
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'Datsun 510']);
        User::factory()->create()->diecasts()->create(['name' => 'Someone Else Car']);

        $this->actingAs($user)
            ->get(route('diecasts.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('diecasts/index')
                ->has('diecasts.data', 1)
                ->where('diecasts.data.0.name', 'Datsun 510'));
    }

    public function test_search_and_tag_filter()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('diecasts.store'), $this->payload(['name' => 'Datsun 510', 'tags' => ['JDM']]));
        $this->actingAs($user)->post(route('diecasts.store'), $this->payload(['name' => 'Toyota AE86', 'tags' => ['JDM', 'Favorit']]));
        $this->actingAs($user)->post(route('diecasts.store'), $this->payload(['name' => 'Ford Mustang']));

        $this->actingAs($user)
            ->get(route('diecasts.index', ['search' => 'AE86']))
            ->assertInertia(fn ($page) => $page->has('diecasts.data', 1)->where('diecasts.data.0.name', 'Toyota AE86'));

        $this->actingAs($user)
            ->get(route('diecasts.index', ['tag' => 'JDM']))
            ->assertInertia(fn ($page) => $page->has('diecasts.data', 2)->where('tags', ['Favorit', 'JDM']));
    }

    public function test_missing_data_filter_from_dashboard()
    {
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'Priced', 'purchase_price' => 30000]);
        $user->diecasts()->create(['name' => 'No price']);
        $user->diecasts()->create(['name' => 'Wishlist no price', 'status' => 'wishlist']);

        $this->actingAs($user)
            ->get(route('diecasts.index', ['missing' => 'purchase_price']))
            ->assertInertia(fn ($page) => $page->has('diecasts.data', 1)->where('diecasts.data.0.name', 'No price'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('completeness.1.key', 'purchase_price')
                ->where('completeness.1.done', 1)
                ->etc());
    }

    public function test_photo_upload_is_resized_and_kept_temporary()
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('diecasts.photos.store'), ['photo' => UploadedFile::fake()->image('car.jpg', 3000, 2000)])
            ->assertOk();

        $path = $response->json('path');
        $this->assertStringStartsWith('diecasts/tmp/', $path);

        [$width, $height] = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame([1600, 1067], [$width, $height]);
    }

    public function test_storing_a_diecast_saves_photos_in_order_and_tags()
    {
        $user = User::factory()->create();
        $front = $this->upload($user);
        $back = $this->upload($user);

        $this->actingAs($user)
            ->post(route('diecasts.store'), $this->payload(['photos' => [$back, $front], 'tags' => ['JDM', 'jdm', ' Favorit ']]))
            ->assertRedirect(route('diecasts.index'));

        $diecast = $user->diecasts()->with('tags')->first();
        $this->assertSame(
            ['diecasts/'.basename($back), 'diecasts/'.basename($front)],
            $diecast->photos->pluck('path')->all(),
        );
        $this->assertSame('/storage/diecasts/'.basename($back), $diecast->photo_url);
        $this->assertSame(['Favorit', 'JDM'], $diecast->tags->pluck('name')->all());
        Storage::disk('public')->assertMissing($front);
    }

    public function test_updating_removes_dropped_photos_and_unused_tags()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('diecasts.store'), $this->payload([
            'photos' => [$this->upload($user), $this->upload($user)],
            'tags' => ['Lama'],
        ]));
        $diecast = $user->diecasts()->first();
        [$keep, $drop] = $diecast->photos->pluck('path')->all();

        $this->actingAs($user)
            ->put(route('diecasts.update', $diecast), $this->payload(['photos' => [$keep], 'tags' => ['Baru']]))
            ->assertRedirect();

        $this->assertSame([$keep], $diecast->fresh()->photos->pluck('path')->all());
        Storage::disk('public')->assertMissing($drop);
        $this->assertSame(['Baru'], $user->tags()->pluck('name')->all());
    }

    public function test_photo_paths_outside_upload_folders_are_ignored()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('diecasts.store'), $this->payload(['photos' => ['../../.env', 'diecasts/someone-else.jpg']]))
            ->assertRedirect();

        $this->assertCount(0, $user->diecasts()->first()->photos);
    }

    public function test_save_and_add_another_returns_to_create_page()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('diecasts.store'), $this->payload(['add_another' => true, 'status' => 'wishlist', 'target_price' => 500000]))
            ->assertRedirect(route('diecasts.create', ['status' => 'wishlist']));
    }

    public function test_scan_without_api_key_explains_how_to_enable()
    {
        $user = User::factory()->create(['is_admin' => true]);

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user)]])
            ->assertOk()
            ->assertJsonPath('fields', null)
            ->assertJsonPath('error', 'Scan AI belum aktif. Isi API key di Settings → AI Scan.');
    }

    public function test_gemini_scan_reads_all_photos_and_finds_duplicates()
    {
        config(['services.gemini.key' => 'test-key']);
        $this->fakeGemini(['name' => "'71 Datsun 510", 'brand' => 'Hot Wheels', 'rarity' => 'treasure_hunt', 'condition' => 'bogus']);

        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'Datsun 510', 'brand' => 'Hot Wheels']);
        $user->diecasts()->create(['name' => 'Ford Mustang', 'brand' => 'Hot Wheels']);

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user), $this->upload($user)]])
            ->assertOk()
            ->assertJsonPath('error', null)
            ->assertJsonPath('fields.rarity', 'treasure_hunt')
            ->assertJsonPath('fields.condition', null)
            ->assertJsonCount(1, 'matches')
            ->assertJsonPath('matches.0.name', 'Datsun 510');

        Http::assertSent(fn ($request) => str_contains($request->url(), ':generateContent')
            && $request->hasHeader('x-goog-api-key', 'test-key')
            && count($request['contents'][0]['parts']) === 3
            && str_contains($request['contents'][0]['parts'][2]['text'], 'These 2 photos'));
    }

    public function test_scan_rejects_other_peoples_photos()
    {
        config(['services.gemini.key' => 'test-key']);

        $this->actingAs(User::factory()->create())
            ->postJson(route('diecasts.scan'), ['photos' => ['diecasts/'.str_repeat('a', 36).'.jpg']])
            ->assertStatus(422);
    }

    public function test_gemini_quota_error_is_explained()
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user)]])
            ->assertOk()
            ->assertJsonPath('error', 'Kuota gratis Gemini habis untuk saat ini. Tunggu sebentar (atau besok) lalu coba lagi.');
    }

    public function test_busy_gemini_model_falls_back_to_the_next_one()
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.8-flash']);
        Http::preventStrayRequests();
        $busy = Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503);
        Http::fake([
            '*/v1beta/models?*' => Http::response(['models' => collect([
                'gemini-3.5-flash-lite', 'gemini-2.5-flash', 'gemini-3.8-flash', 'gemini-3.6-flash',
                'gemini-3.7-flash', 'gemini-3.1-flash-image', 'gemini-3-flash-preview',
            ])->map(fn ($name) => ['name' => "models/{$name}", 'supportedGenerationMethods' => ['generateContent']])->all()]),
            '*/models/gemini-3.8-flash:generateContent' => $busy,
            '*/models/gemini-3.5-flash-lite:generateContent' => $busy,
            '*/models/gemini-flash-lite-latest:generateContent' => Http::failedConnection(),
            '*/models/gemini-3.7-flash:generateContent' => $busy,
            '*/models/gemini-3.6-flash:generateContent' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'name' => 'Mazda RX-7', 'brand' => 'Tomica', 'make' => null, 'series' => null, 'collection_number' => null,
                    'year' => null, 'scale' => null, 'color' => null, 'rarity' => 'regular', 'packaging' => 'boxed',
                    'condition' => null, 'notes' => null, 'confidence' => 'high',
                ])]]]]],
            ]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user)]])
            ->assertJsonPath('error', null)
            ->assertJsonPath('fields.name', 'Mazda RX-7');

        // Model list, chosen model (+ one quick retry), then lite models first (busy, timed out),
        // then the newest regular flash models — fallbacks get no retry.
        $this->assertSame(
            [
                'models', 'gemini-3.8-flash', 'gemini-3.8-flash', 'gemini-3.5-flash-lite',
                'gemini-flash-lite-latest', 'gemini-3.7-flash', 'gemini-3.6-flash',
            ],
            Http::recorded()->map(fn ($pair) => str($pair[0]->url())->after('/v1beta/models')->ltrim('/')->before(':')->before('?')->whenEmpty(fn () => str('models'))->value())->all(),
        );
    }

    public function test_reports_busy_rather_than_a_retired_fallback()
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-3.8-flash']);
        Http::fake([
            '*/v1beta/models?*' => Http::response(['models' => [
                ['name' => 'models/gemini-2.5-flash', 'supportedGenerationMethods' => ['generateContent']],
            ]]),
            '*/models/gemini-3.8-flash:generateContent' => Http::response([], 503),
            '*' => Http::response(['error' => ['message' => 'no longer available to new users']], 404),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user)]])
            ->assertJsonPath('error', 'Server Gemini sedang sibuk (banyak pengguna). Coba lagi sebentar lagi.');
    }

    public function test_all_gemini_models_busy_says_try_again_later()
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 503]], 503)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$this->upload($user)]])
            ->assertJsonPath('fields', null)
            ->assertJsonPath('error', 'Server Gemini sedang sibuk (banyak pengguna). Coba lagi sebentar lagi.');
    }

    public function test_users_cannot_touch_other_users_diecasts()
    {
        $diecast = User::factory()->create()->diecasts()->create(['name' => 'Not yours']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->get(route('diecasts.edit', $diecast))->assertNotFound();
        $this->actingAs($intruder)->put(route('diecasts.update', $diecast), $this->payload())->assertNotFound();
        $this->actingAs($intruder)->delete(route('diecasts.destroy', $diecast))->assertNotFound();

        $this->assertModelExists($diecast);
    }

    public function test_deleting_removes_photo_files()
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('diecasts.store'), $this->payload(['photos' => [$this->upload($user)]]));
        $diecast = $user->diecasts()->first();
        $path = $diecast->photos->first()->path;

        $this->actingAs($user)->delete(route('diecasts.destroy', $diecast))->assertRedirect();

        Storage::disk('public')->assertMissing($path);
    }

    public function test_dashboard_shows_collection_value()
    {
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'A', 'quantity' => 2, 'purchase_price' => 35000, 'market_value' => 100000, 'rarity' => 'treasure_hunt']);
        $user->diecasts()->create(['name' => 'B', 'purchase_price' => 20000]);
        $user->diecasts()->create(['name' => 'C', 'status' => 'wishlist', 'target_price' => 300000]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page
                ->where('stats.total_items', 3)
                ->where('stats.total_spent', 90000)
                ->where('stats.total_value', 200000)
                ->where('stats.value_gain', 130000)
                ->where('stats.wishlist', 1)
                ->has('topGainers', 1)
                ->where('wishlist.0.name', 'C')
                ->etc());
    }

    private function upload(User $user): string
    {
        return $this->actingAs($user)
            ->postJson(route('diecasts.photos.store'), ['photo' => UploadedFile::fake()->image('car.jpg')])
            ->json('path');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function fakeGemini(array $fields): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'make' => null, 'series' => null, 'collection_number' => null, 'year' => null, 'scale' => '1:64',
                'color' => null, 'rarity' => 'regular', 'packaging' => 'carded', 'condition' => null, 'notes' => null,
                'confidence' => 'high', ...$fields,
            ])]]]]],
        ])]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => "'71 Datsun 510",
            'brand' => 'Hot Wheels',
            'rarity' => 'regular',
            'packaging' => 'carded',
            'status' => 'owned',
            'quantity' => 1,
            ...$overrides,
        ];
    }
}
