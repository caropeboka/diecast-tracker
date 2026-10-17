<?php

use App\Http\Controllers\BulkScanController;
use App\Http\Controllers\CheckController;
use App\Http\Controllers\CustomDexController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DexController;
use App\Http\Controllers\DiecastController;
use App\Http\Controllers\ExploreController;
use App\Http\Controllers\PriceCheckController;
use App\Http\Controllers\SightingController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Add/edit form helpers
    Route::post('diecasts/photos', [DiecastController::class, 'uploadPhoto'])
        ->middleware('throttle:120,1')
        ->name('diecasts.photos.store');
    Route::post('diecasts/scan', [DiecastController::class, 'scan'])
        ->middleware('throttle:30,1')
        ->name('diecasts.scan');

    // Bulk scan queue
    Route::get('scan/bulk', [BulkScanController::class, 'index'])->name('bulk.index');
    Route::post('scan/bulk', [BulkScanController::class, 'upload'])->middleware('throttle:240,1')->name('bulk.upload');
    Route::post('scan/bulk/{item}/process', [BulkScanController::class, 'process'])->middleware('throttle:60,1')->name('bulk.process');
    Route::post('scan/bulk/{item}/save', [BulkScanController::class, 'save'])->name('bulk.save');
    Route::delete('scan/bulk/{item}', [BulkScanController::class, 'destroy'])->name('bulk.destroy');

    // In-store "do I have this?" check
    Route::get('scan/check', [CheckController::class, 'index'])->name('check.index');
    Route::post('scan/check', [CheckController::class, 'check'])->middleware('throttle:30,1')->name('check.store');
    Route::post('scan/check/{diecast}/quick', [CheckController::class, 'quick'])->name('check.quick');
    Route::post('scan/check/{item}/spot', [CheckController::class, 'spot'])->name('check.spot');

    // Map of explored places + GPS → place name
    Route::get('peta', [ExploreController::class, 'index'])->name('explore.index');
    // The picker looks up a name each time the map stops moving; OpenStreetMap allows ~1 request/second.
    Route::get('places/lookup', [ExploreController::class, 'lookup'])->middleware('throttle:60,1')->name('places.lookup');
    Route::get('places/search', [ExploreController::class, 'search'])->middleware('throttle:30,1')->name('places.search');

    // Dex: every release of a line vs. what was seen/owned
    Route::get('dex', [DexController::class, 'index'])->name('dex.index');
    Route::get('dex/cocokkan', [DexController::class, 'matches'])->name('dex.matches');
    Route::post('dex/sync', [DexController::class, 'sync'])->middleware('throttle:40,1')->name('dex.sync');
    Route::post('dex/images', [DexController::class, 'images'])->middleware('throttle:120,1')->name('dex.images');
    Route::get('dex/{catalog}', [DexController::class, 'show'])->whereNumber('catalog')->name('dex.show');
    Route::delete('dex/{catalog}', [DexController::class, 'destroy'])->whereNumber('catalog')->name('dex.destroy');
    Route::post('diecasts/{diecast}/dex', [DexController::class, 'link'])->name('dex.link');
    // Own dex lists (brands without a wiki list)
    Route::post('dex/custom', [CustomDexController::class, 'store'])->name('dex.custom.store');
    Route::patch('dex/{catalog}', [CustomDexController::class, 'update'])->whereNumber('catalog')->name('dex.custom.update');
    Route::post('dex/{catalog}/items', [CustomDexController::class, 'addItem'])->whereNumber('catalog')->name('dex.custom.items.store');
    Route::delete('dex/items/{item}', [CustomDexController::class, 'destroyItem'])->name('dex.custom.items.destroy');
    Route::post('dex/{catalog}/import', [CustomDexController::class, 'import'])->whereNumber('catalog')->name('dex.custom.import');
    Route::post('dex/{catalog}/scan', [CustomDexController::class, 'scan'])->whereNumber('catalog')->middleware('throttle:20,1')->name('dex.custom.scan');
    Route::post('dex/{catalog}/from-collection', [CustomDexController::class, 'fromCollection'])->whereNumber('catalog')->name('dex.custom.from-collection');

    // "Ditemui" sightings
    Route::post('diecasts/{diecast}/sightings', [SightingController::class, 'store'])->name('sightings.store');
    Route::delete('sightings/{sighting}', [SightingController::class, 'destroy'])->name('sightings.destroy');

    // Market price from marketplace screenshots
    Route::post('diecasts/{diecast}/price-checks', [PriceCheckController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('price-checks.store');
    Route::post('price-checks/{check}/apply', [PriceCheckController::class, 'apply'])->name('price-checks.apply');
    Route::delete('price-checks/{check}', [PriceCheckController::class, 'destroy'])->name('price-checks.destroy');

    Route::resource('diecasts', DiecastController::class)->except('show');
});

require __DIR__.'/settings.php';
