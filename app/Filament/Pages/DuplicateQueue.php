<?php

namespace App\Filament\Pages;

use App\Actions\Review\ResolveDuplicate;
use App\Domain\Access\Permission;
use App\Models\User;
use App\Services\Review\DuplicateQueue as Queue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class DuplicateQueue extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User && auth()->user()->hasPermission(Permission::ReviewManage);
    }

    public static function getNavigationLabel(): string
    {
        return __('review.duplicates');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    public function getTitle(): string
    {
        return __('review.duplicates');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.review.duplicates')->viewData(fn (): array => ['pairs' => $this->reviewData()])]);
    }

    /** @return list<array<string,mixed>> */
    protected function reviewData(): array
    {
        return app(Queue::class)->pairs($this->actor());
    }

    public function resolveDuplicateAction(): Action
    {
        return Action::make('resolveDuplicate')->label(__('review.resolve_duplicate'))
            ->schema([
                Hidden::make('existing')->required(),
                Hidden::make('incoming')->required(),
                Select::make('resolution')->label(__('review.resolution'))->options([
                    'merge' => __('review.merge'), 'keep_two' => __('review.keep_two'), 'append_active' => __('review.append_active'),
                ])->required()->live(),
                Checkbox::make('recalculate')->label(__('review.confirm_recalculation'))
                    ->visible(fn ($get): bool => $get('resolution') === 'append_active')
                    ->accepted(fn ($get): bool => $get('resolution') === 'append_active'),
                Textarea::make('reason')->label(__('review.reason'))->required()->maxLength(5000),
            ])->fillForm(fn (array $arguments): array => ['existing' => $arguments['existing'] ?? null, 'incoming' => $arguments['incoming'] ?? null])
            ->action(function (array $data): void {
                app(ResolveDuplicate::class)->handle((int) $data['existing'], (int) $data['incoming'], $data['resolution'], $data['reason'], $this->actor(), (bool) ($data['recalculate'] ?? false));
                Notification::make()->success()->title(__('review.saved'))->send();
            });
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isUsable() && $user->hasPermission(Permission::ReviewManage), 403);

        return $user;
    }
}
