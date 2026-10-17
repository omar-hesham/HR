<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;

class PrivacyOperationsSettings extends Page
{
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->isUsable() && $u->can('settings.business') && $u->can('candidates.delete_approve');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.settings_title');
    }

    public function getTitle(): string
    {
        return __('ops.settings_title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.configuration');
    }

    protected function getHeaderActions(): array
    {
        $fields = [];
        foreach (app(SettingsRepository::class)->all() as $key => $value) {
            if (str_starts_with($key, 'operations.') || str_starts_with($key, 'processing.')) {
                $fields[] = Textarea::make(str_replace('.', '_', $key))->label(__('ops.settings.'.str_replace('.', '_', $key)))->default($value)->maxLength(2000);
            }
        }

        return [Action::make('edit')->label(__('app.common.save'))->schema($fields)->action(function (array $data): void {
            abort_unless(self::canAccess(), 403);
            $values = [];
            foreach (app(SettingsRepository::class)->defaults() as $key => $default) {
                if (str_starts_with($key, 'operations.') || str_starts_with($key, 'processing.')) {
                    $values[$key] = (string) ($data[str_replace('.', '_', $key)] ?? '');
                }
            }
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);
            app(SettingsRepository::class)->update($values, $actor);
        })];
    }
}
