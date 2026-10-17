<?php

namespace App\Actions\Review;

use App\Domain\Access\ReviewAccess;
use App\Jobs\RescoreApplication;
use App\Models\Application;
use App\Models\User;

final class RequestRescore
{
    public function handle(Application $application, User $actor): void
    {
        $application->refresh();
        abort_unless(ReviewAccess::manage($actor, $application), 403);
        abort_unless($application->job?->current_criteria_version_id !== null, 422);
        activity('review')->causedBy($actor)->performedOn($application)->event('rescore.requested')->log('rescore.requested');
        RescoreApplication::dispatch($application->id)->afterCommit();
    }
}
