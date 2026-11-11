<?php

use App\Http\Controllers\AdSenseController;
use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\KeywordController;
use App\Http\Controllers\McpLogController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SnapshotController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::get('privacy', function () {
    return Inertia::render('privacy');
})->name('privacy');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('mcp-log', [McpLogController::class, 'index'])->name('mcp-log.index');
    Route::get('annotations', [AnnotationController::class, 'index'])->name('annotations.index');
    Route::delete('annotations/{annotation}', [AnnotationController::class, 'destroy'])->name('annotations.destroy');

    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
    Route::delete('auth/google/{connection}', [GoogleAuthController::class, 'disconnect'])->name('google.disconnect');

    Route::get('keywords', [KeywordController::class, 'index'])->name('keywords.index');
    Route::get('keywords/geo-targets', [KeywordController::class, 'geoTargets'])->name('keywords.geo-targets');

    Route::get('snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
    Route::post('snapshots/generate', [SnapshotController::class, 'generate'])->name('snapshots.generate');

    Route::get('adsense', [AdSenseController::class, 'index'])->name('adsense.index');
    Route::post('adsense/sync', [AdSenseController::class, 'sync'])->name('adsense.sync');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');
    Route::post('properties/switch', [PropertyController::class, 'switch'])->name('properties.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
