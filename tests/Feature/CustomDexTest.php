<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Models\User;
use App\Services\DiecastWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomDexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_an_own_dex_is_filled_by_hand()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('dex.custom.store'), ['brand' => 'Mini GT', 'name' => 'Mini GT 2026', 'year' => 2026])
            ->assertRedirect(route('dex.show', Catalog::first()));
        $catalog = Catalog::first();
        $this->assertSame('custom', $catalog->source);

        $add = fn (array $data) => $this->actingAs($user)->post(route('dex.custom.items.store', $catalog), $data);

        $add(['number' => 'mgt00512', 'name' => 'Nissan Skyline GT-R (R34)', 'variant_label' => 'Bayside Blue', 'photo' => UploadedFile::fake()->image('r34.jpg')])
            ->assertSessionHasNoErrors();
        // Same number, another color: a variant of that entry.
        $add(['number' => 'MGT00512', 'name' => 'Nissan Skyline GT-R (R34)', 'variant_label' => 'Millennium Jade']);
        // No number: numbered in order.
        $add(['name' => 'Toyota Supra']);
        // Exact duplicate: skipped.
        $add(['number' => 'MGT00512', 'name' => 'Nissan Skyline GT-R (R34)', 'variant_label' => 'Bayside Blue']);

        $items = $catalog->items()->get();
        $this->assertCount(3, $items);
        $this->assertSame(['MGT00512', 1, 'Bayside Blue'], [$items[0]->number, $items[0]->variant, $items[0]->variant_label]);
        $this->assertSame(['MGT00512', 2], [$items[1]->number, $items[1]->variant]);
        $this->assertSame('2', $items[2]->number);
        Storage::disk('public')->assertExists($items[0]->image_path);
        $this->assertStringStartsWith('catalog/'.$catalog->id.'/', $items[0]->image_path);

        $this->actingAs($user)->get(route('dex.show', $catalog))
            ->assertInertia(fn ($page) => $page->has('entries', 2)->has('entries.0.variants', 2)->where('stats.total', 2));

        // Name is unique per user.
        $this->actingAs($user)
            ->post(route('dex.custom.store'), ['brand' => 'Mini GT', 'name' => 'Mini GT 2026'])
            ->assertSessionHasErrors('name');
    }

    public function test_spreadsheet_import_reads_indonesian_csv()
    {
        $user = User::factory()->create();
        $catalog = $this->dex($user);
        $csv = "Nomor;Nama;Seri;Varian\n#T64-084;Porsche 911 GT3 RS;Road64;Silver\n;Toyota GR86;;\n;;;\n";

        $this->actingAs($user)
            ->post(route('dex.custom.import', $catalog), ['file' => UploadedFile::fake()->createWithContent('list.csv', $csv)])
            ->assertSessionHasNoErrors();

        $items = $catalog->items()->get();
        $this->assertSame(['T64-084', 'Porsche 911 GT3 RS', 'Road64', 'Silver'], [$items[0]->number, $items[0]->name, $items[0]->series, $items[0]->variant_label]);
        $this->assertSame('Toyota GR86', $items[1]->name);
        $this->assertCount(2, $items);

        $this->actingAs($user)
            ->post(route('dex.custom.import', $catalog), ['file' => UploadedFile::fake()->createWithContent('bad.csv', "Foo,Bar\n1,2\n")])
            ->assertInertiaFlash('toast.message', 'Kolom "Nama" tidak ditemukan di baris pertama.');
    }

    public function test_checklist_screenshots_are_read_by_the_ai_and_matched_to_the_collection()
    {
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => [
                ['number' => 'MGT00512', 'name' => 'Nissan Skyline GT-R (R34) Nismo', 'series' => null, 'series_number' => null, 'variant' => 'LHD'],
                ['number' => 'MGT00513', 'name' => 'Honda NSX-R', 'series' => null, 'series_number' => null, 'variant' => null],
                ['number' => null, 'name' => '', 'series' => null, 'series_number' => null, 'variant' => null],
            ]])]]]]],
        ])]);
        $user = User::factory()->create();
        $catalog = $this->dex($user);
        $mine = app(DiecastWriter::class)->save($user, null, [
            'name' => 'Nissan Skyline GT-R R34', 'brand' => 'Mini GT', 'collection_number' => 'MGT00512',
            'status' => 'owned', 'rarity' => 'regular', 'packaging' => 'boxed', 'quantity' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('dex.custom.scan', $catalog), ['screenshots' => [UploadedFile::fake()->image('checklist.jpg')]], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('added', 2)
            ->assertJsonPath('skipped', 1);

        $this->assertSame(['MGT00512', 'MGT00513'], $catalog->items()->pluck('number')->all());
        $this->assertSame($catalog->items()->first()->id, $mine->fresh()->catalog_item_id);
        // Screenshots aren't kept.
        $this->assertSame([], Storage::disk('public')->allFiles('diecasts/tmp'));
    }

    public function test_the_collection_can_seed_its_own_dex()
    {
        $user = User::factory()->create();
        $catalog = $this->dex($user, 'Roll Speed');
        $writer = app(DiecastWriter::class);
        $photo = $this->actingAs($user)
            ->postJson(route('diecasts.photos.store'), ['photo' => UploadedFile::fake()->image('car.jpg')])
            ->json('path');
        $base = ['brand' => 'Roll Speed', 'status' => 'owned', 'rarity' => 'regular', 'packaging' => 'boxed', 'quantity' => 1];
        $police = $writer->save($user, null, [...$base, 'name' => 'Polisi Lalu Lintas', 'color' => 'Putih'], [$photo]);
        $writer->save($user, null, [...$base, 'name' => 'Ambulans', 'collection_number' => 'No. 7']);
        $writer->save($user, null, [...$base, 'name' => 'Other brand', 'brand' => 'Majorette']);

        $this->actingAs($user)->post(route('dex.custom.from-collection', $catalog))->assertRedirect();

        $items = $catalog->items()->get()->keyBy('name');
        $this->assertCount(2, $items);
        $this->assertSame('7', $items['Ambulans']->number);
        $this->assertSame('Putih', $items['Polisi Lalu Lintas']->variant_label);
        Storage::disk('public')->assertExists($items['Polisi Lalu Lintas']->image_path);
        $this->assertSame($items['Polisi Lalu Lintas']->id, $police->fresh()->catalog_item_id);
        $this->assertSame('confirmed', $police->fresh()->catalog_match);

        // Running it again adds nothing.
        $this->actingAs($user)->post(route('dex.custom.from-collection', $catalog));
        $this->assertSame(2, $catalog->items()->count());

        // Removing an entry unlinks the diecast.
        $this->actingAs($user)->delete(route('dex.custom.items.destroy', $items['Polisi Lalu Lintas']))->assertRedirect();
        $this->assertNull($police->fresh()->catalog_item_id);
        Storage::disk('public')->assertMissing($items['Polisi Lalu Lintas']->image_path);
    }

    public function test_wiki_lists_and_other_peoples_dex_cannot_be_edited()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $catalog = $this->dex($owner);
        $wiki = $owner->catalogs()->create(['brand' => 'Hot Wheels', 'name' => 'Hot Wheels Mainline 2026', 'year' => 2026, 'source' => 'hotwheels_wiki']);
        $item = $wiki->items()->create(['number' => '001', 'sort' => 1, 'name' => 'X']);

        $this->actingAs($other)->post(route('dex.custom.items.store', $catalog), ['name' => 'X'])->assertNotFound();
        $this->actingAs($other)->post(route('dex.custom.from-collection', $catalog))->assertNotFound();
        $this->actingAs($owner)->post(route('dex.custom.items.store', $wiki), ['name' => 'X'])->assertForbidden();
        $this->actingAs($owner)->delete(route('dex.custom.items.destroy', $item))->assertForbidden();
        $this->assertSame(1, CatalogItem::count());
    }

    private function dex(User $user, string $brand = 'Mini GT'): Catalog
    {
        return $user->catalogs()->create(['brand' => $brand, 'name' => "{$brand} list", 'source' => 'custom']);
    }
}
