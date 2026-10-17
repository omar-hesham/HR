<?php

namespace App\Services\Ai;

use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;

final readonly class AiOperationsSnapshot
{
    public function __construct(private SettingsRepository $settings, private CostMeter $meter, private CircuitBreaker $breaker) {}

    /**
     * @return array<string,mixed> */
    public function data(): array
    {
        $driver = (string) config('ai-pipeline.driver');
        $state = $this->breaker->state($driver);

        return ['enabled' => $this->settings->bool('ai.enabled'), 'driver' => $driver, 'breaker' => $state, 'suspended' => $state !== 'closed', 'consumed' => $this->meter->spentUnits(now()->format('Y-m')) / 10000, 'cap' => $this->settings->float('ai.monthly_cap_egp'), 'pending' => DB::table('ai_work_items')->whereIn('state', ['waiting', 'queued', 'review'])->count(), 'alerts' => DB::table('activity_log')->where('event', 'ai.breaker_open')->count(), 'errors' => DB::table('ai_runs')->whereNotNull('error_code')->latest('id')->limit(20)->get(['id', 'purpose', 'driver', 'model', 'prompt_version', 'status', 'input_tokens', 'output_tokens', 'reserved_cost_egp', 'cost_egp', 'duration_ms', 'error_code', 'request_id', 'created_at'])->all()];
    }
}
