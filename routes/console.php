<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Artisan::command('inspire', function () {
//     $this->comment(Inspiring::quote());
// })->purpose('Display an inspiring quote')->hourly();

Schedule::command('app:data-cleanup')->daily();
Schedule::command('app:sync-inv-query')->everyFiveMinutes();

// Uptime monitoring - check every minute
Schedule::command('uptime:check')->everyMinute();

// BPM scheduled commands
Schedule::command('app:ins-bpm-reset')->dailyAt('06:04');
Schedule::command('app:ins-bpm-poll')->everyMinute();

// DWP scheduled commands
Schedule::command('app:ins-dwp-reset')->dailyAt('07:00');
Schedule::command('app:ins-dwp-time-chart')->everyThirtyMinutes();