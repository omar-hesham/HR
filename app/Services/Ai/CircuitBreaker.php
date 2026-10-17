<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Jobs\NotifyAiOperator;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CircuitBreaker
{
    public function __construct(private SettingsRepository $settings) {}

    private function ensure(string $provider): void
    {
        DB::table('ai_provider_states')->insertOrIgnore(['provider' => $provider, 'state' => 'closed', 'failures' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function state(string $provider): string
    {
        return (string) (DB::table('ai_provider_states')->where('provider', $provider)->value('state') ?? 'closed');
    }

    public function canConsume(string $provider): bool
    {
        $row = DB::table('ai_provider_states')->where('provider', $provider)->first();

        return $row === null || $row->state === 'closed' || ($row->state === 'half_open' && ($row->trial_token === null || $row->trial_until < now()->toDateTimeString()));
    }

    public function acquire(string $provider): ?string
    {
        $this->ensure($provider);

        return DB::transaction(function () use ($provider) {
            $row = DB::table('ai_provider_states')->where('provider', $provider)->lockForUpdate()->first();
            if ($row->state === 'closed') {
                return null;
            }
            if (! $this->canConsume($provider)) {
                throw new AiDeferred('awaiting_service');
            }
            $token = (string) Str::uuid();
            DB::table('ai_provider_states')->where('provider', $provider)->update(['trial_token' => $token, 'trial_until' => now()->addSeconds((int) config('ai-pipeline.timeout') + 60), 'updated_at' => now()]);

            return $token;
        });
    }

    public function success(string $provider): void
    {
        $this->ensure($provider);
        DB::table('ai_provider_states')->where('provider', $provider)->update(['state' => 'closed', 'failures' => 0, 'trial_token' => null, 'trial_until' => null, 'probe_after' => now()->addSeconds($this->settings->int('ai.recovery_seconds')), 'updated_at' => now()]);
    }

    public function cancelTrial(string $provider, ?string $token): void
    {
        if ($token !== null) {
            DB::table('ai_provider_states')->where('provider', $provider)->where('trial_token', $token)->update(['trial_token' => null, 'trial_until' => null]);
        }
    }

    public function failure(string $provider): void
    {
        $this->ensure($provider);
        DB::transaction(function () use ($provider): void {
            $row = DB::table('ai_provider_states')->where('provider', $provider)->lockForUpdate()->first();
            $failures = $row->failures + 1;
            $open = $row->state !== 'closed' || $failures >= $this->settings->int('ai.breaker_threshold');
            DB::table('ai_provider_states')->where('provider', $provider)->update(['state' => $open ? 'open' : 'closed', 'failures' => $failures, 'trial_token' => null, 'trial_until' => null, 'probe_after' => now()->addSeconds($this->settings->int('ai.recovery_seconds')), 'updated_at' => now()]);
            if ($open) {
                activity('operations')->event('ai.breaker_open')->withProperties(['provider' => $provider])->log('ai.breaker_open');
                event('ai.breaker_open', [$provider]);
                NotifyAiOperator::dispatch($provider)->afterCommit();
            }
        });
    }

    public function probeDue(string $provider): bool
    {
        $this->ensure($provider);

        return DB::table('ai_provider_states')->where('provider', $provider)->where('state', '!=', 'half_open')->where(fn ($query) => $query->whereNull('probe_after')->orWhere('probe_after', '<=', now()))->exists();
    }

    public function recoveredHealth(string $provider): void
    {
        DB::table('ai_provider_states')->where('provider', $provider)->where('state', 'open')->update(['state' => 'half_open', 'trial_token' => null, 'trial_until' => null, 'updated_at' => now()]);
    }
}
