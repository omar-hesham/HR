<?php

namespace App\Actions\Review;

use App\Domain\Documents\DocumentKind;
use App\Domain\Documents\DocumentStatus;
use App\Jobs\RescoreApplication;
use App\Models\Application;
use App\Models\Candidate;
use App\Models\Document;
use App\Models\DuplicateResolution;
use App\Models\User;
use App\Services\Review\DuplicateQueue;
use Illuminate\Support\Facades\DB;

final class ResolveDuplicate
{
    public function handle(int $existingId, int $incomingId, string $action, string $reason, User $actor, bool $recalculate = false): DuplicateResolution
    {
        abort_if(trim($reason) === '' || ! in_array($action, ['merge', 'keep_two', 'append_active'], true), 422);
        abort_if($action === 'append_active' && ! $recalculate, 422, __('review.confirm_recalculation'));

        return DB::transaction(function () use ($existingId, $incomingId, $action, $reason, $actor): DuplicateResolution {
            $locked = Candidate::query()->whereKey([$existingId, $incomingId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $existing = $locked->get($existingId);
            $incoming = $locked->get($incomingId);
            abort_unless($existing instanceof Candidate && $incoming instanceof Candidate, 404);
            // Lock affected applications before rechecking full scope and making link changes.
            Application::query()->whereIn('candidate_id', [$existingId, $incomingId])->orderBy('id')->lockForUpdate()->get();
            $queue = app(DuplicateQueue::class);
            abort_unless($queue->canResolve($actor, $existing, $incoming), 403);
            $fingerprint = $queue->fingerprint($existing, $incoming);
            abort_if(DuplicateResolution::query()->where('fingerprint', $fingerprint)->exists(), 409);
            $before = [$this->links($existing), $this->links($incoming)];
            if ($action !== 'keep_two') {
                abort_if($action === 'append_active' && ($incoming->activeDocument?->path === null
                    || $incoming->activeDocument->kind !== DocumentKind::Cv
                    || $incoming->activeDocument->status !== DocumentStatus::Extracted), 422);
                Application::query()->where('candidate_id', $incomingId)->update(['candidate_id' => $existingId]);
                Document::query()->where('candidate_id', $incomingId)->update(['candidate_id' => $existingId]);
                $existing->received_at = $existing->received_at->min($incoming->received_at);
                if ($action === 'append_active') {
                    $existing->active_document_id = $incoming->active_document_id;
                }
                $existing->save();
                $incoming->forceFill(['merged_into_id' => $existingId, 'active_document_id' => null])->save();
            }
            $row = DuplicateResolution::query()->create(['existing_candidate_id' => $existingId, 'incoming_candidate_id' => $incomingId,
                'fingerprint' => $fingerprint, 'action' => $action, 'user_id' => $actor->id, 'reason_enc' => $reason,
                'links_enc' => json_encode(['before' => $before, 'after' => [$this->links($existing->refresh()), $this->links($incoming->refresh())]], JSON_THROW_ON_ERROR), 'created_at' => now()]);
            activity('access')->causedBy($actor)->performedOn($row)->event('duplicate.'.$action)
                ->withProperties(['existing_candidate_id' => $existingId, 'incoming_candidate_id' => $incomingId, 'prior_active_document_ids' => array_column($before, 'active_document_id'), 'new_active_document_id' => $existing->active_document_id, 'purpose' => 'duplicate_resolution', 'resolution_id' => $row->id])->log('duplicate.'.$action);
            if ($action !== 'keep_two') {
                foreach ($existing->applications()->get() as $application) {
                    RescoreApplication::dispatch($application->id)->afterCommit();
                }
            }

            return $row;
        });
    }

    /** @return array<string,mixed> */
    private function links(Candidate $candidate): array
    {
        return ['candidate_id' => $candidate->id, 'active_document_id' => $candidate->active_document_id,
            'application_ids' => $candidate->applications()->orderBy('id')->pluck('id')->all(),
            'document_ids' => $candidate->documents()->orderBy('id')->pluck('id')->all()];
    }
}
