<?php

namespace App\Services\Review;

use App\Models\Application;

final class EvidenceRedactor
{
    public function redact(string $quote, Application $application): string
    {
        $candidate = $application->candidate;
        $tokens = array_filter([$candidate->name, $candidate->email, $candidate->phone, $candidate->phone_original,
            ...preg_split('/\s+/u', $candidate->name ?? '', -1, PREG_SPLIT_NO_EMPTY)]);
        foreach ($tokens as $token) {
            $quote = str_ireplace($token, __('review.redacted'), $quote);
        }
        $quote = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', __('review.redacted'), $quote) ?? '';

        return preg_replace('/\+?[\d\x{0660}-\x{0669}][\d\x{0660}-\x{0669}\s().\-]{7,}\d/u', __('review.redacted'), $quote) ?? '';
    }
}
