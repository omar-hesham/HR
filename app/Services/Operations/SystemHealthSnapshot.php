<?php

namespace App\Services\Operations;

use App\Domain\Access\Permission;
use App\Jobs\ExtractDocumentText;
use App\Jobs\NightlyBackup;
use App\Jobs\PollMailbox;
use App\Jobs\RescoreApplication;
use App\Jobs\RetentionSweep;
use App\Jobs\ScoreApplication;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Ai\AiOperationsSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class SystemHealthSnapshot
{
    /** @return array<string,mixed> */
    public function data(User $user): array
    {
        abort_unless($user->isUsable() && $user->hasPermission(Permission::OpsHealthView), 403);
        $oldest = DB::table('jobs')->min('created_at');
        $ai = app(AiOperationsSnapshot::class)->data();

        return [
            'mailboxes' => Mailbox::query()->where('active', true)->get()->map(fn (Mailbox $m): array => ['id' => $m->id, 'last_success' => $m->last_success_at, 'age' => $m->last_success_at === null ? null : (int) $m->last_success_at->diffInSeconds(now()), 'error' => $m->last_error === null ? null : 'mail_poll_failed'])->all(),
            'ai' => array_intersect_key($ai, array_flip(['enabled', 'driver', 'breaker', 'suspended', 'consumed', 'cap', 'pending'])),
            'oldest_job_seconds' => $oldest === null ? null : max(0, now()->timestamp - (int) $oldest),
            'failed_jobs' => DB::table('failed_jobs')->orderByDesc('failed_at')->limit(50)->get(['uuid', 'queue', 'failed_at'])->all(),
            'notice_failures' => DB::table('notice_outbox')->whereIn('status', ['failed', 'uncertain', 'sending'])->count(),
            'disk_total' => disk_total_space(storage_path()), 'disk_free' => disk_free_space(storage_path()),
            'backup' => app(Backups::class)->health(),
            'alerts' => DB::table('activity_log')->where('log_name', 'operations')->latest('id')->limit(20)->pluck('event')->all(),
        ];
    }

    public function retry(User $actor, string $uuid): void
    {
        abort_unless($actor->isUsable() && $actor->can('jobs.retry') && $actor->hasPermission(Permission::OpsHealthView), 403);
        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();
        abort_unless($job !== null, 404);
        $payload = json_decode($job->payload, true, 512, JSON_THROW_ON_ERROR);
        $allowed = [ExtractDocumentText::class, ScoreApplication::class, RescoreApplication::class, PollMailbox::class, RetentionSweep::class, NightlyBackup::class];
        abort_unless(in_array($payload['displayName'] ?? '', $allowed, true), 422, __('ops.retry_manual'));
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        activity('operations')->causedBy($actor)->event('job.retried')->withProperties(['job_ref' => $uuid])->log('job.retried');
    }

    public function monitor(): void
    {
        if (! app(Backups::class)->health()['fresh'] && ! DB::table('activity_log')->where('event', 'backup.stale')->where('created_at', '>', now()->subHours(24))->exists()) {
            app(OperationsAlerts::class)->record('backup.stale');
        }
    }
}
