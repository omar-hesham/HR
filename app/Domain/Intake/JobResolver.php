<?php

namespace App\Domain\Intake;

use App\Domain\Text\SearchNormalizer;
use App\Models\RecruitmentJob;

/**
 * Matches a job title or an approved alias in the subject/body (M1-P0-02).
 * Exactly one match identifies the job; zero or several matches leave the
 * application unassigned for a human (model-assisted matching is Milestone 3).
 */
final class JobResolver
{
    public function resolve(ParsedInboundMessage $message): JobHint
    {
        $haystack = SearchNormalizer::normalize(trim(($message->subject ?? '').' '.$message->textBody));

        if ($haystack === '') {
            return new JobHint(null, false);
        }

        $matches = [];

        foreach (RecruitmentJob::query()->where('active', true)->get() as $job) {
            if ($this->matches($haystack, $job)) {
                $matches[$job->id] = true;
            }
        }

        $ids = array_keys($matches);

        if (count($ids) === 1) {
            return new JobHint((int) $ids[0], false);
        }

        return new JobHint(null, count($ids) > 1);
    }

    private function matches(string $haystack, RecruitmentJob $job): bool
    {
        $needles = array_merge(
            [$job->title_ar, $job->title_en],
            $job->aliases ?? [],
        );

        foreach ($needles as $needle) {
            $needle = SearchNormalizer::normalize((string) $needle);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
