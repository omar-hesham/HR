<?php

namespace App\Services\Operations;

use App\Models\Mailbox;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

final class GoLiveCheck
{
    /** @return array<string,string> */
    public function checks(): array
    {
        $s = app(SettingsRepository::class);
        $s->flush();
        $jobs = RecruitmentJob::query()->where('active', true)->with('currentCriteriaVersion.criteria')->get();
        $criteria = $jobs->count() === 7 && $jobs->every(fn ($j): bool => $j->currentCriteriaVersion !== null && $j->currentCriteriaVersion->approved_at !== null && $j->currentCriteriaVersion->criteria->isNotEmpty());
        $users = User::query()->where('active', true)->whereHas('roles', fn ($q) => $q->where('name', '!=', 'operator'))->get();
        $roles = ['owner', 'hiring_manager', 'recruiter', 'coordinator', 'management'];
        $accounts = $users->count() === 5 && collect($roles)->every(fn (string $r): bool => $users->contains(fn (User $u): bool => $u->hasRole($r)));
        $mailboxes = Mailbox::query()->where('active', true)->get();
        $mail = $mailboxes->isNotEmpty() && $mailboxes->every(fn (Mailbox $m): bool => $m->provider !== 'fake' && $m->start_at !== null && $m->start_at->lte(now()) && $m->uidvalidity !== null && $m->cursor !== null && $m->last_success_at !== null && $m->last_success_at->gte(now()->subMinutes(15)) && $m->last_error === null);
        $checks = ['criteria' => $criteria && $this->filled($s->string('operations.criteria_approval_ref')), 'accounts' => $accounts && $this->filled($s->string('operations.accounts_approval_ref')), 'mfa' => Filament::getPanel('admin')->isMultiFactorAuthenticationRequired() && $users->isNotEmpty() && $users->every(fn (User $u): bool => $u->app_authentication_secret !== null), 'mailbox' => $mail,
            'ai_path' => $this->filled($s->string('operations.ai_path_decision')), 'notice' => $this->filled($s->string('operations.notice_approval_ref')) && $this->filled($s->string('privacy.notice_text_ar')) && $this->filled($s->string('privacy.notice_text_en')),
            'retention' => $this->filled($s->string('operations.retention_approval_ref')), 'backup_cycle' => $this->filled($s->string('operations.backup_cycle_approval_ref')),
            'backups' => app(Backups::class)->health()['configured'] && app(Backups::class)->health()['fresh'] && is_file((string) config('operations.ledger_target')), 'restore' => DB::table('backup_runs')->where('restore_engine', DB::connection()->getDriverName())->whereNotNull('restore_test_at')->where('restore_test_at', '>=', now()->subMonths(3))->exists(),
            'alerts' => filter_var(config('recruitment.intake.health_alert_recipient'), FILTER_VALIDATE_EMAIL) !== false,
            'owners' => $this->filled($s->string('operations.support_owner')) && $this->filled($s->string('operations.recovery_owner')) && $this->filled($s->string('operations.approver')),
            'rollback' => is_file(base_path('docs/RUNBOOK-ar.md')) && is_file(base_path('docs/DEPLOYMENTS.md')), 'guide' => is_file(base_path('docs/USER-GUIDE-AR.md'))];
        $statuses = array_map(fn (bool $pass): string => $pass ? 'pass' : 'missing', $checks);
        foreach (['retention.applicant_days', 'retention.idle_days', 'retention.hard_max_days', 'retention.owner_notice_days', 'retention.hired_handover_days'] as $key) {
            if ($s->int($key) <= 0 || $s->get($key) === $s->defaults()[$key]) {
                $statuses[$key] = 'warning';
            }
        }
        $statuses['external_acceptance'] = 'external';

        return $statuses;
    }

    private function filled(string $value): bool
    {
        return trim($value) !== '' && ! preg_match('/\[|\]|placeholder|example|TODO|TBD/i', $value);
    }
}
