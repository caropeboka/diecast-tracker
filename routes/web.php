<?php

use App\Http\Controllers\BulkScanController;
use App\Http\Controllers\CheckController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiecastController;
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

    Route::resource('diecasts', DiecastController::class)->except('show');
});

require __DIR__.'/settings.php';
