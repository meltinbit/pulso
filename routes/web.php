<?php

use App\Http\Controllers\AudienceReportController;
use App\Http\Controllers\ContentReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FunnelController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\PropertyController;
use App\Http\Controllers\SnapshotController;
use App\Http\Controllers\TrafficReportController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('api/realtime/{property}', [DashboardController::class, 'realtime'])->name('api.realtime');

    Route::get('auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');
    Route::delete('auth/google/{connection}', [GoogleAuthController::class, 'disconnect'])->name('google.disconnect');

    Route::get('reports/traffic', [TrafficReportController::class, 'index'])->name('reports.traffic');
    Route::get('reports/content', [ContentReportController::class, 'index'])->name('reports.content');
    Route::get('reports/audience', [AudienceReportController::class, 'index'])->name('reports.audience');

    Route::get('funnels', [FunnelController::class, 'index'])->name('funnels.index');
    Route::get('funnels/create', [FunnelController::class, 'create'])->name('funnels.create');
    Route::post('funnels', [FunnelController::class, 'store'])->name('funnels.store');
    Route::get('funnels/{funnel}', [FunnelController::class, 'show'])->name('funnels.show');
    Route::delete('funnels/{funnel}', [FunnelController::class, 'destroy'])->name('funnels.destroy');

    Route::get('snapshots', [SnapshotController::class, 'index'])->name('snapshots.index');
    Route::post('snapshots/generate', [SnapshotController::class, 'generate'])->name('snapshots.generate');

    Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
    Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
    Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
    Route::delete('properties/{property}', [PropertyController::class, 'destroy'])->name('properties.destroy');
    Route::post('properties/switch', [PropertyController::class, 'switch'])->name('properties.switch');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
