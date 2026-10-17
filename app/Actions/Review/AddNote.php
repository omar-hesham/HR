<?php

namespace App\Actions\Review;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Domain\Applications\ApplicationStatus;
use App\Models\Application;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AddNote
{
    public function handle(Application $application, User $actor, string $body): Note
    {
        return DB::transaction(function () use ($application, $actor, $body): Note {
            $application = Application::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->isUsable() && JobScope::canViewApplication($actor, $application)
                && $actor->hasPermission(Permission::StatusChange) && $application->status !== ApplicationStatus::Deleted, 403);
            abort_if(trim($body) === '' || mb_strlen($body) > 10000, 422);
            $note = $application->notes()->create(['user_id' => $actor->id, 'body_enc' => $body]);
            $application->forceFill(['last_activity_at' => now()])->save();
            activity('review')->causedBy($actor)->performedOn($application)->event('note.created')->withProperties(['note_id' => $note->id])->log('note.created');

            return $note;
        });
    }
}
