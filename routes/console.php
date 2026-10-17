<?php

use App\Jobs\MonitorMailboxSilence;
use App\Jobs\NightlyBackup;
use App\Jobs\ProbeAiHealth;
use App\Jobs\RetentionSweep;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// M1-P0-01: poll every 5 minutes (each job is overlap-locked per mailbox).
Schedule::command('recruit:poll-mailboxes')->everyFiveMinutes();

Schedule::command('recruit:purge-review-exports')->everyMinute()->withoutOverlapping();

// Brief §7: "no mail received in N hours" alert (N in settings, per-mailbox override).
Schedule::job(new MonitorMailboxSilence)->hourly();

// M3 recovery runs outside the paused AI queue.
Schedule::command('recruit:dispatch-ai')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => app()->call([new ProbeAiHealth, 'handle']))->name('probe-ai-health')->everyMinute()->withoutOverlapping(1);

Schedule::job(new RetentionSweep)->dailyAt('02:00')->withoutOverlapping();
Schedule::job(new NightlyBackup)->dailyAt('01:00')->withoutOverlapping();
Schedule::command('recruit:privacy-maintenance')->everyMinute()->withoutOverlapping();
