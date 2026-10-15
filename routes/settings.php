<?php

use App\Http\Controllers\Settings\AiController;
use App\Http\Controllers\Settings\DataController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/data', [DataController::class, 'edit'])->name('data.edit');
    Route::get('settings/data/export', [DataController::class, 'export'])->name('data.export');
    Route::post('settings/data/import', [DataController::class, 'import'])->name('data.import');
    Route::get('settings/data/backup', [DataController::class, 'backup'])->name('data.backup');
    Route::post('settings/data/restore', [DataController::class, 'restore'])->name('data.restore');
});

Route::middleware(['auth', 'verified', 'can:admin'])->group(function () {
    Route::get('settings/ai', [AiController::class, 'edit'])->name('ai.edit');
    Route::put('settings/ai', [AiController::class, 'update'])->name('ai.update');
    Route::post('settings/ai/models', [AiController::class, 'models'])
        ->middleware('throttle:20,1')
        ->name('ai.models');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
