<?php

namespace App\Services\Privacy;

use App\Domain\Privacy\RetentionTrack;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class HiredHandover
{
    public function record(User $actor, Application $application, string $reason): string
    {
        abort_unless(app(PrivacyAccess::class)->approver($actor) && $actor->can('view', $application), 403);
        abort_unless($application->retention_track === RetentionTrack::HiredHandover && $application->handed_over_at === null && trim($reason) !== '', 422);
        app(PrivacyAccess::class)->subject($actor, $application->candidate);
        $payload = app(SubjectData::class)->payload($application->candidate);
        $id = (string) Str::uuid();
        $path = 'handover-exports/'.$id.'.bin';
        DB::table('privacy_artifacts')->insert(['id' => $id, 'application_id' => $application->id, 'path' => $path, 'expires_at' => now()->addHours(24)]);
        Storage::disk('private')->put($path, app(ContentEncrypter::class)->encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $application->forceFill(['handed_over_at' => now()])->save();
        $application->forceFill(['retention_due_at' => app(RetentionPolicy::class)->due($application)])->save();
        activity('privacy')->causedBy($actor)->performedOn($application)->event('hired.handed_over')->withProperties(['purpose' => 'employee_file_handover', 'reason_enc' => encrypt($reason)])->log('hired.handed_over');

        return $id;
    }

    public function download(User $actor, string $id): string
    {
        abort_unless(app(PrivacyAccess::class)->approver($actor), 403);
        $artifact = DB::table('privacy_artifacts')->where('id', $id)->whereNotNull('application_id')->first();
        abort_unless($artifact !== null, 404);
        $application = Application::query()->findOrFail($artifact->application_id);
        abort_unless($actor->can('view', $application), 403);
        app(PrivacyAccess::class)->subject($actor, $application->candidate);
        abort_if($artifact->purged_at !== null || now()->gte($artifact->expires_at), 410);
        activity('privacy')->causedBy($actor)->performedOn($application)->event('handover.downloaded')->withProperties(['purpose' => 'employee_file_handover'])->log('handover.downloaded');

        return app(ContentEncrypter::class)->decryptString(Storage::disk('private')->get($artifact->path));
    }
}
