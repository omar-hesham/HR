<?php

namespace App\Services\Ai;

use App\Domain\Ai\AiDeferred;
use App\Domain\Ai\AiError;
use App\Domain\Ai\AiException;
use App\Domain\Ai\AiProvider;
use App\Domain\Ai\CriterionResults;
use App\Domain\Ai\DispatchContext;
use App\Domain\Ai\Evidence;
use App\Domain\Ai\MaskedEmail;
use App\Domain\Ai\Masker;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Intake\Contracts\MessageParser;
use App\Domain\Scoring\ExplanationTemplate;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Infrastructure\Storage\EncryptedDocumentStore;
use App\Models\AiWorkItem;
use App\Models\Application;
use App\Models\Document;
use App\Models\IntakeMessage;
use App\Models\Score;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AiWorkExecutor
{
    public function __construct(private AiProvider $provider, private DispatchGuard $guard, private CircuitBreaker $breaker, private StructuredExtraction $extractor, private AiWorkCoordinator $coordinator) {}

    public function execute(int $id): void
    {
        $work = AiWorkItem::query()->find($id);
        if ($work === null || in_array($work->state, ['done', 'review', 'failed'], true)) {
            return;
        }
        if ($work->attempts >= 3) {
            $work->forceFill(['state' => 'review', 'reason' => $work->reason ?? 'ProviderUnavailable'])->save();

            return;
        }
        $application = Application::query()->find($work->application_id);
        if (($work->application_id !== null && ($application === null || $application->status === ApplicationStatus::Deleted)) || ($work->document_id !== null && ! Document::query()->whereKey($work->document_id)->whereNotNull('path')->exists())) {
            $work->forceFill(['state' => 'done', 'reason' => 'deleted'])->save();

            return;
        }
        $context = new DispatchContext($work->document_id, $work->application_id, $work->intake_message_id, $work->score_id, $application?->candidate_id, $work->id);
        if ($this->provider instanceof GuardedAiProvider) {
            $this->provider->lastRunId = null;
        }
        try {
            $this->guard->check($context, $work->purpose);
            if (! $this->breaker->canConsume((string) config('ai-pipeline.driver'))) {
                throw new AiDeferred('awaiting_service');
            }
            $result = match ($work->purpose) {
                'extract' => $this->extract($work),'classify' => $this->classify($work, $context),'explain' => $this->explain($work, $context),default => throw new AiException(AiError::InvalidSchema)
            };
            if (! AiWorkItem::query()->whereKey($work->id)->exists()) {
                return;
            }
            $work->forceFill(['state' => isset($result['verification_error']) ? 'review' : 'done', 'reason' => $result['verification_error'] ?? null,  'result_enc' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->save();
        } catch (AiDeferred $e) {
            if (AiWorkItem::query()->whereKey($work->id)->exists()) {
                $work->forceFill(['state' => 'waiting', 'reason' => $e->reason])->save();
            }
        } catch (Throwable $e) {
            $error = $e instanceof AiException ? $e->error : AiError::ProviderUnavailable;
            if (! AiWorkItem::query()->whereKey($work->id)->exists()) {
                return;
            }
            $work->refresh();
            $attempts = $work->attempts;
            $retry = $e instanceof AiException && $attempts > 0 && $error->retryable() && $attempts < 3;
            $work->forceFill(['state' => $retry ? 'queued' : 'review', 'reason' => $error->value, 'attempts' => $attempts, 'queued_at' => now()])->save();
            if ($retry) {
                throw new AiException($error);
            }
        }
    }

    /**
     * @return array<string,mixed> */
    private function extract(AiWorkItem $work): array
    {
        $extraction = $this->extractor->run((int) $work->document_id, $work->application_id, $work->id);
        if ($extraction !== null) {
            $this->coordinator->forDocument((int) $work->document_id);
        }

        return ['extraction_id' => $extraction?->id, 'verification_error' => $extraction?->error_code];
    }

    /**
     * @return array<string,mixed> */
    private function classify(AiWorkItem $work, DispatchContext $context): array
    {
        $message = IntakeMessage::query()->find($work->intake_message_id);
        $store = app(EncryptedDocumentStore::class);
        if ($message === null || $message->raw_path === null || ! $store->exists($message->raw_path)) {
            return ['deleted' => true];
        }
        $parsed = app(MessageParser::class)->parse($store->get($message->raw_path));
        $from = $parsed->header('from');
        $sender = null;
        if ($from !== null && preg_match('/^\s*([^<]+)\s*</u', $from, $m)) {
            $sender = trim($m[1], ' "');
        }
        // Attachments, filenames and headers are never serialized into the request.
        $masked = app(Masker::class)->mask(($parsed->subject ?? '')."\n".$parsed->textBody, $context, $sender);
        $result = $this->provider->classifyEmail(new MaskedEmail($masked->text, $context, $masked->substitutions, $masked->succeeded));
        if (IntakeMessage::query()->whereKey($message->id)->exists()) {
            $message->forceFill(['reason' => 'classification_review', 'last_error' => null])->save();
        }

        return $result->data; // Safe code/confidence only; every attachment remains visible for link review.
    }

    /**
     * @return array<string,mixed> */
    private function explain(AiWorkItem $work, DispatchContext $context): array
    {
        $score = Score::query()->find($work->score_id);
        if ($score === null || DB::table('score_explanations')->where('score_id', $score->id)->exists()) {
            return [];
        }
        $claims = app(ExplanationTemplate::class)->claims($score);
        $result = $this->provider->explain(new CriterionResults($claims, $context), new Evidence);
        $body = implode("\n", array_map(fn ($id) => $claims[$id], $result->data['claim_ids']));
        DB::transaction(function () use ($score, $body): void {
            $application = Application::query()->whereKey($score->application_id)->lockForUpdate()->first();
            if ($application === null || $application->status === ApplicationStatus::Deleted || ! Score::query()->whereKey($score->id)->exists()) {
                return;
            }
            DB::table('score_explanations')->insertOrIgnore(['score_id' => $score->id, 'body_enc' => app(ContentEncrypter::class)->encryptString($body), 'ai_run_id' => $this->provider instanceof GuardedAiProvider ? $this->provider->lastRunId : null, 'created_at' => now(), 'updated_at' => now()]);
        });

        return ['explained' => true];
    }
}
