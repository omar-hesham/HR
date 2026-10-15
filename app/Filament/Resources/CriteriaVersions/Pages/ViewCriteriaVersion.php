<?php

namespace App\Filament\Resources\CriteriaVersions\Pages;

use App\Actions\Criteria\ApproveCriteriaVersion;
use App\Actions\Criteria\CriteriaValidationException;
use App\Filament\Resources\CriteriaVersions\CriteriaVersionResource;
use App\Filament\Resources\RecruitmentJobs\RecruitmentJobResource;
use App\Models\CriteriaVersion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewCriteriaVersion extends ViewRecord
{
    protected static string $resource = CriteriaVersionResource::class;

    public function getTitle(): string
    {
        $version = $this->version();

        return __('app.criteria.version_title', ['job' => $version->job->title(), 'version' => $version->version]);
    }

    /**
     * Versions have no list page of their own; the trail goes through the job.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $version = $this->version();

        return [
            RecruitmentJobResource::getUrl('index') => RecruitmentJobResource::getPluralModelLabel(),
            RecruitmentJobResource::getUrl('view', ['record' => $version->recruitment_job_id]) => $version->job->title(),
            '#' => __('app.criteria.version').' '.$version->version,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('app.criteria.approve'))
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('app.criteria.approve_confirm'))
                ->visible(fn (): bool => $this->user()->can('approve', $this->version()))
                ->action(function (Action $action): void {
                    try {
                        app(ApproveCriteriaVersion::class)->handle($this->version(), $this->user());
                    } catch (CriteriaValidationException $e) {
                        Notification::make()
                            ->danger()
                            ->title(__('app.criteria.approve_blocked'))
                            ->body(implode("\n", $e->messages()))
                            ->persistent()
                            ->send();

                        $action->halt();
                    }

                    Notification::make()->success()->title(__('app.criteria.approved_ok'))->send();
                    $this->redirect(CriteriaVersionResource::getUrl('view', ['record' => $this->version()]));
                }),
            Action::make('backToJob')
                ->label(__('app.criteria.back_to_job'))
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->url(fn (): string => RecruitmentJobResource::getUrl('view', ['record' => $this->version()->recruitment_job_id])),
        ];
    }

    private function version(): CriteriaVersion
    {
        /** @var CriteriaVersion $record */
        $record = $this->getRecord();

        return $record;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
