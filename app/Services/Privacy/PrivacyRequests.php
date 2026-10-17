<?php

namespace App\Services\Privacy;

use App\Actions\Privacy\DeleteCandidateData;
use App\Domain\Privacy\DeletionReason;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Review\CorrectionForm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final readonly class PrivacyRequests
{
    public function __construct(private PrivacyAccess $access) {}

    public function register(User $actor, Candidate $candidate, string $type, string $channel, string $details): PrivacyRequest
    {
        $this->access->subject($actor, $candidate);
        abort_unless(in_array($type, ['access', 'rectification', 'erasure'], true) && trim($channel) !== '' && mb_strlen($details) <= 2000 && mb_strlen($channel) <= 500, 422);
        $request = PrivacyRequest::query()->create(['candidate_id' => $candidate->id, 'type' => $type, 'status' => 'registered', 'details_enc' => $details, 'channel_enc' => $channel, 'requested_by' => $actor->id]);
        $this->audit($actor, $request, 'registered');

        return $request;
    }

    public function verify(User $actor, PrivacyRequest $request, string $note): void
    {
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        abort_unless($request->status === 'registered' && trim($note) !== '' && mb_strlen($note) <= 1000, 422);
        $request->forceFill(['verification_enc' => $note, 'verified_at' => now(), 'status' => 'verified'])->save();
        $this->audit($actor, $request, 'verified');
    }

    public function decide(User $actor, PrivacyRequest $request, string $decision, string $reason): void
    {
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        DB::transaction(function () use ($actor, $request, $decision, $reason): void {
            $request = PrivacyRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->status === 'verified' && $request->verified_at !== null && trim($reason) !== '' && in_array($decision, ['approved', 'refused'], true), 422);
            $request->forceFill(['approved_by' => $actor->id, 'decision_enc' => $reason, 'decided_at' => now(), 'status' => $decision])->save();
            $this->audit($actor, $request, $decision);
        });
        $request->refresh();
        if ($decision === 'approved') {
            $this->execute($actor, $request);
        }
    }

    public function execute(User $actor, PrivacyRequest $request): void
    {
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        abort_unless($request->status === 'approved', 422);
        if ($request->type === 'erasure') {
            if ($request->candidate_id !== null) {
                app(DeleteCandidateData::class)->candidate((int) $request->candidate_id, DeletionReason::ErasureRequest);
            }
            app(DeletionLedger::class)->publish();
            app(ErasureFiles::class)->purge();
            $request->refresh()->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $this->audit($actor, $request, 'completed');
        } elseif ($request->type === 'access') {
            $this->exportSubject($actor, $request);
        }
    }

    /** @param array{path:string,value:string,evidence:string,reason:string} $correction */
    public function rectify(User $actor, PrivacyRequest $request, Application $application, array $correction): void
    {
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        abort_unless($request->type === 'rectification' && $request->status === 'approved' && in_array($application->candidate_id, app(IdentityGroup::class)->ids((int) $request->candidate_id), true), 422);
        app(CorrectionForm::class)->handle($application, $actor, $correction['path'], $correction['value'], $correction['evidence'], $correction['reason']);
        $request->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->audit($actor, $request, 'completed');
    }

    public function notify(User $actor, PrivacyRequest $request, string $note): void
    {
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        abort_unless(in_array($request->status, ['completed', 'refused'], true) && $request->getAttribute('notified_at') === null && trim($note) !== '', 422);
        // Erasure keeps the notification fact without retaining a person's address or free text.
        $request->forceFill(['notification_enc' => $request->type === 'erasure' ? 'delivery_recorded_via_approved_channel' : $note, 'notified_at' => now()])->save();
        $this->audit($actor, $request, 'notified');
    }

    private function exportSubject(User $actor, PrivacyRequest $request): void
    {
        $candidate = $request->candidate;
        abort_unless($candidate !== null, 422);
        $payload = app(SubjectData::class)->payload($candidate);
        $id = (string) Str::uuid();
        $path = 'privacy-exports/'.$id.'.bin';
        DB::table('privacy_artifacts')->insert(['id' => $id, 'privacy_request_id' => $request->id, 'path' => $path, 'expires_at' => now()->addHours(24)]);
        Storage::disk('private')->put($path, app(ContentEncrypter::class)->encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $request->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->audit($actor, $request, 'completed');
    }

    public function download(User $actor, string $artifactId): string
    {
        $artifact = DB::table('privacy_artifacts')->where('id', $artifactId)->first();
        abort_unless($artifact !== null, 404);
        $request = PrivacyRequest::query()->findOrFail($artifact->privacy_request_id);
        $this->access->request($actor, $request);
        abort_unless($this->access->approver($actor), 403);
        abort_if($artifact->purged_at !== null || now()->gte($artifact->expires_at), 410);
        $this->audit($actor, $request, 'downloaded');

        return app(ContentEncrypter::class)->decryptString(Storage::disk('private')->get($artifact->path));
    }

    public function purgeExpired(): void
    {
        foreach (DB::table('privacy_artifacts')->whereNull('purged_at')->where('expires_at', '<=', now())->get() as $artifact) {
            app(ErasureFiles::class)->queue('private', $artifact->path);
            DB::table('privacy_artifacts')->where('id', $artifact->id)->update(['purged_at' => now()]);
        }
        app(ErasureFiles::class)->purge();
    }

    private function audit(User $actor, PrivacyRequest $request, string $event): void
    {
        activity('privacy')->causedBy($actor)->performedOn($request)->event('privacy.'.$event)->withProperties(['purpose' => 'data_subject_rights'])->log('privacy.'.$event);
    }
}
