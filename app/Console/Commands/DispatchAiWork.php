<?php

namespace App\Console\Commands;

use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\RecoverScoring;
use Illuminate\Console\Command;

class DispatchAiWork extends Command
{
    protected $signature = 'recruit:dispatch-ai';

    protected $description = 'Recheck durable waiting AI work without consuming provider attempts.';

    public function handle(AiWorkCoordinator $coordinator): int
    {
        $coordinator->recover();
        app(RecoverScoring::class)->dispatch();

        return self::SUCCESS;
    }
}
