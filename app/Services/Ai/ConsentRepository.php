<?php

namespace App\Services\Ai;

use App\Domain\Ai\DispatchContext;
use App\Models\Candidate;
use App\Models\IntakeMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ConsentRepository
{
    public function verified(DispatchContext $context): bool
    {
        if ($context->candidateId === null && $context->intakeId === null) {
            return false;
        }

        return DB::table('ai_consents')->whereNull('revoked_at')->where('verified_at', '<=', now())->where(function ($q) use ($context) {
            if ($context->candidateId !== null) {
                $q->where('candidate_id', $context->candidateId);
            } else {
                $q->where('intake_message_id', $context->intakeId);
            }
        })->exists();
    }

    public function verify(DispatchContext $context, string $reference, User $actor): int
    {
        abort_unless($actor->can('ai.pipeline.manage'), 403);
        abort_if(trim($reference) === '' || mb_strlen($reference) > 128, 422);
        abort_unless(($context->candidateId !== null && Candidate::query()->whereKey($context->candidateId)->exists()) xor ($context->intakeId !== null && IntakeMessage::query()->whereKey($context->intakeId)->exists()), 422);
        $id = DB::table('ai_consents')->insertGetId(['candidate_id' => $context->candidateId, 'intake_message_id' => $context->intakeId, 'reference' => $reference, 'verified_by' => $actor->id, 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        activity('configuration')->causedBy($actor)->event('ai.consent_verified')->withProperties(['consent_id' => $id])->log('ai.consent_verified');

        return $id;
    }

    public function revoke(int $id, User $actor): void
    {
        abort_unless($actor->can('ai.pipeline.manage'), 403);
        DB::table('ai_consents')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        activity('configuration')->causedBy($actor)->event('ai.consent_revoked')->withProperties(['consent_id' => $id])->log('ai.consent_revoked');
    }
}
