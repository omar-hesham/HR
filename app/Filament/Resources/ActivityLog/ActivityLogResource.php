<?php

namespace App\Filament\Resources\ActivityLog;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Filament\Resources\ActivityLog\Pages\ListActivityLog;
use App\Models\Application;
use App\Models\Document;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Access and change log (M10-P0-03): who viewed/downloaded a candidate file, who
 * changed access, criteria or settings. Read-only. Properties never contain CV text.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activity-log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 95;

    public static function getModelLabel(): string
    {
        return __('app.activity.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.activity.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    /**
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = Activity::query()->with('causer');

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission(Permission::AccessLogView)) {
            return $query;
        }

        // Restricted grant: only file access events for candidates in the user's jobs.
        $candidateIds = JobScope::applyToApplications(Application::query(), $user)->select('candidate_id');

        return $query
            ->where('subject_type', (new Document)->getMorphClass())
            ->whereIn('subject_id', Document::query()->whereIn('candidate_id', $candidateIds)->select('id'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label(__('app.activity.when'))->dateTime()->sortable(),
                TextColumn::make('event')
                    ->label(__('app.activity.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : __('app.activity.events.'.str_replace('.', '_', $state))),
                TextColumn::make('causer.name')->label(__('app.activity.actor'))->placeholder(__('app.activity.system')),
                TextColumn::make('subject_type')
                    ->label(__('app.activity.subject'))
                    ->formatStateUsing(fn (?string $state, Activity $record): string => class_basename((string) $state).' #'.$record->subject_id),
                TextColumn::make('log_name')->label(__('app.activity.log')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')->label(__('app.activity.log'))->options([
                    'access' => 'access',
                    'documents' => 'documents',
                    'configuration' => 'configuration',
                ]),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLog::route('/'),
        ];
    }
}
