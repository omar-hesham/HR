<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\ProviderResult;
use App\Models\AiRun;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CostMeter
{
    public function __construct(private SettingsRepository $settings) {}

    public function reserve(string $purpose, DispatchContext $context, int $characters): AiRun
    {
        $driver = (string) config('ai-pipeline.driver');
        $month = now()->format('Y-m');
        DB::table('ai_budget_periods')->insertOrIgnore(['month' => $month, 'created_at' => now(), 'updated_at' => now()]);

        return DB::transaction(function () use ($purpose, $context, $characters, $driver, $month): AiRun {
            DB::table('ai_budget_periods')->where('month', $month)->lockForUpdate()->first();
            $input = (int) ceil($characters / max(.1, $this->settings->float('ai.chars_per_token')));
            $reserved = $driver === 'cloud' ? $this->units($input, $this->settings->int('ai.max_output_tokens')) : 0;
            $cap = (int) floor($this->settings->float('ai.monthly_cap_egp') * 10000);
            if ($driver === 'cloud' && $this->spentUnits($month) + $reserved > $cap) {
                throw new AiDeferred('awaiting_budget');
            }

            return AiRun::query()->create(['document_id' => $context->documentId, 'purpose' => $purpose, 'driver' => $driver, 'model' => (string) config('ai-pipeline.'.($driver === 'cloud' ? 'cloud_model' : 'model')), 'prompt_version' => $purpose.'-v1', 'status' => 'reserved', 'reserved_cost_egp' => number_format($reserved / 10000, 4, '.', ''), 'cost_egp' => null, 'request_id' => (string) Str::uuid()]);
        });
    }

    public function spentUnits(string $month): int
    {
        return (int) round((float) DB::table('ai_runs')->where('driver', 'cloud')->where('created_at', '>=', $month.'-01 00:00:00')->where('created_at', '<', Carbon::parse($month.'-01', 'UTC')->addMonth())->selectRaw('COALESCE(SUM(CASE WHEN cost_egp IS NULL THEN reserved_cost_egp ELSE cost_egp END),0) AS spent')->value('spent') * 10000);
    }

    public function reconcile(AiRun $run, ?ProviderResult $result, ?string $error, int $duration): void
    {
        $reserved = (int) round((float) $run->getAttribute('reserved_cost_egp') * 10000);
        $unknownUsage = $run->driver === 'cloud' && $result !== null && ($result->usage->inputTokens < 0 || $result->usage->outputTokens < 0 || $result->usage->inputTokens + $result->usage->outputTokens === 0);
        $actual = $run->driver === 'cloud' && $result !== null && ! $unknownUsage ? $this->units($result->usage->inputTokens, $result->usage->outputTokens) : $reserved;
        $over = $actual > $reserved;
        $run->forceFill(['status' => $error === null && ! $over && ! $unknownUsage ? 'succeeded' : ($error === null ? 'review' : 'failed'), 'error_code' => $over ? 'reservation_exceeded' : ($error ?? ($unknownUsage ? 'usage_unavailable' : null)), 'cost_egp' => number_format(min($reserved, $actual) / 10000, 4, '.', ''), 'input_tokens' => $unknownUsage ? null : $result?->usage->inputTokens, 'output_tokens' => $unknownUsage ? null : $result?->usage->outputTokens, 'duration_ms' => max(0, $duration), 'request_id' => $result !== null && preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/D', $result->requestId) ? $result->requestId : $run->getAttribute('request_id'), 'model' => $result !== null && preg_match('/^[A-Za-z0-9_.:\/\-]{1,128}$/D', $result->modelId) ? $result->modelId : $run->model])->save();
        if ($over) {
            activity('operations')->event('ai.reservation_exceeded')->withProperties(['ai_run_id' => $run->id])->log('ai.reservation_exceeded');
        }
    }

    private function units(int $input, int $output): int
    {
        return max(0, (int) ceil(($input * $this->settings->float('ai.input_egp_per_1k') + $output * $this->settings->float('ai.output_egp_per_1k')) * 10));
    }
}
