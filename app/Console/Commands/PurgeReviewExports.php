<?php

namespace App\Console\Commands;

use App\Services\Review\ExportApplications;
use Illuminate\Console\Command;

final class PurgeReviewExports extends Command
{
    protected $signature = 'recruit:purge-review-exports';

    protected $description = 'Remove expired encrypted review-export bytes only.';

    public function handle(ExportApplications $exports): int
    {
        $this->info((string) $exports->purgeExpired());

        return self::SUCCESS;
    }
}
