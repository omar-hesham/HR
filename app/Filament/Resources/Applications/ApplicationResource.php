<?php

namespace App\Filament\Resources\Applications;

use App\Filament\Resources\Applications\Pages\CompareApplications;
use App\Filament\Resources\Applications\Pages\ListApplications;
use App\Filament\Resources\Applications\Pages\ViewApplication;
use App\Models\Application;
use App\Models\User;
use App\Services\Review\ReviewApplications;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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

    /** @return Builder<Application> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? app(ReviewApplications::class)->visible($user)
            : Application::query()->whereRaw('1 = 0');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApplications::route('/'),
            'compare' => CompareApplications::route('/compare'),
            'view' => ViewApplication::route('/{record}'),
        ];
    }
}
