<?php

namespace App\Console\Commands;

use App\Services\Operations\Backups;
use Illuminate\Console\Command;

class Backup extends Command
{
    protected $signature = 'recruit:backup';

    public function handle(Backups $backups): int
    {
        $run = $backups->create();
        $this->info(__('ops.backup_created', ['id' => $run->id]));

        return self::SUCCESS;
    }
}
