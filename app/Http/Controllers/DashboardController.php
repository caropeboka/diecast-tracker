<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $owned = $user->diecasts()->setEagerLoads([])->where('status', 'owned');
        $valued = (clone $owned)->whereNotNull('market_value');

        return Inertia::render('dashboard', [
            'stats' => [
                'total_items' => (int) (clone $owned)->sum('quantity'),
                'unique_items' => (clone $owned)->count(),
                'total_spent' => (float) (clone $owned)->sum(DB::raw('COALESCE(purchase_price, 0) * quantity')),
                'total_value' => (float) (clone $valued)->sum(DB::raw('market_value * quantity')),
                // Only items with both prices count towards gain, so missing values don't look like losses.
                'value_gain' => (float) (clone $valued)->whereNotNull('purchase_price')
                    ->sum(DB::raw('(market_value - purchase_price) * quantity')),
                'valued_items' => (clone $valued)->count(),
                'treasure_hunts' => (int) (clone $owned)->whereIn('rarity', ['treasure_hunt', 'super_treasure_hunt'])->sum('quantity'),
                'super_treasure_hunts' => (int) (clone $owned)->where('rarity', 'super_treasure_hunt')->sum('quantity'),
                'wishlist' => $user->diecasts()->setEagerLoads([])->where('status', 'wishlist')->count(),
                'pending_scans' => $user->scanItems()->where('source', 'bulk')->whereIn('status', ['pending', 'done', 'failed'])->count(),
            ],
            'byBrand' => (clone $owned)
                ->selectRaw("COALESCE(brand, 'Tanpa merek') as label, SUM(quantity) as total")
                ->groupBy('label')
                ->orderByDesc('total')
                ->limit(8)
                ->get(),
            'byScale' => (clone $owned)
                ->selectRaw("COALESCE(scale, '?') as label, SUM(quantity) as total")
                ->groupBy('label')
                ->orderByDesc('total')
                ->get(),
            'topGainers' => $user->diecasts()
                ->where('status', 'owned')
                ->whereNotNull('market_value')
                ->whereNotNull('purchase_price')
                ->whereColumn('market_value', '>', 'purchase_price')
                ->orderByRaw('(market_value - purchase_price) DESC')
                ->limit(5)
                ->get(),
            'wishlist' => $user->diecasts()
                ->where('status', 'wishlist')
                ->latest()
                ->limit(5)
                ->get(),
            'recent' => $user->diecasts()->where('status', '!=', 'wishlist')->latest()->limit(6)->get(),
        ]);
    }
}
