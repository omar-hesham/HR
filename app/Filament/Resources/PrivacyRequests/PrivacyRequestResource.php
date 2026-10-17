<?php

namespace App\Filament\Resources\PrivacyRequests;

use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Privacy\PrivacyAccess;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PrivacyRequestResource extends Resource
{
    protected static ?string $model = PrivacyRequest::class;

    public static function getModelLabel(): string
    {
        return __('ops.privacy_request');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ops.privacy_requests');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(PrivacyAccess::class)->allowed($u);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return Builder<PrivacyRequest> */
    public static function getEloquentQuery(): Builder
    {
        $u = auth()->user();
        abort_unless($u instanceof User, 403);

        return app(PrivacyAccess::class)->requests($u);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('id')->label(__('ops.request_ref')), TextColumn::make('type')->label(__('ops.type'))->formatStateUsing(fn (string $state): string => __('ops.types.'.$state)), TextColumn::make('status')->label(__('ops.status'))->formatStateUsing(fn (string $state): string => __('ops.states.'.$state)), TextColumn::make('verified_at')->label(__('ops.verified'))->dateTime('d/m/Y H:i')])->recordActions(Pages\ListPrivacyRequests::requestActions());
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPrivacyRequests::route('/')];
    }
}
