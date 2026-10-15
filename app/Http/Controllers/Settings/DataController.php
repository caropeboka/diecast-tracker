<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\CollectionBackup;
use App\Services\CollectionSpreadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DataController extends Controller
{
    public function __construct(
        private CollectionSpreadsheet $spreadsheet,
        private CollectionBackup $backup,
    ) {}

    /**
     * Show the backup / export / import page.
     */
    public function edit(Request $request): Response
    {
        $diecasts = $request->user()->diecasts()->setEagerLoads([]);

        return Inertia::render('settings/data', [
            'counts' => [
                'diecasts' => (clone $diecasts)->count(),
                'photos' => $request->user()->diecasts()->setEagerLoads([])->withCount('photos')->get()->sum('photos_count'),
            ],
            'zipAvailable' => class_exists(\ZipArchive::class),
            'result' => $request->session()->get('import_result'),
        ]);
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        if (! class_exists(\ZipArchive::class)) {
            return back()->withErrors(['backup' => 'Ekstensi PHP "zip" belum aktif. Aktifkan lewat Laragon → PHP → Extensions → zip.']);
        }

        $path = $this->tempFile('xlsx');
        $this->spreadsheet->export($request->user(), $path, $request->boolean('template'));

        $name = $request->boolean('template') ? 'template-koleksi-diecast.xlsx' : 'koleksi-diecast-'.now()->format('Y-m-d').'.xlsx';

        return response()->download($path, $name)->deleteFileAfterSend();
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:xlsx,csv'],
        ], [
            'file.extensions' => 'File harus .xlsx atau .csv.',
        ]);

        $file = $request->file('file');
        $result = $this->spreadsheet->import($request->user(), $file->getRealPath(), strtolower($file->getClientOriginalExtension()));

        return $this->finish($result, 'import');
    }

    public function backup(Request $request): BinaryFileResponse|RedirectResponse
    {
        $path = $this->tempFile('zip');

        try {
            $this->backup->create($request->user(), $path);
        } catch (RuntimeException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return response()->download($path, 'backup-diecast-'.now()->format('Y-m-d').'.zip')->deleteFileAfterSend();
    }

    public function restore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'backup' => ['required', 'file', 'max:2097152', 'extensions:zip'],
            'mode' => ['required', 'in:merge,replace'],
            'confirm' => ['required_if:mode,replace', 'accepted_if:mode,replace'],
        ], [
            'backup.extensions' => 'File backup harus .zip.',
            'confirm.required_if' => 'Centang konfirmasi dulu — koleksi sekarang akan diganti.',
            'confirm.accepted_if' => 'Centang konfirmasi dulu — koleksi sekarang akan diganti.',
        ]);

        set_time_limit(600);

        try {
            $result = $this->backup->restore($request->user(), $request->file('backup')->getRealPath(), $data['mode'] === 'replace');
        } catch (RuntimeException $e) {
            return back()->withErrors(['backup' => $e->getMessage()]);
        }

        return $this->finish($result, 'restore');
    }

    /**
     * @param  array{imported: int, skipped: array<int, string>}  $result
     */
    private function finish(array $result, string $type): RedirectResponse
    {
        Inertia::flash('toast', [
            'type' => $result['imported'] > 0 ? 'success' : 'error',
            'message' => "{$result['imported']} diecast berhasil ".($type === 'restore' ? 'dipulihkan.' : 'diimport.'),
        ]);

        return to_route('data.edit')->with('import_result', [
            'type' => $type,
            'imported' => $result['imported'],
            'skipped' => array_slice($result['skipped'], 0, 50),
            'skipped_total' => count($result['skipped']),
        ]);
    }

    private function tempFile(string $extension): string
    {
        $dir = storage_path('app/private/exports');

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir.'/'.uniqid('diecast-', true).'.'.$extension;
    }
}
