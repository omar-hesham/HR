<?php

namespace App\Actions\Demo;

use App\Jobs\PollMailbox;
use App\Models\IntakeMessage;
use App\Models\Mailbox;
use App\Services\Intake\MailboxDriverFactory;
use App\Services\Intake\ProcessInboundMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Local-only demo reset: removes every synthetic review datum and everything that
 * depends on it (files included), then lets the caller re-run the demo seeders.
 * Users — and their MFA enrolment — roles, permissions and settings are never touched.
 */
final class ResetDemoData
{
    /**
     * All synthetic demo data and its dependents, in FK-safe order. The shared
     * jobs queue is purged separately: pending jobs reference purged rows.
     */
    private const TABLES = [
        'score_explanations', 'criterion_results', 'overrides', 'evaluation_keys', 'field_corrections',
        'human_field_verifications', 'notes', 'status_events', 'ai_work_items', 'ai_extraction_contexts',
        'ai_consents', 'scores', 'ai_runs', 'duplicate_resolutions', 'calibration_checkpoints',
        'calibration_entries', 'calibration_batches', 'privacy_artifacts', 'privacy_requests', 'erasure_files',
        'notice_outbox', 'review_exports', 'document_sources', 'intake_messages', 'extractions', 'documents',
        'applications', 'candidates', 'retention_notices', 'deletion_ledger',
    ];

    public function purge(): void
    {
        $this->deleteFiles();
        foreach (self::TABLES as $table) {
            DB::table($table)->delete();
        }
        DB::table('jobs')->delete();
        $this->resetCriteriaVersions();
        Mailbox::query()->where('provider', 'fake')->update(
            ['cursor' => null, 'uidvalidity' => null, 'last_error' => null, 'last_success_at' => null, 'last_message_at' => null]);
    }

    /**
     * Runs the fake mailbox once over the committed synthetic .eml fixtures so
     * intake-health and the weekly message counts are not zero. Returns the
     * number of intake messages now stored, or -1 when the poll failed.
     */
    public function runFixturesMailboxOnce(): int
    {
        $mailbox = Mailbox::query()->where('provider', 'fake')->where('active', true)->first();
        if ($mailbox === null) {
            return 0;
        }
        $mailbox->forceFill(['host' => base_path('tests/Fixtures/eml')])->save();
        try {
            (new PollMailbox($mailbox->id))->handle(app(MailboxDriverFactory::class), app(ProcessInboundMessage::class));
        } catch (Throwable) {
            return -1;
        }

        return (int) IntakeMessage::query()->count();
    }

    private function deleteFiles(): void
    {
        $private = Storage::disk((string) config('recruitment.storage.disk'));
        $exports = Storage::disk('review_exports');
        foreach (DB::table('documents')->pluck('path') as $path) {
            $private->delete($path);
        }
        foreach (DB::table('intake_messages')->pluck('raw_path') as $path) {
            if (is_string($path) && $path !== '') {
                $private->delete($path);
            }
        }
        foreach (DB::table('review_exports')->pluck('path') as $path) {
            $exports->delete($path);
        }
        foreach (DB::table('privacy_artifacts')->pluck('path') as $path) {
            $private->delete($path);
        }
    }

    /**
     * Keeps only the first approved criteria version of every job (the demo-job
     * seeder's version 1) and drops later versions created by the review seeder
     * or live rehearsal, so repeated resets do not accumulate versions.
     */
    private function resetCriteriaVersions(): void
    {
        foreach (DB::table('recruitment_jobs')->pluck('id') as $jobId) {
            $approved = DB::table('criteria_versions')->where('recruitment_job_id', $jobId)
                ->whereNotNull('approved_at')->orderBy('version')->first();

            if ($approved === null) {
                $staleIds = DB::table('criteria_versions')->where('recruitment_job_id', $jobId)->pluck('id');
                DB::table('recruitment_jobs')->where('id', $jobId)->update(['current_criteria_version_id' => null]);
            } else {
                $staleIds = DB::table('criteria_versions')->where('recruitment_job_id', $jobId)
                    ->where('version', '>', $approved->version)->pluck('id');
                DB::table('recruitment_jobs')->where('id', $jobId)->update(['current_criteria_version_id' => $approved->id]);
            }

            DB::table('criteria')->whereIn('criteria_version_id', $staleIds)->delete();
            DB::table('criteria_versions')->whereIn('id', $staleIds)->delete();
        }
    }
}
