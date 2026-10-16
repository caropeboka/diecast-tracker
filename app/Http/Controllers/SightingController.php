<?php

namespace App\Http\Controllers;

use App\Models\Diecast;
use App\Models\Sighting;
use App\Services\PhotoStorage;
use App\Services\SightingLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Catatan temuan" on a diecast's page: add or remove the places it was seen.
 */
class SightingController extends Controller
{
    public function __construct(private SightingLog $log) {}

    public function store(Request $request, Diecast $diecast, PhotoStorage $photos): JsonResponse
    {
        abort_unless($diecast->user_id === $request->user()->id, 404);

        $data = $request->validate([
            ...SightingLog::rules(),
            'photo' => ['nullable', ...array_slice(DiecastController::photoRule(), 1)],
        ], DiecastController::photoMessages());

        $photo = null;

        if ($request->hasFile('photo')) {
            try {
                $photo = $photos->store($request->file('photo'));
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json($this->log->record($diecast, $data, $photo));
    }

    public function destroy(Request $request, Sighting $sighting): JsonResponse
    {
        abort_unless($sighting->user_id === $request->user()->id, 404);

        $this->log->delete($sighting);

        return response()->json(['deleted' => true]);
    }
}
