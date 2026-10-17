<?php

namespace App\Providers;

use App\Domain\Ai\AiProvider;
use App\Jobs\RescoreApplication;
use App\Models\Application;
use App\Models\CriteriaVersion;
use App\Models\CriterionResult;
use App\Models\DocumentSource;
use App\Models\Exceptions\ImmutableRecordException;
use App\Models\Extraction;
use App\Models\FieldCorrection;
use App\Models\Override;
use App\Models\Score;
use App\Services\Ai\AiWorkCoordinator;
use App\Services\Ai\CriteriaSnapshot;
use App\Services\Ai\FakeAiDriver;
use App\Services\Ai\GuardedAiProvider;
use App\Services\Ai\GuardedDatabaseConnector;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;

final class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AiProvider::class, GuardedAiProvider::class);
        $this->app->singleton(FakeAiDriver::class);
    }

    public function boot(): void
    {
        Score::updating(function (Score $score): void {
            if (! $score->wasRecentlyCreated || $score->getRawOriginal('reason_enc') !== null || array_diff(array_keys($score->getDirty()), ['reason_enc', 'updated_at']) !== []) {
                throw ImmutableRecordException::forModel($score);
            }
        });
        foreach ([CriterionResult::class, FieldCorrection::class, Override::class] as $immutable) {
            $immutable::updating(fn ($record) => throw ImmutableRecordException::forModel($record));
        }
        Queue::addConnector('database', fn () => new GuardedDatabaseConnector($this->app['db']));
        Queue::addConnector('ai-database', fn () => new GuardedDatabaseConnector($this->app['db']));
        CriteriaVersion::saving(fn (CriteriaVersion $version) => app(CriteriaSnapshot::class)->saving($version));
        Extraction::saved(function (Extraction $extraction): void {
            if ($extraction->status === Extraction::STATUS_OK) {
                app(AiWorkCoordinator::class)->forDocument($extraction->document_id);
            }
        });
        DocumentSource::saved(function (DocumentSource $source): void {
            if ($source->application_id !== null && ($source->wasRecentlyCreated || $source->wasChanged('application_id'))) {
                app(AiWorkCoordinator::class)->forApplication($source->application_id);
            }
        });
        Application::updated(function (Application $application): void {
            if ($application->wasChanged('recruitment_job_id')) {
                app(AiWorkCoordinator::class)->forApplication($application->id);
            }
        });
        Activity::created(function (Activity $activity): void {
            if ($activity->event !== 'criteria.approved' || ! $activity->properties->get('rescore_required')) {
                return;
            }
            $versionId = $activity->subject_id;
            foreach (Application::query()->where('recruitment_job_id', $activity->properties->get('job_id'))->pluck('id') as $id) {
                RescoreApplication::dispatch((int) $id, $versionId)->afterCommit();
            }
        });
    }
}
