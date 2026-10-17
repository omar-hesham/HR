<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Review\WeeklyReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class WeeklySummary extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 12;

    #[Url]
    public string $week = '';

    public function mount(): void
    {
        $this->week = $this->week === '' ? now()->timezone('Africa/Cairo')->startOfWeek()->toDateString() : $this->week;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WeeklyReport::class)->allowed($user);
    }

    public static function getNavigationLabel(): string
    {
        return __('review.weekly');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    public function getTitle(): string
    {
        return __('review.weekly');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.review.weekly')->viewData(fn (): array => ['data' => $this->reviewData()])]);
    }

    /** @return array<string,mixed> */
    protected function reviewData(): array
    {
        return app(WeeklyReport::class)->data($this->actor(), $this->week);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pilot')->label(__('review.pilot_time'))
                ->schema([
                    DatePicker::make('week')->label(__('review.week'))->default(fn (): string => $this->week)->required(),
                    TextInput::make('minutes')->label(__('review.minutes'))->integer()->minValue(0)->maxValue(10080)->required(),
                ])->action(function (array $data): void {
                    app(WeeklyReport::class)->pilot($this->actor(), $data['week'], (int) $data['minutes']);
                    Notification::make()->success()->title(__('review.saved'))->send();
                }),
        ];
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && app(WeeklyReport::class)->allowed($user), 403);

        return $user;
    }
}
