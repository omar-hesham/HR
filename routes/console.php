<?php

use App\Jobs\MonitorMailboxSilence;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// M1-P0-01: poll every 5 minutes (each job is overlap-locked per mailbox).
Schedule::command('recruit:poll-mailboxes')->everyFiveMinutes();

// Brief §7: "no mail received in N hours" alert (N in settings, per-mailbox override).
Schedule::job(new MonitorMailboxSilence)->hourly();
