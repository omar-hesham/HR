<?php

namespace App\Filament\Pages;

use App\Domain\Access\Permission;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Intake health (M11-P0-01, brief §7): last poll per mailbox, counts per
 * outcome, open errors, quarantine and notice stats. Read-only; gated by
 * ops.health.view. All queries live in IntakeHealthSnapshot.
 */
class IntakeHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'intake-health';

    public static function getNavigationLabel(): string
    {
        return __('app.intake.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public function getTitle(): string
    {
        return __('app.intake.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::OpsHealthView);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Components\View::make('filament.pages.intake-health'),
        ]);
    }
}
