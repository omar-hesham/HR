<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Operations\RestoreVerification;
use Illuminate\Console\Command;

class RestoreVerify extends Command
{
    protected $signature = 'recruit:restore-verify {run} {--ledger=}';

    public function handle(RestoreVerification $restore): int
    {
        $root = $restore->verify(BackupRun::query()->findOrFail($this->argument('run')), $this->option('ledger') ?: null);
        $this->info(__('ops.restore_verified', ['path' => $root]));

        return self::SUCCESS;
    }
}
