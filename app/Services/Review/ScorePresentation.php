<?php

namespace App\Services\Review;

use App\Domain\Review\EvidencePreview;
use App\Infrastructure\Storage\ContentEncrypter;
use App\Models\Score;
use Illuminate\Support\Facades\DB;

final class ScorePresentation
{
    /** @return array<string,mixed> */
    public function data(Score $score): array
    {
        $score->loadMissing(['criterionResults.criterion', 'criteriaVersion', 'extraction', 'overrides.user']);
        $all = [];
        $rows = [];
        foreach ($score->criterionResults as $result) {
            $evidence = json_decode($result->evidence_enc ?? '[]', true, 512, JSON_THROW_ON_ERROR);
            $evidence = is_array($evidence) ? $evidence : [];
            $all = [...$all, ...$evidence];
            $rows[] = ['criterion' => $result->criterion, 'result' => $result->result, 'confidence' => $result->confidence, 'source' => $result->source, 'evidence' => $evidence, 'normalizations' => json_decode($result->normalizations_enc ?? '[]', true, 512, JSON_THROW_ON_ERROR)];
        }
        $enrichment = $score->application->job?->getAttribute('rules_only') ? null : DB::table('score_explanations')->where('score_id', $score->id)->value('body_enc');

        return ['score' => $score, 'rows' => $rows, 'enrichment' => is_string($enrichment) ? app(ContentEncrypter::class)->decryptString($enrichment) : null,
            'preview' => app(EvidencePreview::class)->render($score->extraction?->normalizedText() ?? '', $all, $score->extraction->document_id ?? 0)];
    }
}
