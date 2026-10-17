<?php

namespace Tests\Feature;

use App\Models\CatalogItem;
use App\Models\Diecast;
use App\Models\User;
use App\Services\Dex\CatalogMatcher;
use App\Services\Dex\WikiCatalog;
use App\Services\DiecastWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DexTest extends TestCase
{
    use RefreshDatabase;

    /** Trimmed copy of the real "List of 2026 Hot Wheels" table, quirks included. */
    private const HW_2026 = <<<'WIKI'
==2026 Hot Wheels #1-250==
For 2026, the Hot Wheels mainline is numbered 1 through 250.
{| class="sortable wikitable" style="font-size: 85%;" width="100%"
|-
! style="border-style: none none solid solid; |Toy #
! style="border-style: none none solid solid; |Col.#
! style="border-style: none none solid solid; |Model Name
! style="border-style: none none solid solid; |Series
! style="border-style: none none solid solid; |Series #
! style="border-style: none none solid solid; |Photo
|-
|JJM00
|009
|[['87 Buick Regal GNX]]
|bgcolor="#E60026" |[[Nightspeed Mini Collection (2026)|<font color="black">Nightspeed</font>]]<br>{{TH|2026}}
|1/10
|[[File:Image Not Available.jpg|center|75px]]
|-
|JJM15
|013
|[[Drift-Ender]]
| bgcolor="yellow" |[[Truckin' Along Mini Collection (2026)|<font color="black">Truckin' Along]]</font><br>'''[[2026 Treasure Hunts Series|<font color=#00CC00">Super Treasure Hunt]]'''</font>
|1/5
|[[File:STH Drift-Ender.jpg|center|75px]]
|-
|JJJ29
|061
|[[Mazda RX-7]]
|bgcolor="#54CBF5" |[[HW J-Imports Mini Collection (2026)|<font color="black">HW J-Imports</font>]]
|2/10
|[[File:1978MazdaRX-7.jpg|center|75px]]
|-
|JJL35
|061
|[[Mazda RX-7]] (2nd Color - Zamac)
|bgcolor="#54CBF5" |[[HW J-Imports Mini Collection (2026)|<font color="black">HW J-Imports</font>]]<br>{{WM|2026}}
|2/10
|[[File:MazdaRX-7Custom.jpg|center|75px]]
|-
|FYG07
|223
|[[Lamborghini Aventador J]] (2nd Color)
|bgcolor="red" |[[HW Exotics Mini Collection (2026)|<font color="black">HW Exotics</font>]]
|8/10
|[[File:1280х720.jpg|center|75px]]
|-
|JJX99
|250
|[[Bone Shaker]] <!-- Incorrectly numbered as 235 -->
|bgcolor="black" |[[HW Flames Mini Collection (2026)|<font color="white">HW Flames</font>]]
|5/5
|[[File:BoneShaker2026.jpg|center|75px]]
|-
|PROMO
|No #
|[[Promo Car]]
|Promo
|
|
|}
WIKI;

    private const HW_2023 = <<<'WIKI'
{| class="wikitable sortable"
! Toy # !! Col.# !! Model Name !! Series !! Series # !! Photo
|-
|HKH26
|061
|[[Mercedes-Benz Unimog 1300L|Mercedes-Benz Unimog 1300]]
| bgcolor="#FFAA1D" |[[HW Haulers Mini Collection (2023)|<font color="white">HW Haulers</font>]]
|4/5
|[[File:Unimog.png|center|75px]]
|-
|HKJ10
|021
|[[Subaru WRX STI]]
| bgcolor="#87CEFA" |[[HW J-Imports Mini Collection (2023)|<font color="black">HW J-Imports</font>]]
|2/10
|[[File:WRX.png|center|75px]]
|}
WIKI;

    private const MB_2015 = <<<'WIKI'
==1-120 Series==
{|width="80%" style="font-size: 100%; text-align: center" class="wikitable sortable"
! style="border-style: none none solid solid" |'''Model #''' <ref name=mbxforum>[https://www.mbxforum.com/x.php www.mbxforum.com] Retrieved 2019</ref>
!style="border-style: none none solid solid"|'''Man. #''' <ref name=mbxforum/>
!style="border-style: none none solid solid"|'''Card #'''
!style="border-style: none none solid solid"|'''Model Name''' <ref name=mbxforum/>
!style="border-style: none none solid solid"|'''Segment'''
!style="border-style: none none solid solid"|'''Photo''' <ref name=mbxforum/>
|-
|MB1
|MB979
|CFV75
|[[Porsche Cayman]] <br> (New)
|MBX Adventure City
|[[File:Porsche_Cayman_2015_MB1.jpg|100px]]
|-
|MB5
|MB974
|CFV80<br>DFK04
|[[Cadillac One]]
|MBX Adventure City
|[[File:Cadillac_One_2015.jpg|100px]]
|}
WIKI;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Sleep::fake();

        $pages = [
            'List of 2026 Hot Wheels' => self::HW_2026,
            'List of 2023 Hot Wheels' => self::HW_2023,
            'List of 2015 Matchbox' => self::MB_2015,
        ];

        Http::fake(function (Request $request) use ($pages) {
            if (str_contains($request->url(), 'static.wikia.nocookie.net')) {
                return Http::response('fake-image-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }

            if (! str_contains($request->url(), '.fandom.com/api.php')) {
                return null;
            }

            if ($request['action'] === 'parse') {
                return isset($pages[$request['page']])
                    ? Http::response(['parse' => ['title' => $request['page'], 'wikitext' => $pages[$request['page']]]])
                    : Http::response(['error' => ['code' => 'missingtitle', 'info' => 'The page does not exist.']]);
            }

            return Http::response(['query' => [
                // Like the real API: underscores normalized to spaces.
                'normalized' => [['from' => 'File:Porsche_Cayman_2015_MB1.jpg', 'to' => 'File:Porsche Cayman 2015 MB1.jpg']],
                'pages' => collect(explode('|', $request['titles']))
                    ->map(fn (string $title) => str_replace('_', ' ', $title))
                    ->map(fn (string $title) => ['title' => $title, 'imageinfo' => [[
                        'thumburl' => 'https://static.wikia.nocookie.net/wiki/'.rawurlencode(substr($title, 5)).'/scale-to-width-down/240',
                    ]]])
                    ->values()->all(),
            ]]);
        });
    }

    public function test_hot_wheels_list_is_read_with_variants_flags_and_photos()
    {
        $items = collect(app(WikiCatalog::class)->fetch('hotwheels_wiki', 2026)['items'])->keyBy(fn ($i) => $i['number'].'|'.$i['variant']);

        // The unnumbered promo is not part of the numbered set.
        $this->assertCount(6, $items);

        $this->assertSame('Mazda RX-7', $items['061|1']['name']);
        $this->assertSame('HW J-Imports', $items['061|1']['series']);
        $this->assertSame('2/10', $items['061|1']['series_number']);
        $this->assertSame('JJJ29', $items['061|1']['toy_code']);
        $this->assertSame('1978MazdaRX-7.jpg', $items['061|1']['image_file']);

        $this->assertSame('Mazda RX-7', $items['061|2']['name']);
        $this->assertSame('2nd Color - Zamac', $items['061|2']['variant_label']);
        $this->assertSame('Walmart', $items['061|2']['flags']['exclusive']);

        $this->assertTrue($items['009|1']['flags']['th']);
        $this->assertNull($items['009|1']['image_file']);
        // Broken <font color=#00CC00"> markup must not swallow the Super Treasure Hunt label.
        $this->assertTrue($items['013|1']['flags']['sth']);
        $this->assertArrayNotHasKey('th', $items['013|1']['flags']);
        $this->assertSame("Truckin' Along", $items['013|1']['series']);
        // HTML comments are not part of the name.
        $this->assertSame('Bone Shaker', $items['250|1']['name']);
        // Non-Latin file names stay intact (byte 0x85 inside "х" is not a line break).
        $this->assertSame('1280х720.jpg', $items['223|2']['image_file']);
    }

    public function test_matchbox_list_is_read()
    {
        $items = app(WikiCatalog::class)->fetch('matchbox_wiki', 2015)['items'];

        $this->assertCount(2, $items);
        $this->assertSame('MB1', $items[0]['number']);
        $this->assertSame('Porsche Cayman', $items[0]['name']);
        $this->assertTrue($items[0]['flags']['new']);
        $this->assertSame('CFV75', $items[0]['toy_code']);
        $this->assertSame('MBX Adventure City', $items[0]['series']);
        $this->assertSame('Porsche Cayman 2015 MB1.jpg', $items[0]['image_file']);
        $this->assertSame('CFV80', $items[1]['toy_code']);
    }

    public function test_sync_builds_the_dex_links_the_collection_and_keeps_links_on_resync()
    {
        $user = User::factory()->create();
        // As the AI read it: the copyright year (2023) instead of the release year.
        $rx7 = $this->diecast($user, ['name' => 'MAZDA RX-7', 'series' => 'HW J-Imports', 'collection_number' => '61/250 · 2/10', 'year' => 2023]);

        $this->actingAs($user)
            ->postJson(route('dex.sync'), ['source' => 'hotwheels_wiki', 'year' => 2026])
            ->assertOk()
            ->assertJsonPath('name', 'Hot Wheels Mainline 2026')
            ->assertJsonPath('items', 6)
            ->assertJsonPath('linked', 1);

        $item = CatalogItem::where('number', '061')->where('variant', 1)->first();
        $this->assertSame($item->id, $rx7->fresh()->catalog_item_id);
        $this->assertSame('auto', $rx7->fresh()->catalog_match);
        $this->assertNotNull($item->image_url);

        // Re-sync: same rows, same ids, link kept.
        $this->actingAs($user)
            ->postJson(route('dex.sync'), ['source' => 'hotwheels_wiki', 'year' => 2026])
            ->assertJsonPath('added', 0)
            ->assertJsonPath('removed', 0);
        $this->assertSame($item->id, $rx7->fresh()->catalog_item_id);
        $this->assertSame(1, $user->catalogs()->count());
    }

    public function test_matcher_finds_the_release_year_across_years()
    {
        $user = User::factory()->create();
        $this->sync($user, 2023);
        $this->sync($user, 2026);

        $rx7 = $this->diecast($user, ['name' => 'MAZDA RX-7', 'series' => 'HW J-Imports', 'collection_number' => '61/250 · 2/10', 'year' => 2023]);
        $suggestions = app(CatalogMatcher::class)->suggest($rx7);

        $this->assertSame(2026, $suggestions[0]['item']->catalog->year);
        $this->assertSame('061', $suggestions[0]['item']->number);
        $this->assertSame(2026, $suggestions[0]['year_fix']);
        // 2023's #061 is the Unimog: same number, nothing else in common.
        $this->assertSame('Mercedes-Benz Unimog 1300', $suggestions[1]['item']->name);
        $this->assertGreaterThanOrEqual(CatalogMatcher::AUTO_MARGIN, $suggestions[0]['score'] - $suggestions[1]['score']);
        // Linked when saved.
        $this->assertSame($suggestions[0]['item']->id, $rx7->fresh()->catalog_item_id);

        // A name alone is a suggestion, never an automatic link.
        $loose = $this->diecast($user, ['name' => 'Mazda RX-7', 'series' => null, 'collection_number' => null, 'year' => null]);
        $this->assertNull($loose->fresh()->catalog_item_id);
        $this->assertSame('061', app(CatalogMatcher::class)->suggest($loose)[0]['item']->number);
    }

    public function test_card_numbers_are_parsed()
    {
        $this->assertSame(['main' => 61, 'series' => '2/10', 'code' => null], CatalogMatcher::numbers('61/250 · 2/10'));
        $this->assertSame(['main' => null, 'series' => '2/5', 'code' => null], CatalogMatcher::numbers('02/05'));
        $this->assertSame(['main' => 12, 'series' => null, 'code' => null], CatalogMatcher::numbers('MB12'));
        $this->assertSame(['main' => null, 'series' => null, 'code' => 'MGT00512'], CatalogMatcher::numbers('MGT00512'));
        $this->assertSame(['mazda', 'rx', '7'], CatalogMatcher::tokens("'95 Mazda RX-7"));
    }

    public function test_progress_counts_each_number_once()
    {
        $user = User::factory()->create();
        $catalog = $this->sync($user, 2026);
        $item = fn (string $number, int $variant = 1) => CatalogItem::where('number', $number)->where('variant', $variant)->first();

        // Both colors of #061 owned: still one entry.
        $this->linked($user, $item('061'), 'owned');
        $this->linked($user, $item('061', 2), 'owned');
        $this->linked($user, $item('009'), 'spotted');
        $this->linked($user, $item('013'), 'wishlist');
        $this->linked($user, $item('250'), 'sold');

        $this->actingAs($user)->get(route('dex.index'))
            ->assertInertia(fn ($page) => $page
                ->component('dex/index')
                ->where('catalogs.0.total', 5)
                ->where('catalogs.0.seen', 3)
                ->where('catalogs.0.caught', 2)
                ->where('catalogs.0.wanted', 1)
                ->where('brands.0.brand', 'Hot Wheels'));

        $this->actingAs($user)->get(route('dex.show', $catalog))
            ->assertInertia(fn ($page) => $page
                ->component('dex/show')
                ->has('entries', 5)
                ->where('entries.2.number', '061')
                ->has('entries.2.variants', 2)
                ->has('entries.2.diecasts', 2)
                ->where('entries.2.caught', true)
                ->where('entries.0.seen', true)
                ->where('entries.0.caught', false)
                ->where('entries.1.wanted', true)
                ->where('stats', ['total' => 5, 'seen' => 3, 'caught' => 2, 'wanted' => 1])
                ->where('series.0.series', 'Nightspeed'));
    }

    public function test_thumbnails_are_downloaded_once_in_batches()
    {
        $user = User::factory()->create();
        $this->sync($user, 2026);

        $this->actingAs($user)->postJson(route('dex.images'))->assertOk()->assertJsonPath('remaining', 0);

        $item = CatalogItem::where('number', '061')->where('variant', 1)->first();
        Storage::disk('public')->assertExists($item->image_path);
        $this->assertStringStartsWith('/storage/catalog/', $item->photo_url);
        // "Image Not Available" has nothing to download.
        $this->assertNull(CatalogItem::where('number', '009')->first()->image_path);

        $downloads = fn () => collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'static.wikia'))->count();
        $before = $downloads();
        $this->actingAs($user)->postJson(route('dex.images'))->assertJsonPath('remaining', 0);
        $this->assertSame($before, $downloads());
    }

    public function test_links_can_be_confirmed_changed_and_dropped()
    {
        $user = User::factory()->create();
        $this->sync($user, 2026);
        $rx7 = $this->diecast($user, ['name' => 'MAZDA RX-7', 'series' => 'HW J-Imports', 'collection_number' => '61/250 · 2/10', 'year' => 2023]);
        $zamac = CatalogItem::where('number', '061')->where('variant', 2)->first();

        $this->actingAs($user)
            ->postJson(route('dex.link', $rx7), ['action' => 'confirm', 'fix_year' => true])
            ->assertOk()
            ->assertJsonPath('catalog_match', 'confirmed')
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('linked.number', '061');

        $this->actingAs($user)
            ->postJson(route('dex.link', $rx7), ['action' => 'link', 'catalog_item_id' => $zamac->id])
            ->assertJsonPath('linked.variant_label', '2nd Color - Zamac');

        $this->actingAs($user)
            ->postJson(route('dex.link', $rx7), ['action' => 'ignore'])
            ->assertJsonPath('linked', null)
            ->assertJsonPath('catalog_match', 'ignored');

        // A rejected match stays rejected after the next sync.
        $this->sync($user, 2026);
        $this->assertNull($rx7->fresh()->catalog_item_id);
    }

    public function test_adding_from_the_dex_prefills_the_form_and_links_it()
    {
        $user = User::factory()->create();
        $this->sync($user, 2026);
        $zamac = CatalogItem::where('number', '061')->where('variant', 2)->first();

        $this->actingAs($user)
            ->get(route('diecasts.create', ['dex_item' => $zamac->id, 'status' => 'spotted']))
            ->assertInertia(fn ($page) => $page
                ->where('status', 'spotted')
                ->where('dexItem.item_id', $zamac->id)
                ->where('dexItem.fields.name', 'Mazda RX-7')
                ->where('dexItem.fields.brand', 'Hot Wheels')
                ->where('dexItem.fields.collection_number', '61/250 · 2/10')
                ->where('dexItem.fields.year', 2026));

        $this->actingAs($user)->post(route('diecasts.store'), [
            'name' => 'Mazda RX-7', 'brand' => 'Hot Wheels', 'rarity' => 'regular', 'packaging' => 'carded',
            'status' => 'owned', 'quantity' => 1, 'catalog_item_id' => $zamac->id,
        ])->assertRedirect(route('diecasts.index'));

        $diecast = $user->diecasts()->first();
        $this->assertSame($zamac->id, $diecast->catalog_item_id);
        $this->assertSame('confirmed', $diecast->catalog_match);
    }

    public function test_scan_suggests_the_dex_entry_with_its_release_year()
    {
        $user = User::factory()->create();
        $this->sync($user, 2026);
        config(['services.gemini.key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode([
                'name' => 'MAZDA RX-7', 'brand' => 'Hot Wheels', 'make' => 'Mazda', 'series' => 'HW J-Imports',
                'collection_number' => '61/250 · 2/10', 'year' => 2023, 'scale' => '1:64', 'color' => null,
                'rarity' => 'regular', 'packaging' => 'carded', 'condition' => null, 'notes' => null, 'confidence' => 'high',
            ])]]]]],
        ])]);
        $photo = $this->actingAs($user)
            ->postJson(route('diecasts.photos.store'), ['photo' => UploadedFile::fake()->image('car.jpg')])
            ->json('path');

        $this->actingAs($user)
            ->postJson(route('diecasts.scan'), ['photos' => [$photo]])
            ->assertOk()
            ->assertJsonPath('fields.year', 2023)
            ->assertJsonPath('dex.number', '061')
            ->assertJsonPath('dex.year', 2026)
            ->assertJsonPath('dex.year_fix', 2026);
    }

    public function test_a_dropped_connection_is_retried_then_reported()
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;

            throw new ConnectionException('Connection reset');
        });

        $this->actingAs(User::factory()->create())
            ->postJson(route('dex.sync'), ['source' => 'hotwheels_wiki', 'year' => 2026])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tidak bisa menghubungi wiki. Cek koneksi internet lalu coba lagi.');

        $this->assertSame(3, $attempts);
    }

    public function test_a_year_missing_from_the_wiki_is_reported()
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('dex.sync'), ['source' => 'hotwheels_wiki', 'year' => 2016])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Daftar 2016 belum ada di wiki.');

        $this->actingAs(User::factory()->create())
            ->postJson(route('dex.sync'), ['source' => 'somewhere', 'year' => 2026])
            ->assertJsonValidationErrors('source');
    }

    public function test_dex_is_private()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $catalog = $this->sync($owner, 2026);
        $item = CatalogItem::where('number', '061')->first();
        $mine = $this->diecast($other, ['name' => 'X']);

        $this->actingAs($other)->get(route('dex.show', $catalog))->assertNotFound();
        $this->actingAs($other)->delete(route('dex.destroy', $catalog))->assertNotFound();
        $this->actingAs($other)->postJson(route('dex.link', $this->diecast($owner, ['name' => 'Y'])), ['action' => 'ignore'])->assertNotFound();
        // Someone else's dex entry can't be linked or used to prefill.
        $this->actingAs($other)
            ->postJson(route('dex.link', $mine), ['action' => 'link', 'catalog_item_id' => $item->id])
            ->assertJsonValidationErrors('catalog_item_id');
        $this->actingAs($other)
            ->get(route('diecasts.create', ['dex_item' => $item->id]))
            ->assertInertia(fn ($page) => $page->where('dexItem', null));
    }

    public function test_deleting_a_dex_unlinks_the_collection_and_removes_photos()
    {
        $user = User::factory()->create();
        $catalog = $this->sync($user, 2026);
        $rx7 = $this->diecast($user, ['name' => 'MAZDA RX-7', 'series' => 'HW J-Imports', 'collection_number' => '61/250 · 2/10']);
        $this->actingAs($user)->postJson(route('dex.images'));

        $this->actingAs($user)->delete(route('dex.destroy', $catalog))->assertRedirect(route('dex.index'));

        $this->assertNull($rx7->fresh()->catalog_item_id);
        $this->assertNull($rx7->fresh()->catalog_match);
        $this->assertSame(0, CatalogItem::count());
        $this->assertSame([], Storage::disk('public')->allFiles('catalog'));
    }

    private function sync(User $user, int $year)
    {
        $this->actingAs($user)->postJson(route('dex.sync'), ['source' => 'hotwheels_wiki', 'year' => $year])->assertOk();

        return $user->catalogs()->where('year', $year)->first();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function diecast(User $user, array $attributes): Diecast
    {
        return app(DiecastWriter::class)->save($user, null, [
            'brand' => 'Hot Wheels', 'status' => 'owned', 'rarity' => 'regular', 'packaging' => 'carded', 'quantity' => 1,
            ...$attributes,
        ]);
    }

    private function linked(User $user, CatalogItem $item, string $status): Diecast
    {
        $diecast = $user->diecasts()->create(['name' => $item->name, 'status' => $status]);
        $diecast->forceFill(['catalog_item_id' => $item->id, 'catalog_match' => 'confirmed'])->save();

        return $diecast;
    }
}
