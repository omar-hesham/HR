<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\ArabicExplanation;
use App\Domain\Ai\ClassificationResult;
use App\Domain\Ai\CriterionResults;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\Evidence;
use App\Domain\Ai\ExtractionResult;
use App\Domain\Ai\HealthResult;
use App\Domain\Ai\JobContext;
use App\Domain\Ai\MaskedEmail;
use App\Domain\Ai\MaskedText;
use App\Domain\Ai\Masker;
use App\Domain\Ai\ProviderResult;
use App\Domain\Ai\Schema;
use App\Domain\Ai\UsageSummary;
use App\Models\AiRun;
use App\Models\AiWorkItem;
use App\Models\Document;
use App\Services\Settings\SettingsRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

final class GuardedAiProvider implements AiProvider
{
    public ?int $lastRunId = null;

    public function __construct(private readonly AiDriverFactory $factory, private readonly DispatchGuard $guard, private readonly CircuitBreaker $breaker, private readonly CostMeter $meter) {}

    public function classifyEmail(MaskedEmail $email): ClassificationResult
    {
        return $this->call('classify', $email->context, mb_strlen($email->text), $email->succeeded, fn ($driver) => $driver->classifyEmail($email), function ($result): void {
            $p = $result->data;
            $keys = array_keys($p);
            sort($keys);
            if ($keys !== ['classification', 'confidence'] || ! in_array($p['classification'], ['cv', 'supporting_document', 'uncertain', 'other'], true) || ! is_numeric($p['confidence']) || $p['confidence'] < 0 || $p['confidence'] > 1) {
                throw new AiException(AiError::InvalidSchema);
            }
            if ($p['confidence'] < app(SettingsRepository::class)->float('scoring.confidence')) {
                throw new AiException(AiError::LowConfidence);
            }
        });
    }

    public function extract(MaskedText $text, Schema $schema, JobContext $context): ExtractionResult
    {
        return $this->call('extract', $text->context, mb_strlen($text->text) + mb_strlen($schema->json()), $text->succeeded, fn ($driver) => $driver->extract($text, $schema, $context), function ($result) use ($schema, $context): void {
            $schema->validate($result->data);
            foreach ($result->data['experience_relevant_years'] as $experience) {
                if ($experience['job_id'] !== $context->jobId) {
                    throw new AiException(AiError::InvalidSchema);
                }
            }
        });
    }

    public function explain(CriterionResults $results, Evidence $evidence): ArabicExplanation
    {
        $document = Document::query()->find($results->context->documentId);
        $name = $document?->candidate?->name;
        $claims = [];
        $succeeded = true;
        $characters = 0;
        foreach ($results->claims as $id => $claim) {
            $masked = (new Masker)->mask($claim, $results->context, $name);
            $succeeded = $succeeded && $masked->succeeded;
            $claims[$id] = $masked->text;
            $characters += mb_strlen($claim);
        }

        return $this->call('explain', $results->context, $characters, $succeeded, fn ($driver) => $driver->explain(new CriterionResults($claims, $results->context), new Evidence), fn ($result) => $this->validateExplanation($result->data, $results->claims));
    }

    public function health(): HealthResult
    {
        return $this->call('health', new DispatchContext, 100, true, fn ($driver) => $driver->health(), function ($result): void {
            if (array_keys($result->data) !== ['healthy'] || ! is_bool($result->data['healthy'])) {
                throw new AiException(AiError::InvalidSchema);
            }
        });
    }

    public function usage(): UsageSummary
    {
        return new UsageSummary((int) AiRun::query()->where('driver', config('ai-pipeline.driver'))->sum('input_tokens'), (int) AiRun::query()->where('driver', config('ai-pipeline.driver'))->sum('output_tokens'));
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,string> $claims */
    public function validateExplanation(array $payload, array $claims): void
    {
        if (array_keys($payload) !== ['claim_ids'] || ! is_array($payload['claim_ids']) || ! array_is_list($payload['claim_ids']) || count($payload['claim_ids']) !== count($claims)) {
            throw new AiException(AiError::InvalidSchema);
        }
        $ids = $payload['claim_ids'];
        $expected = array_keys($claims);
        sort($ids);
        sort($expected);
        if ($ids !== $expected) {
            throw new AiException(AiError::InvalidSchema);
        }
    }

    /**
     * @template T of ProviderResult
     *
     * @param callable(AiProvider):T $dispatch
     * @param callable(T):void $validate
     * @return T */
    private function call(string $purpose, DispatchContext $context, int $characters, bool $masked, callable $dispatch, callable $validate): ProviderResult
    {
        $this->lastRunId = null;
        $this->guard->check($context, $purpose, $masked);
        $provider = (string) config('ai-pipeline.driver');
        $token = $purpose === 'health' ? null : $this->breaker->acquire($provider);
        try {
            $run = DB::transaction(function () use ($purpose, $context, $characters) {
                $work = $context->workItemId === null ? null : AiWorkItem::query()->whereKey($context->workItemId)->lockForUpdate()->first();
                if ($context->workItemId !== null && ($work === null || $work->attempts >= 3)) {
                    throw new AiDeferred('awaiting_service');
                }
                $reserved = $this->meter->reserve($purpose, $context, $characters + 4096);
                if ($work !== null) {
                    $work->forceFill(['attempts' => $work->attempts + 1, 'queued_at' => now()])->save();
                }

                return $reserved;
            });
        } catch (AiDeferred $e) {
            $this->breaker->cancelTrial($provider, $token);
            throw $e;
        }
        $this->lastRunId = $run->id;
        $start = hrtime(true);
        $result = null;
        try {
            $result = $dispatch($this->factory->driver((string) $run->getAttribute('request_id')));
            $validate($result);
            $this->meter->reconcile($run, $result, null, (int) ((hrtime(true) - $start) / 1_000_000));
            if ($purpose !== 'health') {
                $this->breaker->success($provider);
            }

            return $result;
        } catch (Throwable $e) {
            $error = $e instanceof AiException ? $e->error : AiError::ProviderUnavailable;
            $this->meter->reconcile($run, $result, $error->value, (int) ((hrtime(true) - $start) / 1_000_000));
            if ($purpose !== 'health' && $error->providerFailure()) {
                $this->breaker->failure($provider);
            } elseif ($purpose !== 'health') {
                $this->breaker->success($provider);
            }
            throw new AiException($error);
        }
    }
}
