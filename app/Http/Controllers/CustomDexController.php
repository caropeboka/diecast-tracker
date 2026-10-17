<?php

namespace App\Http\Controllers;

use App\Models\Catalog;
use App\Models\CatalogItem;
use App\Services\AiVision;
use App\Services\Dex\CatalogImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use RuntimeException;

/**
 * Dex lists the user builds for brands without a wiki list: by hand, spreadsheet, checklist screenshots or the collection.
 */
class CustomDexController extends Controller
{
    public function __construct(private CatalogImport $import) {}

    public function store(Request $request): RedirectResponse
    {
        $catalog = $request->user()->catalogs()->create([...$this->validated($request), 'source' => 'custom']);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Dex {$catalog->name} dibuat. Sekarang isi daftarnya."]);

        return to_route('dex.show', $catalog);
    }

    public function update(Request $request, Catalog $catalog): RedirectResponse
    {
        $this->authorizeCustom($request, $catalog);

        $catalog->update($this->validated($request, $catalog));

        return back();
    }

    public function addItem(Request $request, Catalog $catalog): RedirectResponse
    {
        $this->authorizeCustom($request, $catalog);

        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:32'],
            'name' => ['required', 'string', 'max:255'],
            'series' => ['nullable', 'string', 'max:255'],
            'series_number' => ['nullable', 'string', 'max:32'],
            'variant_label' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', ...array_slice(DiecastController::photoRule(), 1)],
        ], DiecastController::photoMessages());

        $item = $this->import->addOne($catalog, collect($data)->except('photo')->all(), $request->file('photo'));

        Inertia::flash('toast', $item
            ? ['type' => 'success', 'message' => "#{$item->number} {$item->name} ditambahkan."]
            : ['type' => 'error', 'message' => 'Entri itu sudah ada di dex ini.']);

        return back();
    }

    public function destroyItem(Request $request, CatalogItem $item): RedirectResponse
    {
        $this->authorizeCustom($request, $item->catalog);

        $this->import->deleteItem($item);

        return back();
    }

    public function import(Request $request, Catalog $catalog): RedirectResponse
    {
        $this->authorizeCustom($request, $catalog);

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:5120']], [
            'file.mimes' => 'Pilih file .xlsx atau .csv.',
        ]);

        $file = $request->file('file');
        $result = $this->import->fromSpreadsheet($catalog, $file->getRealPath(), strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv');

        Inertia::flash('toast', isset($result['error'])
            ? ['type' => 'error', 'message' => $result['error']]
            : ['type' => 'success', 'message' => $this->summary($result)]);

        return back();
    }

    /**
     * Read a checklist from screenshots (JSON: the page shows progress while the AI reads).
     */
    public function scan(Request $request, Catalog $catalog, AiVision $ai): JsonResponse
    {
        $this->authorizeCustom($request, $catalog);

        $request->validate([
            'screenshots' => ['required', 'array', 'min:1', 'max:'.CatalogImport::MAX_SCREENSHOTS],
            'screenshots.*' => DiecastController::photoRule(),
        ], [
            'screenshots.required' => 'Pilih minimal satu screenshot.',
            'screenshots.max' => 'Maksimal '.CatalogImport::MAX_SCREENSHOTS.' screenshot sekali baca.',
            ...DiecastController::photoMessages('screenshots.*'),
        ]);

        if (! $ai->isEnabled()) {
            return response()->json(['message' => 'AI belum aktif. Isi API key di Settings → AI Scan.'], 422);
        }

        set_time_limit(150);

        try {
            $result = $this->import->fromScreenshots($catalog, $request->file('screenshots'), $ai);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'added' => $result['added'],
            'skipped' => $result['skipped'],
            'message' => $this->summary($result),
        ]);
    }

    public function fromCollection(Request $request, Catalog $catalog): RedirectResponse
    {
        $this->authorizeCustom($request, $catalog);

        $result = $this->import->fromCollection($catalog);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['added'] || $result['skipped']
            ? $this->summary($result)
            : "Belum ada diecast {$catalog->brand} di koleksimu yang bisa dimasukkan."]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Catalog $catalog = null): array
    {
        return $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:120', Rule::unique('catalogs', 'name')
                ->where('user_id', $request->user()->id)
                ->where('source', 'custom')
                ->ignore($catalog?->id)],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
        ], [
            'name.unique' => 'Kamu sudah punya dex dengan nama itu.',
        ], [
            'brand' => 'merek',
            'name' => 'nama dex',
            'year' => 'tahun',
        ]);
    }

    /**
     * @param  array{added: int, skipped: int}  $result
     */
    private function summary(array $result): string
    {
        return "{$result['added']} entri ditambahkan".($result['skipped'] ? ", {$result['skipped']} dilewati (sudah ada / kosong)." : '.');
    }

    private function authorizeCustom(Request $request, Catalog $catalog): void
    {
        abort_unless($catalog->user_id === $request->user()->id, 404);
        abort_unless($catalog->isCustom(), 403, 'Daftar dari wiki diubah lewat sinkron, bukan manual.');
    }
}
