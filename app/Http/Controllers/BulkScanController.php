<?php

namespace App\Http\Controllers;

use App\Exceptions\ScanQuotaException;
use App\Http\Requests\DiecastRequest;
use App\Models\Diecast;
use App\Models\ScanItem;
use App\Services\CollectionMatcher;
use App\Services\DiecastScanner;
use App\Services\DiecastWriter;
use App\Services\PhotoStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Bulk scan: upload many photos, the browser asks the AI to read them one by one, the user reviews and saves.
 * Processing is driven by the open page (no queue worker needed on Laragon).
 */
class BulkScanController extends Controller
{
    public function __construct(
        private PhotoStorage $photos,
        private DiecastScanner $scanner,
        private CollectionMatcher $matcher,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('diecasts/bulk', [
            'items' => $user->scanItems()
                ->where('source', 'bulk')
                ->whereIn('status', ['pending', 'done', 'failed'])
                ->oldest('id')
                ->get()
                ->map(fn (ScanItem $item) => $this->present($request, $item)),
            'suggestions' => [
                'purchase_place' => $this->distinct($request, 'purchase_place'),
                'storage_location' => $this->distinct($request, 'storage_location'),
                'tags' => $user->tags()->orderBy('name')->pluck('name'),
            ],
            'scanEnabled' => $this->scanner->isEnabled(),
        ]);
    }

    /**
     * Add one photo to the queue.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate(['photo' => DiecastController::photoRule()], DiecastController::photoMessages());

        try {
            $path = $this->photos->store($request->file('photo'), PhotoStorage::INBOX_DIR);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $item = $request->user()->scanItems()->create(['source' => 'bulk', 'status' => 'pending', 'photo_path' => $path]);

        return response()->json($this->present($request, $item));
    }

    /**
     * Let the AI read one queued photo.
     */
    public function process(Request $request, ScanItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);

        if (! $this->scanner->isEnabled()) {
            return response()->json(['message' => 'Scan AI belum aktif. Isi API key di Settings → AI Scan.'], 422);
        }

        if ($item->status === 'pending' || $item->status === 'failed') {
            set_time_limit(120);

            try {
                $item->update(['status' => 'done', 'fields' => $this->scanner->scan([$item->photo_path]), 'error' => null]);
            } catch (ScanQuotaException $e) {
                // Leave it pending; the page waits and retries.
                return response()->json(['message' => $e->getMessage(), 'retry_after' => 60], 429);
            } catch (RuntimeException $e) {
                $item->update(['status' => 'failed', 'error' => $e->getMessage()]);
            }
        }

        return response()->json($this->present($request, $item));
    }

    /**
     * Save a reviewed item to the collection, with the user's quick edits and shared defaults.
     */
    public function save(Request $request, ScanItem $item, DiecastWriter $writer): JsonResponse
    {
        $this->authorizeItem($request, $item);
        abort_if($item->status === 'saved', 422, 'Sudah disimpan.');

        $data = Validator::make(
            [
                'rarity' => 'regular',
                'packaging' => 'carded',
                'status' => 'owned',
                'quantity' => 1,
                ...array_filter($item->fields ?? [], fn ($value) => $value !== null),
                ...$request->except(['photos', 'scan_item_id']),
                'ai_scanned' => $item->fields !== null,
            ],
            (new DiecastRequest)->rules(),
            [],
            (new DiecastRequest)->attributes(),
        )->validate();

        $diecast = $writer->save(
            $request->user(),
            null,
            collect($data)->only((new Diecast)->getFillable())->all(),
            [$item->photo_path],
            $data['tags'] ?? [],
        );

        $item->update(['status' => 'saved', 'diecast_id' => $diecast->id]);

        return response()->json(['id' => $item->id, 'diecast_id' => $diecast->id, 'name' => $diecast->name]);
    }

    public function destroy(Request $request, ScanItem $item): JsonResponse
    {
        $this->authorizeItem($request, $item);

        if ($item->status !== 'saved') {
            $this->photos->delete($item->photo_path);
        }

        $item->delete();

        return response()->json(['deleted' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Request $request, ScanItem $item): array
    {
        return [
            'id' => $item->id,
            'status' => $item->status,
            'photo_url' => $item->photo_url,
            'fields' => $item->fields,
            'error' => $item->error,
            'matches' => $item->status === 'done' && $item->fields
                ? $this->matcher->find($request->user(), $item->fields, 3)
                : [],
        ];
    }

    private function authorizeItem(Request $request, ScanItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<int, string>
     */
    private function distinct(Request $request, string $column): array
    {
        return $request->user()->diecasts()
            ->setEagerLoads([])
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
