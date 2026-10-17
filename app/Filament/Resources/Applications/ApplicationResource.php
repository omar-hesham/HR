<?php

namespace App\Filament\Resources\Applications;

use App\Domain\Access\JobScope;
use App\Domain\Applications\ApplicationStatus;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Models\Application;
use App\Models\Document;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Ai\AiWorkCoordinator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * All applications the user may see (scoped server-side by JobScope). The ranked
 * review dashboard comes in Milestone 4; this list proves intake and statuses.
 */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('app.applications.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.applications.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    /**
     * @return Builder<Application>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = Application::query()->with(['candidate.activeDocument', 'job']);

        return $user instanceof User ? JobScope::applyToApplications($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('candidate.name')
                    ->label(__('app.candidates.singular'))
                    ->state(fn (Application $record): string => $record->candidate->displayName())
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('job.title_ar')
                    ->label(__('app.jobs.singular'))
                    ->state(fn (Application $record): string => $record->job?->title() ?? __('app.applications.unassigned'))
                    ->badge(fn (Application $record): bool => $record->job === null)
                    ->color(fn (Application $record): ?string => $record->job === null ? 'warning' : null),
                TextColumn::make('status')
                    ->description(fn (Application $record): ?string => app(AiWorkCoordinator::class)->labelForApplication($record->id))
                    ->label(__('app.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (ApplicationStatus $state): string => $state->label())
                    ->color(fn (ApplicationStatus $state): string => match ($state) {
                        ApplicationStatus::NeedsReview => 'danger',
                        ApplicationStatus::Processing, ApplicationStatus::Received => 'warning',
                        ApplicationStatus::Shortlisted, ApplicationStatus::Hired => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('review_reason')
                    ->label(__('app.review.reason'))
                    ->formatStateUsing(fn (?ReviewReason $state): string => $state?->label() ?? '')
                    ->placeholder('—'),
                TextColumn::make('source')
                    ->label(__('app.common.source'))
                    ->formatStateUsing(fn (SourceChannel $state): string => $state->label()),
                TextColumn::make('received_at')->label(__('app.common.received_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('received_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label(__('app.common.status'))->options(ApplicationStatus::options()),
                SelectFilter::make('recruitment_job_id')
                    ->label(__('app.jobs.singular'))
                    ->options(fn (): array => RecruitmentJob::query()->get()->mapWithKeys(fn (RecruitmentJob $job): array => [$job->id => $job->title()])->all()),
                SelectFilter::make('source')->label(__('app.common.source'))->options(SourceChannel::options()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('app.documents.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (Application $record): ?string => ($document = self::cv($record)) !== null ? route('documents.download', ['document' => $document->uuid]) : null)
                    ->visible(fn (Application $record): bool => ($document = self::cv($record)) !== null && (auth()->user()?->can('download', $document) ?? false)),
            ]);
    }

    private static function cv(Application $application): ?Document
    {
        return $application->candidate->activeDocument;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
        ];
    }
}
