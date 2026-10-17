<?php

namespace App\Jobs;

use App\Models\AiWorkItem;
use App\Services\Ai\AiWorkExecutor;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

abstract class AiWorkJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout;

    public int $uniqueFor;

    public function __construct(public readonly int $workItemId)
    {
        $this->timeout = (int) config('ai-pipeline.timeout') + 60;
        $this->uniqueFor = $this->timeout + 300;
        $this->onConnection('ai')->onQueue('ai');
    }

    public function uniqueId(): string
    {
        return 'ai-work:'.$this->workItemId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->shared()->dontRelease()->expireAfter($this->timeout + 30)];
    }

    public function handle(AiWorkExecutor $executor): void
    {
        $executor->execute($this->workItemId);
    }

    public function failed(?Throwable $exception): void
    {
        AiWorkItem::query()->whereKey($this->workItemId)->whereNotIn('state', ['done', 'review'])->update(['state' => 'review', 'reason' => 'ProviderUnavailable']);
    }
}
