<?php

namespace App\Filament\Resources\NeedsReview;

use App\Actions\Review\ResolveReviewItem;
use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Domain\Documents\Disposition;
use App\Domain\Documents\ReviewReason;
use App\Domain\Documents\SourceChannel;
use App\Filament\Resources\NeedsReview\Pages\ListNeedsReview;
use App\Models\Application;
use App\Models\DocumentSource;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Needs review" list (M2-P0-04, WF-P0-01): every unreadable, protected, image-only,
 * rejected or failed file stays visible with its reason until a person resolves it.
 */
class NeedsReviewResource extends Resource
{
    protected static ?string $model = DocumentSource::class;

    protected static ?string $slug = 'needs-review';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 5;

    public static function getModelLabel(): string
    {
        return __('app.review.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.review.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('app.review.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return Builder<DocumentSource>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = DocumentSource::query()->openReview()->with(['application.job', 'uploader', 'document']);

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission(Permission::ApplicationsViewAll)) {
            return $query;
        }

        $visibleApplications = JobScope::applyToApplications(Application::query(), $user)->select('id');

        return $query->where(function (Builder $q) use ($user, $visibleApplications): void {
            $q->whereIn('application_id', $visibleApplications);

            if ($user->hasPermission(Permission::ReviewManage)) {
                $q->orWhereNull('application_id');
            }
        });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('review_opened_at')->label(__('app.review.opened_at'))->dateTime()->sortable(),
                TextColumn::make('review_reason')
                    ->label(__('app.review.reason'))
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (ReviewReason $state): string => $state->label())
                    ->description(fn (DocumentSource $record): string => $record->review_reason !== null ? __('app.review.hint.'.$record->review_reason->value) : ''),
                TextColumn::make('original_filename_enc')
                    ->label(__('app.documents.filename'))
                    ->state(fn (DocumentSource $record): string => (string) $record->originalFilename())
                    ->extraAttributes(['dir' => 'auto'])
                    ->wrap(),
                TextColumn::make('source')
                    ->label(__('app.common.source'))
                    ->formatStateUsing(fn (SourceChannel $state): string => $state->label()),
                TextColumn::make('disposition')
                    ->label(__('app.review.disposition'))
                    ->formatStateUsing(fn (Disposition $state): string => $state->label()),
                TextColumn::make('application.job.title_ar')
                    ->label(__('app.jobs.singular'))
                    ->state(fn (DocumentSource $record): string => $record->application?->job?->title() ?? __('app.applications.unassigned')),
                TextColumn::make('uploader.name')->label(__('app.documents.uploaded_by'))->placeholder('—'),
                TextColumn::make('created_at')->label(__('app.common.received_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('review_opened_at')
            ->filters([
                SelectFilter::make('review_reason')->label(__('app.review.reason'))->options(ReviewReason::options()),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(__('app.documents.download'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (DocumentSource $record): ?string => $record->document !== null ? route('documents.download', ['document' => $record->document->uuid]) : null)
                    ->visible(fn (DocumentSource $record): bool => $record->document !== null && (auth()->user()?->can('download', $record->document) ?? false)),
                Action::make('retry')
                    ->label(__('app.review.retry'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->visible(fn (DocumentSource $record): bool => $record->document?->path !== null && (auth()->user()?->can('resolve', $record) ?? false))
                    ->action(function (DocumentSource $record): void {
                        app(ResolveReviewItem::class)->retry($record, self::user());
                        Notification::make()->success()->title(__('app.review.retry_queued'))->send();
                    }),
                Action::make('resolve')
                    ->label(__('app.review.resolve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (DocumentSource $record): bool => auth()->user()?->can('resolve', $record) ?? false)
                    ->schema([
                        Textarea::make('note')->label(__('app.review.note'))->helperText(__('app.review.note_help'))->required()->rows(3),
                    ])
                    ->action(function (DocumentSource $record, array $data): void {
                        app(ResolveReviewItem::class)->resolve($record, (string) $data['note'], self::user());
                        Notification::make()->success()->title(__('app.review.resolved'))->send();
                    }),
            ]);
    }

    private static function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNeedsReview::route('/'),
        ];
    }
}
