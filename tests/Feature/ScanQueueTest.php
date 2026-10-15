<?php

namespace Tests\Feature;

use App\Models\ScanItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ScanQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.scan.provider' => 'gemini', 'services.gemini.key' => 'test-key']);
    }

    public function test_bulk_upload_process_and_save()
    {
        $this->fakeGemini(['name' => 'Nissan Skyline GT-R (BNR34)', 'brand' => 'Hot Wheels', 'confidence' => 'high']);
        $user = User::factory()->create();

        $item = $this->actingAs($user)
            ->postJson(route('bulk.upload'), ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->json();

        $this->actingAs($user)
            ->postJson(route('bulk.process', $item['id']))
            ->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('fields.name', 'Nissan Skyline GT-R (BNR34)');

        $this->actingAs($user)
            ->postJson(route('bulk.save', $item['id']), [
                'purchase_price' => 45000,
                'storage_location' => 'Box 1',
                'tags' => ['JDM'],
                'brand' => 'Hot Wheels Premium',
            ])
            ->assertOk();

        $diecast = $user->diecasts()->with('tags')->first();
        $this->assertSame('Nissan Skyline GT-R (BNR34)', $diecast->name);
        $this->assertSame('Hot Wheels Premium', $diecast->brand);
        $this->assertSame('45000.00', $diecast->purchase_price);
        $this->assertTrue($diecast->ai_scanned);
        $this->assertSame(['JDM'], $diecast->tags->pluck('name')->all());
        $this->assertStringStartsWith('diecasts/', $diecast->photos->first()->path);
        $this->assertStringNotContainsString('inbox', $diecast->photos->first()->path);
        $this->assertSame('saved', ScanItem::find($item['id'])->status);

        $response = $this->actingAs($user)->get(route('bulk.index'));
        $response->assertInertia(fn ($page) => $page->has('items', 0));
    }

    public function test_quota_hit_keeps_item_pending_and_asks_to_wait()
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 429)]);
        $user = User::factory()->create();
        $item = $user->scanItems()->create(['photo_path' => $this->inboxPhoto()]);

        $this->actingAs($user)
            ->postJson(route('bulk.process', $item))
            ->assertStatus(429)
            ->assertJsonPath('retry_after', 60);

        $this->assertSame('pending', $item->fresh()->status);
    }

    public function test_failed_scan_can_be_completed_by_hand_from_the_form()
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);
        $user = User::factory()->create();
        $item = $user->scanItems()->create(['photo_path' => $this->inboxPhoto()]);

        $this->actingAs($user)->postJson(route('bulk.process', $item))->assertJsonPath('status', 'failed');

        $this->actingAs($user)
            ->get(route('diecasts.create', ['scan_item' => $item->id]))
            ->assertInertia(fn ($page) => $page->where('scanItem.id', $item->id));

        $this->actingAs($user)
            ->post(route('diecasts.store'), [
                'name' => 'Manual', 'rarity' => 'regular', 'packaging' => 'loose', 'status' => 'owned', 'quantity' => 1,
                'photos' => [$item->photo_path], 'scan_item_id' => $item->id,
            ])
            ->assertRedirect(route('bulk.index'));

        $this->assertSame('saved', $item->fresh()->status);
        $this->assertCount(1, $user->diecasts()->first()->photos);
    }

    public function test_discarding_deletes_the_photo()
    {
        $user = User::factory()->create();
        $item = $user->scanItems()->create(['photo_path' => $path = $this->inboxPhoto()]);

        $this->actingAs($user)->deleteJson(route('bulk.destroy', $item))->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertModelMissing($item);
    }

    public function test_queue_items_are_private()
    {
        $item = User::factory()->create()->scanItems()->create(['photo_path' => $this->inboxPhoto()]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('bulk.process', $item))->assertNotFound();
        $this->actingAs($intruder)->postJson(route('bulk.save', $item), ['name' => 'x'])->assertNotFound();
        $this->actingAs($intruder)->deleteJson(route('bulk.destroy', $item))->assertNotFound();
    }

    public function test_store_check_finds_owned_and_wishlist_items()
    {
        $this->fakeGemini(['name' => 'Porsche 911 GT3', 'brand' => 'Mini GT']);
        $user = User::factory()->create();
        $owned = $user->diecasts()->create(['name' => 'Porsche 911 GT3', 'brand' => 'Mini GT', 'quantity' => 1]);

        $this->actingAs($user)
            ->postJson(route('check.store'), ['photo' => UploadedFile::fake()->image('a.jpg')])
            ->assertOk()
            ->assertJsonPath('fields.name', 'Porsche 911 GT3')
            ->assertJsonPath('matches.0.id', $owned->id);

        $this->actingAs($user)
            ->postJson(route('check.quick', $owned), ['action' => 'increment'])
            ->assertJsonPath('quantity', 2);
    }

    public function test_acquiring_a_wishlist_item_moves_it_to_the_collection()
    {
        $user = User::factory()->create();
        $wish = $user->diecasts()->create(['name' => 'RX-7', 'status' => 'wishlist', 'target_price' => 200000]);

        $this->actingAs($user)
            ->postJson(route('check.quick', $wish), ['action' => 'acquire'])
            ->assertJsonPath('status', 'owned');

        $this->assertSame(now()->toDateString(), $wish->fresh()->purchase_date->toDateString());
    }

    private function inboxPhoto(): string
    {
        $path = 'diecasts/inbox/'.Str::uuid().'.jpg';
        Storage::disk('public')->put($path, UploadedFile::fake()->image('a.jpg')->getContent());

        return $path;
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
                'notes' => null, 'confidence' => 'medium', ...$fields,
            ])]]]]],
        ])]);
    }
}
