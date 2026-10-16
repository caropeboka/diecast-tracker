<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;
use ZipArchive;

class DataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP zip extension is not enabled.');
        }

        Storage::fake('public');
    }

    public function test_export_writes_collection_to_excel()
    {
        $user = $this->userWithCollection();

        $response = $this->actingAs($user)->get(route('data.export'))->assertOk();
        $rows = $this->readXlsx($response->getFile()->getPathname());

        $this->assertSame('Nama', $rows[0][0]);
        $this->assertSame("'71 Datsun 510", $rows[1][0]);
        $this->assertContains('Treasure Hunt', $rows[1]);
        $this->assertContains('Favorit, JDM', $rows[1]);
    }

    public function test_import_csv_with_indonesian_formats()
    {
        $user = User::factory()->create();
        $csv = "Nama;Merek;Kelangkaan;Status;Jumlah;Harga Beli;Tanggal Beli;Tag\n"
            ."Toyota AE86;Tomica;Regular;Dimiliki;2;Rp 35.000;2026-09-01;JDM, Drift\n"
            .";Matchbox;;;;;;\n"
            ."Honda NSX;Mini GT;Super Mahal;;;;;\n";

        $this->actingAs($user)
            ->post(route('data.import'), ['file' => UploadedFile::fake()->createWithContent('koleksi.csv', $csv)])
            ->assertRedirect(route('data.edit'))
            ->assertSessionHas('import_result', fn ($result) => $result['imported'] === 1 && $result['skipped_total'] === 2);

        $diecast = $user->diecasts()->with('tags')->first();
        $this->assertSame('Toyota AE86', $diecast->name);
        $this->assertSame(2, $diecast->quantity);
        $this->assertSame('35000.00', $diecast->purchase_price);
        $this->assertSame(['Drift', 'JDM'], $diecast->tags->pluck('name')->all());
    }

    public function test_export_then_import_round_trips()
    {
        $user = $this->userWithCollection();
        $file = $this->actingAs($user)->get(route('data.export'))->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        copy($file, $copy);

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('data.import'), [
            'file' => new UploadedFile($copy, 'koleksi.xlsx', null, null, true),
        ]);

        $imported = $other->diecasts()->first();
        $this->assertSame("'71 Datsun 510", $imported->name);
        $this->assertSame('treasure_hunt', $imported->rarity);
        $this->assertSame('150000.00', $imported->market_value);
    }

    public function test_backup_and_restore_with_photos()
    {
        $user = $this->userWithCollection();
        $this->actingAs($user)->post(route('sightings.store', $user->diecasts()->first()), [
            'place' => 'Toys Kingdom', 'seen_on' => '2026-10-01', 'price' => 45000, 'photo' => UploadedFile::fake()->image('rak.jpg'),
        ], ['Accept' => 'application/json'])->assertOk();

        $zip = $this->actingAs($user)->get(route('data.backup'))->assertOk()->getFile()->getPathname();
        $copy = tempnam(sys_get_temp_dir(), 'x').'.zip';
        copy($zip, $copy);

        $archive = new ZipArchive;
        $archive->open($copy);
        $this->assertNotFalse($archive->locateName('diecasts.json'));
        $this->assertNotFalse($archive->locateName('koleksi.xlsx'));
        $archive->close();

        // Replace mode wipes the current collection first.
        $user->diecasts()->create(['name' => 'Will be replaced']);

        $this->actingAs($user)
            ->post(route('data.restore'), [
                'backup' => new UploadedFile($copy, 'backup.zip', null, null, true),
                'mode' => 'replace',
                'confirm' => true,
            ])
            ->assertRedirect(route('data.edit'));

        $restored = $user->diecasts()->with('tags')->get();
        $this->assertSame(["'71 Datsun 510"], $restored->pluck('name')->all());
        $this->assertCount(1, $restored->first()->photos);
        Storage::disk('public')->assertExists($restored->first()->photos->first()->path);
        $this->assertSame(['Favorit', 'JDM'], $restored->first()->tags->pluck('name')->all());

        $sighting = $restored->first()->sightings()->first();
        $this->assertSame('Toys Kingdom', $sighting->place);
        $this->assertSame('2026-10-01', $sighting->seen_on->toDateString());
        Storage::disk('public')->assertExists($sighting->photo_path);
    }

    public function test_replace_restore_requires_confirmation()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('data.restore'), [
                'backup' => UploadedFile::fake()->create('backup.zip', 1),
                'mode' => 'replace',
            ])
            ->assertSessionHasErrors('confirm');
    }

    public function test_random_zip_is_rejected()
    {
        $zip = tempnam(sys_get_temp_dir(), 'x').'.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('hello.txt', 'hi');
        $archive->close();

        $this->actingAs(User::factory()->create())
            ->post(route('data.restore'), ['backup' => new UploadedFile($zip, 'x.zip', null, null, true), 'mode' => 'merge'])
            ->assertSessionHasErrors(['backup' => 'Ini bukan file backup Diecast Tracker.']);
    }

    private function userWithCollection(): User
    {
        $user = User::factory()->create();
        $photo = $this->actingAs($user)
            ->postJson(route('diecasts.photos.store'), ['photo' => UploadedFile::fake()->image('car.jpg')])
            ->json('path');

        $this->actingAs($user)->post(route('diecasts.store'), [
            'name' => "'71 Datsun 510", 'brand' => 'Hot Wheels', 'rarity' => 'treasure_hunt', 'packaging' => 'carded',
            'status' => 'owned', 'quantity' => 1, 'purchase_price' => 35000, 'market_value' => 150000,
            'photos' => [$photo], 'tags' => ['JDM', 'Favorit'],
        ]);

        return $user;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readXlsx(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();

        return $rows;
    }
}
