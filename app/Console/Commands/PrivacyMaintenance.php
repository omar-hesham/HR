<?php

namespace App\Console\Commands;

use App\Jobs\RetentionSweep;
use App\Services\Operations\SystemHealthSnapshot;
use App\Services\Privacy\DeletionLedger;
use App\Services\Privacy\PrivacyRequests;
use Illuminate\Console\Command;

class PrivacyMaintenance extends Command
{
    protected $signature = 'recruit:privacy-maintenance {--sweep}';

    public function handle(PrivacyRequests $requests, SystemHealthSnapshot $health): int
    {
        app(DeletionLedger::class)->publish();
        $requests->purgeExpired();
        $health->monitor();
        if ($this->option('sweep')) {
            app()->call([new RetentionSweep, 'handle']);
        }

        return self::SUCCESS;
    }
}
