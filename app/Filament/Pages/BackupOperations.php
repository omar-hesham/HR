<?php

namespace App\Filament\Pages;

use App\Models\BackupRun;
use App\Models\User;
use App\Services\Operations\Backups;
use App\Services\Operations\RestoreVerification;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class BackupOperations extends Page
{
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->isUsable() && $u->can('backups.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.backups');
    }

    public function getTitle(): string
    {
        return __('ops.backups');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(), 403);

        return $schema->components([View::make('filament.operations.backups')->viewData(['runs' => BackupRun::query()->latest('id')->limit(30)->get()])]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('backup')->label(__('ops.backups'))->action(function (): void {
            abort_unless(self::canAccess(), 403);
            app(Backups::class)->create();
        }), Action::make('restore')->label(__('ops.checks.restore'))->schema([TextInput::make('run')->label(__('ops.job_ref'))->integer()->required()])->action(function (array $data): void {
            abort_unless(self::canAccess(), 403);
            app(RestoreVerification::class)->verify(BackupRun::query()->findOrFail($data['run']));
        })];
    }
}
