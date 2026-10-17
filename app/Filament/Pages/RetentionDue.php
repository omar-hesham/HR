<?php

namespace App\Filament\Pages;

use App\Models\Application;
use App\Models\User;
use App\Services\Privacy\HiredHandover;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

class RetentionDue extends Page
{
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->isUsable() && $u->can('retention.due_view') && $u->can('candidates.delete_approve');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.retention_due');
    }

    public function getTitle(): string
    {
        return __('ops.retention_due');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('handover')->label(__('ops.handover'))->schema([TextInput::make('application')->label(__('ops.application'))->integer()->required(), Textarea::make('reason')->label(__('ops.reason'))->helperText(__('ops.handover_confirm'))->required()])->action(function (array $data) {
            abort_unless(self::canAccess(), 403);
            $actor = auth()->user();
            abort_unless($actor instanceof User, 403);
            $id = app(HiredHandover::class)->record($actor, Application::query()->findOrFail($data['application']), $data['reason']);

            return redirect()->route('privacy-artifacts.download', ['artifact' => $id]);
        })];
    }

    public function content(Schema $schema): Schema
    {
        abort_unless(self::canAccess(), 403);

        return $schema->components([View::make('filament.operations.retention')->viewData(['applications' => Application::query()->where('status', '!=', 'deleted')->where('retention_due_at', '<=', now())->orderBy('retention_due_at')->get(), 'notices' => DB::table('retention_notices')->orderBy('hard_due_at')->get()])]);
    }
}
