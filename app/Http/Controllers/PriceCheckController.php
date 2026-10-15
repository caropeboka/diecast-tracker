<?php

namespace App\Http\Controllers;

use App\Models\Diecast;
use App\Models\PriceCheck;
use App\Services\AiVision;
use App\Services\PriceChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Market price from marketplace screenshots: the user searches Tokopedia/Shopee, uploads screenshots,
 * the AI reads the listings and the app computes the median.
 */
class PriceCheckController extends Controller
{
    public function __construct(private PriceChecker $checker) {}

    public function store(Request $request, Diecast $diecast, AiVision $ai): JsonResponse
    {
        abort_unless($diecast->user_id === $request->user()->id, 404);

        $request->validate([
            'screenshots' => ['required', 'array', 'min:1', 'max:'.PriceChecker::MAX_SCREENSHOTS],
            'screenshots.*' => DiecastController::photoRule(),
        ], [
            'screenshots.required' => 'Pilih minimal satu screenshot.',
            'screenshots.max' => 'Maksimal '.PriceChecker::MAX_SCREENSHOTS.' screenshot sekali cek.',
            ...DiecastController::photoMessages('screenshots.*'),
        ]);

        if (! $ai->isEnabled()) {
            return response()->json(['message' => 'AI belum aktif. Isi API key di Settings → AI Scan.'], 422);
        }

        set_time_limit(150);

        try {
            $check = $this->checker->check($diecast, $request->file('screenshots'));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($check);
    }

    public function apply(Request $request, PriceCheck $check): JsonResponse
    {
        $this->authorizeCheck($request, $check);

        $data = $request->validate(['value' => ['nullable', 'integer', 'min:1', 'max:999999999999']]);
        abort_if($check->stats === null && ! isset($data['value']), 422, 'Belum ada harga untuk dipakai.');

        $diecast = $this->checker->apply($check, $data['value'] ?? null);

        return response()->json($diecast->only(['id', 'market_value', 'market_value_source', 'market_value_updated_at']));
    }

    public function destroy(Request $request, PriceCheck $check): JsonResponse
    {
        $this->authorizeCheck($request, $check);

        $this->checker->delete($check);

        return response()->json(['deleted' => true]);
    }

    private function authorizeCheck(Request $request, PriceCheck $check): void
    {
        abort_unless($check->user_id === $request->user()->id, 404);
    }
}
