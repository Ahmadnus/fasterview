<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|==============================================================================
| >>> SET THE SYNC SCHEDULE HERE <<<
|==============================================================================
|
| This is the one place to adjust when occupancy data gets pulled. Line it up
| with Power BI's own refresh timing — the sync triggers a refresh itself and
| then waits for it, so schedule it a little BEFORE you want the data ready.
|
| CURRENT SETTING: every day at 06:00 Dubai time.
|
| Pick the frequency you want and swap the line below. Examples:
|
|   ->dailyAt('06:00')            once a day at 06:00
|   ->twiceDailyAt(6, 18, 0)      06:00 and 18:00
|   ->hourly()                    every hour
|   ->everySixHours()             every 6 hours
|   ->weeklyOn(1, '06:00')        Mondays at 06:00
|
| TIMEZONE: the app timezone (config/app.php) is UTC, and this machine's clock
| is on European time — so "06:00" on its own would be ambiguous and would
| actually fire at 10:00 in Dubai. The ->timezone() line below pins it to Dubai
| business hours explicitly. Change that line, not the app timezone, if you want
| a different reference (e.g. 'Europe/Berlin' for this machine's local clock).
|
| To make the scheduler actually fire, add ONE Windows Task Scheduler entry
| that runs every minute:
|
|   Program:   C:\xampp\php\php.exe
|   Arguments: artisan schedule:run
|   Start in:  D:\laravel\faster-occupancy-tracker
|
| Note: the machine must be awake at 06:00 for this to run. In Task Scheduler,
| tick "Run task as soon as possible after a scheduled start is missed".
|
| To run it by hand at any time:  php artisan powerbi:sync
|
*/

// NOTE ON --skip-refresh:
// Triggering a Power BI refresh as the service principal currently fails with
// ModelRefreshFailed_CredentialsNotSpecified — the dataset's data source
// credentials are not bound to the service principal. So this job reads the
// dataset as Power BI's own scheduled refresh last left it, which works today
// and still gives a dated daily snapshot.
// Once the credentials are configured in Power BI, drop --skip-refresh to have
// the sync trigger its own refresh and wait for it.
Schedule::command('powerbi:sync --skip-refresh')
    // 12:30 Dubai — sits just after Power BI's own refresh, which the
    // `Last Update` table shows landing around 12:01 Dubai each day.
    ->dailyAt('12:30')              // <-- CHANGE THE TIMING HERE
    ->timezone('Asia/Dubai')        // <-- and the reference timezone HERE
    ->withoutOverlapping()          // never let two syncs run at once
    ->appendOutputTo(storage_path('logs/powerbi-sync.log'));
