<?php

namespace App\Actions\Demo;

use App\Actions\Review\RequestRescore;
use App\Domain\Access\ReviewAccess;
use App\Models\Application;
use App\Models\User;

final readonly class RescoreDemoAiRun
{
    public function __construct(private RequestRescore $rescore) {}

    /** @return array{created:int,skipped:int} */
    public function handle(User $actor): array
    {
        abort_unless(app()->environment('local') && $actor->isUsable() && $actor->can('ai.pipeline.manage'), 403);
        $created = 0;
        $skipped = 0;
        $applications = Application::query()->whereHas('sources', fn ($query) => $query->where('external_reference', 'like', 'demo-ai-%')
            ->orWhereHas('intakeMessage.mailbox', fn ($mailbox) => $mailbox->where('address', 'demo-ai-mail@example.test')));
        foreach ($applications->lazyById() as $application) {
            if (! ReviewAccess::manage($actor, $application) || $application->job?->current_criteria_version_id === null) {
                $skipped++;

                continue;
            }
            $before = $application->current_score_id;
            $this->rescore->handle($application, $actor, synchronously: true);
            $application->refresh();
            if ($application->current_score_id !== null && $application->current_score_id !== $before) {
                $created++;
            } else {
                $skipped++;
            }
        }

        return compact('created', 'skipped');
    }
}
