<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Actions\Review\AddNote;
use App\Actions\Review\RequestRescore;
use App\Actions\Scoring\OverrideScore;
use App\Domain\Access\Permission;
use App\Domain\Access\ReviewAccess;
use App\Domain\Applications\ApplicationStatus;
use App\Filament\Resources\Applications\ApplicationResource;
use App\Models\Application;
use App\Models\User;
use App\Services\Applications\ApplicationStatusService;
use App\Services\Review\ApplicationDetail;
use App\Services\Review\CorrectionForm;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ViewApplication extends ViewRecord
{
    use WithPagination;

    protected static string $resource = ApplicationResource::class;

    #[Url]
    public ?int $scoreId = null;

    public function getTitle(): string
    {
        return __('review.detail');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.review.detail')->viewData(fn (): array => ['data' => $this->reviewData()])]);
    }

    /** @return array<string,mixed> */
    protected function reviewData(): array
    {
        return app(ApplicationDetail::class)->data($this->actor(), $this->application(), $this->scoreId);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('status')->label(__('review.change_status'))
                ->visible(fn (): bool => ReviewAccess::statusOptions($this->actor(), $this->application()) !== [])
                ->schema([
                    Select::make('status')->label(__('app.common.status'))->options(fn (): array => ReviewAccess::statusOptions($this->actor(), $this->application()))->required(),
                    Textarea::make('reason')->label(__('review.reason'))->required()->maxLength(5000),
                ])->action(function (array $data): void {
                    $application = $this->application();
                    $to = ApplicationStatus::tryFrom($data['status']);
                    abort_unless($to !== null && ReviewAccess::transition($this->actor(), $application, $to), 403);
                    app(ApplicationStatusService::class)->transition($application, $to, $this->actor(), $data['reason']);
                    $this->done();
                }),
            Action::make('note')->label(__('review.add_note'))->visible(fn (): bool => $this->actor()->hasPermission(Permission::StatusChange))
                ->schema([Textarea::make('body')->label(__('review.note'))->required()->maxLength(10000)])
                ->action(function (array $data): void {
                    app(AddNote::class)->handle($this->application(), $this->actor(), $data['body']);
                    $this->done();
                }),
            Action::make('correct')->label(__('review.correct'))
                ->visible(fn (): bool => ReviewAccess::manage($this->actor(), $this->application()) && $this->application()->candidate->activeDocument?->latestExtraction?->fields_json_enc !== null)
                ->schema([
                    Select::make('path')->label(__('review.field'))->options(function (): array {
                        $extraction = $this->application()->candidate->activeDocument?->latestExtraction;

                        return $extraction === null ? [] : app(CorrectionForm::class)->paths($extraction);
                    })->required()->searchable(),
                    TextInput::make('value')->label(__('review.value'))->helperText(__('review.value_help'))->required(),
                    Textarea::make('evidence')->label(__('review.evidence'))->required(),
                    Textarea::make('reason')->label(__('review.reason'))->required()->maxLength(5000),
                ])->action(function (array $data): void {
                    app(CorrectionForm::class)->handle($this->application(), $this->actor(), $data['path'], $data['value'], $data['evidence'], $data['reason']);
                    $this->done();
                }),
            Action::make('override')->label(__('review.override'))
                ->visible(fn (): bool => $this->actor()->hasPermission(Permission::ScoresOverride) && $this->application()->currentScore !== null && ($this->scoreId === null || $this->scoreId === $this->application()->current_score_id))
                ->schema([
                    TextInput::make('value')->label(__('review.new_score'))->numeric()->minValue(0)->maxValue(100)->required(),
                    Textarea::make('reason')->label(__('review.reason'))->required()->maxLength(5000),
                ])->action(function (array $data): void {
                    $score = $this->application()->currentScore;
                    abort_unless($score !== null, 422);
                    app(OverrideScore::class)->handle($score, (string) $data['value'], $this->actor(), $data['reason']);
                    $this->done();
                }),
            Action::make('rescore')->label(__('review.rescore'))
                ->visible(fn (): bool => ReviewAccess::manage($this->actor(), $this->application()) && $this->application()->job?->current_criteria_version_id !== null)
                ->action(function (): void {
                    app(RequestRescore::class)->handle($this->application(), $this->actor());
                    $this->done();
                }),
        ];
    }

    private function application(): Application
    {
        $application = Application::query()->findOrFail($this->getRecord()->getKey());
        abort_unless($this->actor()->can('view', $application) && $application->status !== ApplicationStatus::Deleted, 403);

        return $application;
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isUsable(), 403);

        return $user;
    }

    private function done(): void
    {
        Notification::make()->success()->title(__('review.saved'))->send();
    }
}
