<?php

namespace App\Services\Ai;

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
use App\Domain\Ai\ProviderResult;
use App\Domain\Ai\Schema;
use App\Domain\Ai\UsageSummary;
use App\Services\Demo\RecordedDemoResponses;

/** Recorded synthetic responses only; no network implementation. */
final class FakeAiDriver implements AiProvider
{
    /**
     * @var array<string,list<array<string,mixed>|AiError>> */
    public array $responses = [];

    /**
     * @var list<array{purpose:string,data:array<string,mixed>}> */
    public array $calls = [];

    private UsageSummary $lastUsage;

    public function __construct()
    {
        $this->lastUsage = new UsageSummary;
    }

    /**
     * @param array<string,mixed>|AiError $response */
    public function push(string $purpose, array|AiError $response): void
    {
        $this->responses[$purpose][] = $response;
    }

    public function classifyEmail(MaskedEmail $email): ClassificationResult
    {
        $this->recorded('classify', $email->context);

        return $this->response('classify', ['text' => $email->text], ClassificationResult::class);
    }

    public function extract(MaskedText $text, Schema $schema, JobContext $context): ExtractionResult
    {
        $this->recorded('extract', $text->context, $context->jobId);

        return $this->response('extract', ['text' => $text->text, 'job_id' => $context->jobId], ExtractionResult::class);
    }

    public function explain(CriterionResults $results, Evidence $evidence): ArabicExplanation
    {
        $this->recorded('explain', $results->context, claims: $results->claims);

        return $this->response('explain', ['claims' => $results->claims], ArabicExplanation::class);
    }

    public function health(): HealthResult
    {
        return $this->response('health', [], HealthResult::class);
    }

    public function usage(): UsageSummary
    {
        return $this->lastUsage;
    }

    /** @param array<string,string> $claims */
    private function recorded(string $purpose, DispatchContext $context, string $jobId = '', array $claims = []): void
    {
        if (empty($this->responses[$purpose])) {
            $recording = app(RecordedDemoResponses::class)->response($purpose, $context, $jobId, $claims);
            if ($recording !== null) {
                $this->push($purpose, $recording);
            }
        }
    }

    /**
     * @template T of ProviderResult
     *
     * @param array<string,mixed> $data
     * @param class-string<T> $class
     * @return T */
    private function response(string $purpose, array $data, string $class): ProviderResult
    {
        $this->calls[] = compact('purpose', 'data');
        if (empty($this->responses[$purpose])) {
            throw new AiException(AiError::ProviderUnavailable);
        }
        $response = array_shift($this->responses[$purpose]);
        if ($response instanceof AiError) {
            throw new AiException($response);
        }
        $this->lastUsage = new UsageSummary(100, 50);

        return new $class($response, 'fake-request-'.count($this->calls), 'synthetic-model-v1', $this->lastUsage, 5);
    }
}
