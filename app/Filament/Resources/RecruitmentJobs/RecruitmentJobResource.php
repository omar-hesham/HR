<?php

namespace App\Filament\Resources\RecruitmentJobs;

use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Filament\Resources\RecruitmentJobs\Pages\CreateRecruitmentJob;
use App\Filament\Resources\RecruitmentJobs\Pages\EditRecruitmentJob;
use App\Filament\Resources\RecruitmentJobs\Pages\ListRecruitmentJobs;
use App\Filament\Resources\RecruitmentJobs\Pages\ViewRecruitmentJob;
use App\Filament\Resources\RecruitmentJobs\RelationManagers\CriteriaVersionsRelationManager;
use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Jobs (M4). Titles and aliases live in the database, never in code (M4-P0-03).
 */
class RecruitmentJobResource extends Resource
{
    protected static ?string $model = RecruitmentJob::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('app.jobs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.jobs.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.configuration');
    }

    /**
     * @return Builder<RecruitmentJob>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = RecruitmentJob::query();
        $user = auth()->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasPermission(Permission::JobsManage) || $user->hasPermission(Permission::ApplicationsViewAll)) {
            return $query;
        }

        return $query->whereIn('id', $user->assignedJobIds());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('app.jobs.section_titles'))
                ->columns(2)
                ->schema([
                    TextInput::make('title_ar')->label(__('app.jobs.title_ar'))->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                    TextInput::make('title_en')->label(__('app.jobs.title_en'))->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                    TagsInput::make('aliases')->label(__('app.jobs.aliases'))->helperText(__('app.jobs.aliases_help'))->columnSpanFull(),
                    Textarea::make('description_ar')->label(__('app.jobs.description_ar'))->rows(3)->extraInputAttributes(['dir' => 'auto']),
                    Textarea::make('description_en')->label(__('app.jobs.description_en'))->rows(3)->extraInputAttributes(['dir' => 'auto']),
                ]),
            Section::make(__('app.jobs.section_options'))
                ->columns(2)
                ->schema([
                    Toggle::make('active')->label(__('app.jobs.active'))->default(true),
                    Toggle::make('name_blind_review')->label(__('app.jobs.name_blind_review'))->helperText(__('app.jobs.name_blind_help')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title_ar')
                    ->label(__('app.jobs.title'))
                    ->formatStateUsing(fn (RecruitmentJob $record): string => $record->title())
                    ->searchable(['title_ar', 'title_en'])
                    ->sortable(),
                TextColumn::make('currentCriteriaVersion.version')
                    ->label(__('app.criteria.current_version'))
                    ->placeholder(__('app.criteria.no_approved_version')),
                TextColumn::make('applications_count')
                    ->label(__('app.applications.plural'))
                    ->counts(['applications' => fn (Builder $query): Builder => self::scopeApplications($query)]),
                IconColumn::make('active')->label(__('app.jobs.active'))->boolean(),
                IconColumn::make('name_blind_review')->label(__('app.jobs.name_blind_short'))->boolean(),
                TextColumn::make('updated_at')->label(__('app.common.updated_at'))->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label(__('app.jobs.active')),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('title_ar');
    }

    /**
     * Counts never reveal applications outside the user's scope.
     *
     * @param  Builder<Application>  $query
     * @return Builder<Application>
     */
    private static function scopeApplications(Builder $query): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? JobScope::applyToApplications($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getRelations(): array
    {
        return [
            CriteriaVersionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecruitmentJobs::route('/'),
            'create' => CreateRecruitmentJob::route('/create'),
            'view' => ViewRecruitmentJob::route('/{record}'),
            'edit' => EditRecruitmentJob::route('/{record}/edit'),
        ];
    }
}
