<?php

namespace App\Console\Commands;

use App\Services\Operations\GoLiveCheck;
use Illuminate\Console\Command;

class GoLive extends Command
{
    protected $signature = 'recruit:go-live-check {--json}';

    public function handle(GoLiveCheck $check): int
    {
        $checks = $check->checks();
        if ($this->option('json')) {
            $this->line(json_encode($checks, JSON_THROW_ON_ERROR));
        } else {
            foreach ($checks as $key => $status) {
                $this->line(__('ops.checks.'.str_replace('.', '_', $key)).': '.__('ops.check_status.'.$status));
            }
        }

        return count(array_filter($checks, fn (string $status): bool => in_array($status, ['missing', 'warning'], true))) === 0 ? self::SUCCESS : self::FAILURE;
    }
}
