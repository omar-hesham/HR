<?php

namespace App\Services\Operations;

use App\Jobs\NotifyOperations;

final class OperationsAlerts
{
    public function record(string $code): void
    {
        activity('operations')->event($code)->log($code);
        NotifyOperations::dispatch($code)->afterCommit();
    }
}
