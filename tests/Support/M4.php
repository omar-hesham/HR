<?php

use App\Actions\Scoring\EvaluateApplication;
use App\Models\RecruitmentJob;
use Symfony\Component\HttpKernel\Exception\HttpException;

function m4Application(?RecruitmentJob $job = null, array $candidate = []): array
{
    $fixture = m3Application();
    if ($job !== null) {
        $fixture['application']->forceFill(['recruitment_job_id' => $job->id])->save();
        $fixture['extraction']->forceFill(['fields_json_enc' => json_encode(m3Facts((string) $job->id), JSON_THROW_ON_ERROR)])->save();
        $fixture['job'] = $job;
        $fixture['version'] = $job->currentCriteriaVersion;
    }
    $fixture['candidate']->forceFill($candidate)->save();
    $fixture['score'] = app(EvaluateApplication::class)->handle($fixture['application']->id);
    $fixture['application']->refresh();

    return $fixture;
}

function m4HttpException(callable $operation, int $code): void
{
    try {
        $operation();
        test()->fail('Expected HTTP exception '.$code);
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe($code);
    }
}
