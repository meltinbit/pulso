<?php

use App\Http\Controllers\Api\AiCrawlerIngestController;
use Illuminate\Support\Facades\Route;

Route::post('ai-crawlers', AiCrawlerIngestController::class)
    ->middleware('throttle:300,1')
    ->name('api.ai-crawlers');
