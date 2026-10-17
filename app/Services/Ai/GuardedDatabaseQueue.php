<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiException;
use App\Domain\Ai\DispatchContext;
use App\Models\AiWorkItem;
use App\Services\Settings\SettingsRepository;
use Illuminate\Queue\DatabaseQueue;

/** Gate BEFORE parent::pop reserves a row and increments attempts. */
class GuardedDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        if ($this->getQueue($queue) === 'ai') {
            $reason = null;
            try {
                app(DispatchGuard::class)->check(new DispatchContext, 'health');
            } catch (AiDeferred|AiException) {
                $reason = 'awaiting_service';
            }
            $driver = (string) config('ai-pipeline.driver');
            if (! app(CircuitBreaker::class)->canConsume($driver)) {
                $reason = 'awaiting_service';
            }
            if ($driver === 'cloud' && app(CostMeter::class)->spentUnits(now()->format('Y-m')) >= (int) (app(SettingsRepository::class)->float('ai.monthly_cap_egp') * 10000)) {
                $reason = 'awaiting_budget';
            }
            if ($reason !== null) {
                AiWorkItem::query()->where('state', 'queued')->update(['reason' => $reason]);

                return null;
            }
        }

        $originalRetryAfter = $this->retryAfter;
        if ($this->getQueue($queue) === 'ai') {
            $this->retryAfter = max((int) $this->retryAfter, (int) config('ai-pipeline.timeout') + 180);
        }
        try {
            return parent::pop($queue);
        } finally {
            $this->retryAfter = $originalRetryAfter;
        }
    }
}
