<?php

namespace App\Domain\Ai;

interface AiProvider
{
    public function classifyEmail(MaskedEmail $email): ClassificationResult;

    public function extract(MaskedText $text, Schema $schema, JobContext $context): ExtractionResult;

    public function explain(CriterionResults $results, Evidence $evidence): ArabicExplanation;

    public function health(): HealthResult;

    public function usage(): UsageSummary;
}
