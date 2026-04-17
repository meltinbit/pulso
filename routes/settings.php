<?php

use App\Http\Controllers\Settings\GoogleSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SnapshotSettingsController;
use App\Http\Controllers\Settings\TelegramSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');

    Route::get('settings/google', [GoogleSettingsController::class, 'edit'])->name('settings.google');
    Route::put('settings/google', [GoogleSettingsController::class, 'update'])->name('settings.google.update');

    Route::get('settings/snapshots', [SnapshotSettingsController::class, 'edit'])->name('settings.snapshots');
    Route::put('settings/snapshots', [SnapshotSettingsController::class, 'update'])->name('settings.snapshots.update');

    Route::get('settings/telegram', [TelegramSettingsController::class, 'edit'])->name('settings.telegram');
    Route::put('settings/telegram', [TelegramSettingsController::class, 'update'])->name('settings.telegram.update');
    Route::post('settings/telegram/test', [TelegramSettingsController::class, 'test'])->name('settings.telegram.test');
});
