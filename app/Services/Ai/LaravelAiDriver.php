<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\ArabicExplanation;
use App\Domain\Ai\ClassificationResult;
use App\Domain\Ai\CriterionResults;
use App\Domain\Ai\Evidence;
use App\Domain\Ai\ExtractionResult;
use App\Domain\Ai\HealthResult;
use App\Domain\Ai\JobContext;
use App\Domain\Ai\MaskedEmail;
use App\Domain\Ai\MaskedText;
use App\Domain\Ai\ProviderResult;
use App\Domain\Ai\Schema;
use App\Domain\Ai\UsageSummary;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\AiManager;
use Throwable;

/** SDK adapter. Call only through GuardedAiProvider; no failover providers or stored conversations. */
final class LaravelAiDriver implements AiProvider
{
    private UsageSummary $lastUsage;

    public function __construct(private readonly string $driver, private readonly ?string $requestId = null)
    {
        $this->lastUsage = new UsageSummary;
    }

    public function classifyEmail(MaskedEmail $email): ClassificationResult
    {
        return $this->request('classify', ['email' => $email->text], ClassificationResult::class);
    }

    public function extract(MaskedText $text, Schema $schema, JobContext $context): ExtractionResult
    {
        return $this->request('extract', ['document' => $text->text, 'job_context' => ['job_id' => $context->jobId, 'title' => $context->title, 'military_enabled' => $context->militaryEnabled]], ExtractionResult::class, $schema->json());
    }

    public function explain(CriterionResults $results, Evidence $evidence): ArabicExplanation
    {
        return $this->request('explain', ['claims' => $results->claims, 'evidence' => $evidence->items], ArabicExplanation::class);
    }

    public function health(): HealthResult
    {
        return $this->request('health', [], HealthResult::class);
    }

    public function usage(): UsageSummary
    {
        return $this->lastUsage;
    }

    /**
     * @template T of ProviderResult
     *
     * @param array<string,mixed> $data
     * @param class-string<T> $class
     * @return T */
    private function request(string $purpose, array $data, string $class, string $schema = ''): ProviderResult
    {
        $connection = $this->driver === 'cloud' ? 'recruit_cloud' : 'recruit_local';
        $requestId = $this->requestId ?? (string) Str::uuid();
        config(['ai.providers.'.$connection.'.headers.X-Request-ID' => $requestId]);
        $instructions = $purpose === 'health' ? 'Return exactly {"healthy":true}. No tools or other output.' : (string) file_get_contents(resource_path('ai/'.$purpose.'-v1.txt'));
        if ($schema !== '') {
            $instructions .= "\nJSON SCHEMA:\n".$schema;
        }
        $start = hrtime(true);
        try {
            app(AiManager::class)->forgetInstance($connection)->textProvider($connection)->useTextGateway(new MeteredSdkGateway(app(Dispatcher::class)));
            $agent = new ExtractionAgent($instructions, [], []);
            $response = $agent->prompt(json_encode(['untrusted_data' => $data], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), provider: $connection, model: (string) config('ai-pipeline.'.($this->driver === 'cloud' ? 'cloud_model' : 'model')), timeout: $purpose === 'health' ? 10 : (int) config('ai-pipeline.timeout'));
            $payload = json_decode($response->text, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new AiException(AiError::InvalidSchema);
            }
            $this->lastUsage = new UsageSummary($response->usage->inputTokens, $response->usage->outputTokens);

            return new $class($payload, $requestId, $response->meta->model ?? (string) config('ai-pipeline.model'), $this->lastUsage, (int) ((hrtime(true) - $start) / 1_000_000));
        } catch (AiException $e) {
            throw $e;
        } catch (Throwable $e) {
            $type = class_basename($e);
            $error = match (true) {
                str_contains($type, 'RateLimit') => AiError::RateLimited,
                str_contains($type, 'Authentication') || ($e instanceof RequestException && in_array($e->response->status(), [401, 403], true)) => AiError::AuthenticationFailed,
                str_contains($type, 'InsufficientCredits') => AiError::BudgetExceeded,
                str_contains($type, 'Connection') || str_contains($type, 'Timeout') => AiError::Timeout,
                $e instanceof \JsonException => AiError::InvalidSchema,
                default => AiError::ProviderUnavailable,
            };
            throw new AiException($error);
        }
    }
}
