<?php

namespace App\Jobs;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

/** Invoked directly by scheduler outside the paused ai queue. */
final class ProbeAiHealth
{
    public function handle(AiProvider $provider, CircuitBreaker $breaker, AiWorkCoordinator $coordinator): void
    {
        $driver = (string) config('ai-pipeline.driver');
        Cache::lock('ai-probe:'.$driver, 30)->get(function () use ($provider, $breaker, $coordinator, $driver): void {
            if (! $breaker->probeDue($driver)) {
                return;
            }
            try {
                if ($provider->health()->healthy()) {
                    if ($breaker->state($driver) === 'open') {
                        $breaker->recoveredHealth($driver);
                    } else {
                        $breaker->success($driver);
                    }
                    $coordinator->recover();
                } else {
                    $breaker->failure($driver);
                }
            } catch (AiDeferred) { /* Cap/approval/pipeline waits are not outages. */
            } catch (AiException $e) {
                if ($e->error !== AiError::BudgetExceeded) {
                    $breaker->failure($driver);
                }
            }
        });
    }
}
