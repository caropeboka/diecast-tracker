<?php

namespace App\Http\Controllers;

use App\Http\Requests\DiecastRequest;
use App\Models\Diecast;
use App\Models\ScanItem;
use App\Services\CollectionMatcher;
use App\Services\DiecastScanner;
use App\Services\DiecastWriter;
use App\Services\PhotoStorage;
use App\Services\SightingLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * "Do I already have this?" — snap a diecast in the store and see straight away whether it's in the collection.
 */
class CheckController extends Controller
{
    public function __construct(
        private PhotoStorage $photos,
        private DiecastScanner $scanner,
        private CollectionMatcher $matcher,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('diecasts/check', [
            'scanEnabled' => $this->scanner->isEnabled(),
            'places' => $request->user()->knownPlaces(),
        ]);
    }

    /**
     * "Ditemui": log that the checked diecast was seen here. With diecast_id the sighting goes on that
     * existing entry (the photo becomes the sighting's photo); without it a new "spotted" diecast is created.
     */
    public function spot(Request $request, ScanItem $item, DiecastWriter $writer, SightingLog $sightings): JsonResponse
    {
        abort_unless($item->user_id === $request->user()->id, 404);
        abort_if($item->status === 'saved', 422, 'Sudah dicatat.');

        $data = $request->validate([
            'diecast_id' => ['nullable', 'integer'],
            ...SightingLog::rules(),
        ]);
        $sighting = collect($data)->only(['place', 'latitude', 'longitude', 'seen_on', 'price', 'notes'])->all();

        if (isset($data['diecast_id'])) {
            $diecast = $request->user()->diecasts()->findOrFail($data['diecast_id']);
            $sightings->record($diecast, $sighting, $item->photo_path);
        } else {
            $attributes = Validator::make(
                [
                    'rarity' => 'regular',
                    'packaging' => 'carded',
                    'quantity' => 1,
                    ...array_filter($item->fields ?? [], fn ($value) => $value !== null),
                    'status' => 'spotted',
                    'ai_scanned' => true,
                ],
                collect((new DiecastRequest)->rules())->only([...Diecast::SCAN_FIELDS, 'status', 'quantity', 'ai_scanned'])->all(),
                ['name.required' => 'AI tidak menemukan nama diecast ini — tambahkan lewat form.'],
            )->validate();

            $diecast = $writer->save($request->user(), null, $attributes, [$item->photo_path], []);
            $sightings->record($diecast, $sighting);
        }

        $item->update(['status' => 'saved', 'diecast_id' => $diecast->id]);

        return response()->json(
            $diecast->loadCount('sightings')->loadMin('sightings', 'price'),
        );
    }

    /**
     * Upload + identify in one go, then look the result up in the collection.
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['photo' => DiecastController::photoRule()], DiecastController::photoMessages());
        $this->pruneOldChecks($request);

        if (! $this->scanner->isEnabled()) {
            return response()->json(['message' => 'Scan AI belum aktif. Isi API key di Settings → AI Scan.'], 422);
        }

        try {
            $path = $this->photos->store($request->file('photo'), PhotoStorage::INBOX_DIR);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $item = $request->user()->scanItems()->create(['source' => 'check', 'status' => 'pending', 'photo_path' => $path]);

        set_time_limit(120);

        try {
            $item->update(['status' => 'done', 'fields' => $this->scanner->scan([$path])]);
        } catch (RuntimeException $e) {
            $item->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }

        return response()->json([
            'id' => $item->id,
            'status' => $item->status,
            'photo_url' => $item->photo_url,
            'fields' => $item->fields,
            'error' => $item->error,
            'matches' => $item->fields ? $this->matcher->find($request->user(), $item->fields) : [],
        ]);
    }

    /**
     * One-tap actions on a match: bought another one (+1), or got a wishlist item.
     */
    public function quick(Request $request, Diecast $diecast): JsonResponse
    {
        abort_unless($diecast->user_id === $request->user()->id, 404);

        $action = $request->validate(['action' => ['required', 'in:increment,acquire']])['action'];

        if ($action === 'increment') {
            $diecast->increment('quantity');
        } else {
            $diecast->update(['status' => 'owned', 'purchase_date' => now()->toDateString()]);
        }

        return response()->json($diecast->fresh());
    }

    /**
     * Checks that never became a diecast are only useful for a day.
     */
    private function pruneOldChecks(Request $request): void
    {
        $request->user()->scanItems()
            ->where('source', 'check')
            ->where('status', '!=', 'saved')
            ->where('created_at', '<', now()->subDay())
            ->get()
            ->each(function (ScanItem $item) {
                $this->photos->delete($item->photo_path);
                $item->delete();
            });
    }
}
