<?php

use App\Jobs\CheckGoogleConnections;
use App\Jobs\GenerateDailySnapshots;
use App\Jobs\RunAiVisibilityChecks;
use App\Jobs\SyncAdSenseData;
use App\Jobs\SyncBingWebmasterData;
use App\Jobs\SyncSearchConsoleData;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new CheckGoogleConnections)->hourly()->withoutOverlapping();
Schedule::job(new GenerateDailySnapshots)->hourlyAt(0)->withoutOverlapping();
Schedule::job(new SyncSearchConsoleData)->dailyAt('05:00')->withoutOverlapping();
Schedule::job(new SyncAdSenseData)->everyThreeHours()->withoutOverlapping();
Schedule::job(new SyncBingWebmasterData)->dailyAt('06:00')->withoutOverlapping();
Schedule::job(new RunAiVisibilityChecks)->weeklyOn(1, '07:00')->withoutOverlapping();
Schedule::command('model:prune')->daily()->at('03:00');
