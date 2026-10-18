<?php

namespace App\Jobs;

use App\Actions\Scoring\EvaluateApplication;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use RuntimeException;
use Throwable;

class ScoreApplication implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    // Defaults also cover jobs serialized before these options were introduced.
    public ?string $rescoreRequest = null;

    public bool $explain = true;

    public function __construct(public readonly int $applicationId, public readonly ?int $versionId = null, ?string $rescoreRequest = null, bool $explain = true)
    {
        $this->rescoreRequest = $rescoreRequest;
        $this->explain = $explain;
        $this->onQueue('scoring');
    }

    public function uniqueId(): string
    {
        return 'score:'.$this->applicationId.':'.($this->versionId ?? 'current');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('score:'.$this->applicationId))->shared()->dontRelease()->expireAfter(180)];
    }

    public function handle(EvaluateApplication $evaluator): void
    {
        try {
            $evaluator->handle($this->applicationId, $this->versionId, $this->rescoreRequest, $this->explain);
        } catch (Throwable) {
            throw new RuntimeException('score_failed');
        }
    }
}
