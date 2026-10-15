<?php

namespace App\Filament\Resources\CriteriaVersions;

use App\Domain\Criteria\CriteriaVersionStatus;
use App\Filament\Resources\CriteriaVersions\Pages\ViewCriteriaVersion;
use App\Filament\Resources\CriteriaVersions\RelationManagers\CriteriaRelationManager;
use App\Models\CriteriaVersion;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One criteria version with its criteria editor. Reached from a job; not in the menu.
 */
class CriteriaVersionResource extends Resource
{
    protected static ?string $model = CriteriaVersion::class;

    protected static bool $shouldRegisterNavigation = false;

    public static function getModelLabel(): string
    {
        return __('app.criteria.version');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.criteria.versions');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(3)
                ->schema([
                    TextEntry::make('job.title_ar')
                        ->label(__('app.jobs.singular'))
                        ->formatStateUsing(fn (CriteriaVersion $record): string => $record->job->title()),
                    TextEntry::make('version')->label(__('app.criteria.version')),
                    TextEntry::make('status')
                        ->label(__('app.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (CriteriaVersionStatus $state): string => $state->label()),
                    TextEntry::make('approver.name')->label(__('app.criteria.approved_by'))->placeholder('—'),
                    TextEntry::make('approved_at')->label(__('app.criteria.approved_at'))->dateTime()->placeholder('—'),
                    TextEntry::make('creator.name')->label(__('app.criteria.created_by'))->placeholder('—'),
                    TextEntry::make('readonly_notice')
                        ->hiddenLabel()
                        ->state(__('app.criteria.readonly_notice'))
                        ->visible(fn (CriteriaVersion $record): bool => ! $record->isEditable())
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            CriteriaRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'view' => ViewCriteriaVersion::route('/{record}'),
        ];
    }
}
