<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Operations\SystemHealthSnapshot;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class SystemHealth extends AiOperations
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isUsable() && $user->can('ops.health.view');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.health');
    }

    public function getTitle(): string
    {
        return __('ops.health');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.operations.health')->viewData(fn (): array => ['health' => app(SystemHealthSnapshot::class)->data($this->actor())]), ...parent::content($schema)->getComponents()]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('retry')->label(__('ops.retry'))->visible(fn (): bool => auth()->user()?->can('jobs.retry') ?? false)->schema([TextInput::make('uuid')->label(__('ops.job_ref'))->required()])->action(fn (array $data) => app(SystemHealthSnapshot::class)->retry($this->actor(), $data['uuid']))];
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && self::canAccess(), 403);

        return $actor;
    }
}
