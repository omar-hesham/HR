<?php

namespace App\Services\Demo;

use App\Domain\Ai\DispatchContext;
use App\Models\Application;
use App\Models\IntakeMessage;

/** Only the explicitly selected fake driver uses these recordings. */
final class RecordedDemoResponses
{
    /** @param array<string,string> $claims
     * @return array<string,mixed>|null */
    public function response(string $purpose, DispatchContext $context, string $jobId = '', array $claims = []): ?array
    {
        if (! app()->environment('local', 'testing')) {
            return null;
        }
        $application = Application::query()->find($context->applicationId);
        $isUpload = str_starts_with($application->external_reference ?? '', 'demo-ai-');
        $mailbox = $application?->sources()->whereNotNull('intake_message_id')->first()?->intakeMessage->mailbox
            ?? IntakeMessage::query()->find($context->intakeId)?->mailbox;
        $isFixtureMail = $mailbox?->provider === 'fake' && $mailbox->address === 'demo-ai-mail@example.test'
            && $mailbox->host === base_path('tests/Fixtures/eml');
        if (! $isUpload && ! $isFixtureMail) {
            return null;
        }

        if ($purpose === 'explain') {
            return ['claim_ids' => array_keys($claims)];
        }
        if ($purpose === 'classify') {
            return ['classification' => 'uncertain', 'confidence' => .95];
        }
        $recording = $isUpload ? 'demo-response-v1.json' : 'demo-empty-response-v1.json';
        $response = json_decode((string) file_get_contents(base_path('tests/Fixtures/ai/'.$recording)), true, 512, JSON_THROW_ON_ERROR);
        foreach ($response['experience_relevant_years'] as &$experience) {
            $experience['job_id'] = $jobId;
        }

        return $response;
    }
}
