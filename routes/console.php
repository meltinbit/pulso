<?php

use App\Jobs\GenerateDailySnapshots;
use App\Jobs\RefreshAnalyticsCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new RefreshAnalyticsCache)->dailyAt('02:00')->withoutOverlapping();
Schedule::job(new GenerateDailySnapshots)->dailyAt('07:00')->withoutOverlapping();
