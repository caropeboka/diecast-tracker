<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

class SightingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();
        config(['services.scan.provider' => 'gemini', 'services.gemini.key' => 'test-key']);
    }

    public function test_new_spotted_diecast_records_its_first_sighting()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('diecasts.store'), [
            'name' => 'Nissan Silvia S15', 'rarity' => 'regular', 'packaging' => 'carded',
            'status' => 'spotted', 'quantity' => 1,
            'purchase_place' => 'should be ignored', 'purchase_price' => null,
            'sighting' => ['place' => 'Toys Kingdom GI', 'seen_on' => '2026-10-10', 'price' => 45000],
        ])->assertRedirect(route('diecasts.index'));

        $diecast = $user->diecasts()->with('sightings')->first();
        $this->assertSame('spotted', $diecast->status);
        $this->assertCount(1, $diecast->sightings);
        $this->assertSame('Toys Kingdom GI', $diecast->sightings[0]->place);
        $this->assertSame('45000.00', $diecast->sightings[0]->price);
        $this->assertSame('2026-10-10', $diecast->sightings[0]->seen_on->toDateString());
    }

    public function test_sightings_can_be_added_with_photo_and_removed()
    {
        $user = User::factory()->create();
        $diecast = $user->diecasts()->create(['name' => 'RX-7', 'status' => 'wishlist']);

        $sighting = $this->actingAs($user)
            ->post(route('sightings.store', $diecast), [
                'place' => 'Gramedia', 'price' => 39900, 'photo' => UploadedFile::fake()->image('rak.jpg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('place', 'Gramedia')
            ->json();

        $path = str_replace('/storage/', '', $sighting['photo_url']);
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)->deleteJson(route('sightings.destroy', $sighting['id']))->assertOk();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(0, $diecast->sightings()->count());
    }

    public function test_sightings_are_private()
    {
        $diecast = User::factory()->create()->diecasts()->create(['name' => 'Not yours']);
        $sighting = $diecast->sightings()->create(['user_id' => $diecast->user_id, 'seen_on' => now()]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('sightings.store', $diecast), ['place' => 'x'])->assertNotFound();
        $this->actingAs($intruder)->deleteJson(route('sightings.destroy', $sighting))->assertNotFound();
    }

    public function test_collection_hides_spotted_unless_filtered()
    {
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'Owned']);
        $spotted = $user->diecasts()->create(['name' => 'Seen', 'status' => 'spotted']);
        $spotted->sightings()->createMany([
            ['user_id' => $user->id, 'seen_on' => now(), 'price' => 50000],
            ['user_id' => $user->id, 'seen_on' => now(), 'price' => 42000],
        ]);

        $this->actingAs($user)->get(route('diecasts.index'))
            ->assertInertia(fn ($page) => $page->has('diecasts.data', 1)->where('diecasts.data.0.name', 'Owned'));

        $this->actingAs($user)->get(route('diecasts.index', ['status' => 'spotted']))
            ->assertInertia(fn ($page) => $page
                ->has('diecasts.data', 1)
                ->where('diecasts.data.0.sightings_count', 2)
                // SQLite returns a number, MySQL a decimal string — compare the value.
                ->where('diecasts.data.0.sightings_min_price', fn ($price) => (float) $price === 42000.0));
    }

    public function test_store_check_can_mark_a_new_diecast_as_spotted()
    {
        $this->fakeGemini(['name' => 'Toyota Supra MK4', 'brand' => 'Mini GT']);
        $user = User::factory()->create();

        $item = $this->actingAs($user)
            ->postJson(route('check.store'), ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->json();

        $this->actingAs($user)
            ->postJson(route('check.spot', $item['id']), ['place' => 'JDM Fest', 'price' => 120000])
            ->assertOk()
            ->assertJsonPath('status', 'spotted')
            ->assertJsonPath('sightings_count', 1);

        $diecast = $user->diecasts()->with('sightings')->first();
        $this->assertSame('Toyota Supra MK4', $diecast->name);
        $this->assertCount(1, $diecast->photos);
        $this->assertSame('JDM Fest', $diecast->sightings[0]->place);

        // The same check can't be logged twice.
        $this->actingAs($user)->postJson(route('check.spot', $item['id']), [])->assertStatus(422);
    }

    public function test_store_check_adds_sighting_with_photo_to_existing_wishlist_item()
    {
        $this->fakeGemini(['name' => 'Mazda RX-7', 'brand' => 'Tomica']);
        $user = User::factory()->create();
        $wish = $user->diecasts()->create(['name' => 'Mazda RX-7', 'brand' => 'Tomica', 'status' => 'wishlist']);

        $item = $this->actingAs($user)
            ->postJson(route('check.store'), ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertJsonPath('matches.0.id', $wish->id)
            ->json();

        $this->actingAs($user)
            ->postJson(route('check.spot', $item['id']), ['diecast_id' => $wish->id, 'place' => 'Toys Kingdom'])
            ->assertOk();

        $sighting = $wish->sightings()->first();
        $this->assertSame('Toys Kingdom', $sighting->place);
        $this->assertNotNull($sighting->photo_path);
        Storage::disk('public')->assertExists($sighting->photo_path);
        $this->assertSame('wishlist', $wish->fresh()->status);
    }

    public function test_bulk_save_as_spotted_turns_defaults_into_a_sighting()
    {
        $user = User::factory()->create();
        $path = 'diecasts/inbox/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('a.jpg')->getContent());
        $item = $user->scanItems()->create(['photo_path' => $path, 'status' => 'done', 'fields' => ['name' => 'Honda NSX', 'confidence' => 'high']]);

        $this->actingAs($user)->postJson(route('bulk.save', $item), [
            'status' => 'spotted', 'purchase_place' => 'Event Diecast Expo', 'purchase_date' => '2026-10-01', 'purchase_price' => 75000,
            'purchase_latitude' => -6.2297, 'purchase_longitude' => 106.8076,
        ])->assertOk();

        $diecast = $user->diecasts()->with('sightings')->first();
        $this->assertNull($diecast->purchase_place);
        $this->assertNull($diecast->purchase_price);
        $this->assertNull($diecast->purchase_latitude);
        $this->assertSame('Event Diecast Expo', $diecast->sightings[0]->place);
        $this->assertSame('75000.00', $diecast->sightings[0]->price);
        $this->assertEqualsWithDelta(-6.2297, $diecast->sightings[0]->latitude, 0.00001);
    }

    public function test_deleting_a_diecast_removes_sighting_photos()
    {
        $user = User::factory()->create();
        $diecast = $user->diecasts()->create(['name' => 'Seen', 'status' => 'spotted']);
        $this->actingAs($user)->post(route('sightings.store', $diecast), [
            'place' => 'x', 'photo' => UploadedFile::fake()->image('a.jpg'),
        ], ['Accept' => 'application/json']);
        $path = $diecast->sightings()->value('photo_path');

        $this->actingAs($user)->delete(route('diecasts.destroy', $diecast));

        Storage::disk('public')->assertMissing($path);
    }

    public function test_known_places_merge_purchase_and_sighting_places()
    {
        $user = User::factory()->create();
        $user->diecasts()->create(['name' => 'A', 'purchase_place' => 'Toys Kingdom']);
        $user->diecasts()->create(['name' => 'B', 'status' => 'spotted'])
            ->sightings()->create(['user_id' => $user->id, 'seen_on' => now(), 'place' => 'Gramedia']);

        $this->actingAs($user)->get(route('check.index'))
            ->assertInertia(fn ($page) => $page->where('places', ['Gramedia', 'Toys Kingdom']));
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function fakeGemini(array $fields): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'brand' => null, 'make' => null, 'series' => null, 'collection_number' => null, 'year' => null,
                'scale' => '1:64', 'color' => null, 'rarity' => 'regular', 'packaging' => 'carded', 'condition' => null,
                'notes' => null, 'confidence' => 'high', ...$fields,
            ])]]]]],
        ])]);
    }
}
