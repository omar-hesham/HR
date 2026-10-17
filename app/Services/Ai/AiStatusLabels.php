<?php

namespace App\Services\Ai;

use App\Models\AiWorkItem;
use App\Models\DocumentSource;

final class AiStatusLabels
{
    public function forSource(DocumentSource $source): ?string
    {
        if ($source->intake_message_id !== null) {
            $work = AiWorkItem::query()->where('intake_message_id', $source->intake_message_id)->latest('id')->first();
            if ($work !== null) {
                if ($work->state === 'done') {
                    $suggestion = json_decode($work->result_enc ?? '{}', true);

                    return __('ai.classification_suggestion', ['classification' => __('ai.classifications.'.($suggestion['classification'] ?? 'uncertain'))]);
                }

                return __('ai.states.'.($work->state === 'done' ? 'classification_review' : ($work->reason ?? $work->state)));
            }
        }

        return $source->application_id === null ? null : app(AiWorkCoordinator::class)->labelForApplication($source->application_id);
    }
}
