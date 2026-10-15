<?php

namespace App\Filament\Resources\RecruitmentJobs\RelationManagers;

use App\Actions\Criteria\StartDraftVersion;
use App\Domain\Criteria\CriteriaVersionStatus;
use App\Filament\Resources\CriteriaVersions\CriteriaVersionResource;
use App\Models\CriteriaVersion;
use App\Models\RecruitmentJob;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Version history of a job's criteria. Old versions stay visible and read-only (M4-P0-02).
 */
class CriteriaVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'criteriaVersions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('app.criteria.versions');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->modelLabel(__('app.criteria.version'))
            ->pluralModelLabel(__('app.criteria.versions'))
            ->columns([
                TextColumn::make('version')->label(__('app.criteria.version'))->sortable(),
                TextColumn::make('status')
                    ->label(__('app.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (CriteriaVersionStatus $state): string => $state->label())
                    ->color(fn (CriteriaVersionStatus $state): string => match ($state) {
                        CriteriaVersionStatus::Approved => 'success',
                        CriteriaVersionStatus::Draft => 'warning',
                        CriteriaVersionStatus::Superseded => 'gray',
                    }),
                TextColumn::make('criteria_count')->label(__('app.criteria.plural'))->counts('criteria'),
                TextColumn::make('approver.name')->label(__('app.criteria.approved_by'))->placeholder('—'),
                TextColumn::make('approved_at')->label(__('app.criteria.approved_at'))->dateTime()->placeholder('—'),
                TextColumn::make('created_at')->label(__('app.common.created_at'))->dateTime(),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                Action::make('newDraft')
                    ->label(__('app.criteria.new_draft'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => $this->user()?->can('createFor', [CriteriaVersion::class, $this->job()]) ?? false)
                    ->action(function (): void {
                        $draft = app(StartDraftVersion::class)->handle($this->job(), $this->userOrFail());

                        Notification::make()->success()->title(__('app.criteria.draft_ready', ['version' => $draft->version]))->send();

                        $this->redirect(CriteriaVersionResource::getUrl('view', ['record' => $draft]));
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (CriteriaVersion $record): string => $record->isEditable() ? __('app.criteria.open_draft') : __('app.criteria.view_version'))
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (CriteriaVersion $record): string => CriteriaVersionResource::getUrl('view', ['record' => $record])),
            ]);
    }

    private function job(): RecruitmentJob
    {
        /** @var RecruitmentJob $job */
        $job = $this->getOwnerRecord();

        return $job;
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function userOrFail(): User
    {
        return $this->user() ?? abort(403);
    }
}
