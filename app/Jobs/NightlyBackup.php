<?php

namespace App\Jobs;

use App\Services\Operations\Backups;
use App\Services\Operations\OperationsAlerts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

final class NightlyBackup implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 1800;

    /** @return list<int> */
    public function backoff(): array
    {
        return [300];
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('nightly-backup'))->expireAfter(2400)];
    }

    public function handle(Backups $backups): void
    {
        $backups->create();
    }

    public function failed(?Throwable $exception): void
    {
        app(OperationsAlerts::class)->record('backup.failed_permanently');
    }
}
