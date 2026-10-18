<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Actions\Review\RequestRescore;
use App\Domain\Access\Permission;
use App\Filament\Pages\UploadDocuments;
use App\Filament\Resources\Applications\ApplicationResource;
use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Review\CompareApplications as Comparison;
use App\Services\Review\ExportApplications;
use App\Services\Review\ReviewDashboard;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class ListApplications extends Page
{
    use WithPagination;

    protected static string $resource = ApplicationResource::class;

    #[Url]
    public string $job = '';

    #[Url]
    public string $band = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $source = '';

    #[Url]
    public string $exception = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    #[Url]
    public bool $history = false;

    /** @var list<string|int> */
    public array $selection = [];

    public function mount(): void
    {
        $this->actor();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.review.dashboard')->viewData(fn (): array => ['data' => $this->reviewData()])]);
    }

    public function getTitle(): string
    {
        return __('review.dashboard');
    }

    /** @return array<string,mixed> */
    protected function reviewData(): array
    {
        return app(ReviewDashboard::class)->data($this->actor(), [
            'job' => $this->job, 'band' => $this->band, 'status' => $this->status, 'source' => $this->source,
            'exception' => $this->exception, 'from' => $this->from, 'until' => $this->until,
        ]);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['job', 'band', 'status', 'source', 'exception', 'from', 'until'], true)) {
            $this->resetPage('rankPage');
        }
    }

    public function compareSelection(): void
    {
        app(Comparison::class)->data($this->actor(), $this->selection);
        $this->redirect(ApplicationResource::getUrl('compare', ['applications' => $this->selection]));
    }

    public function rescore(int $id): void
    {
        app(RequestRescore::class)->handle(Application::query()->findOrFail($id), $this->actor());
        Notification::make()->success()->title(__('review.rescore_queued'))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')->label(__('app.upload.title'))->url(UploadDocuments::getUrl())->visible(fn (): bool => UploadDocuments::canAccess()),
            Action::make('export')->label(__('review.export'))
                ->visible(fn (): bool => $this->actor()->hasPermission(Permission::ExportsAll) || $this->actor()->hasPermission(Permission::ExportsAssigned))
                ->schema([
                    Select::make('job')->label(__('app.jobs.singular'))->options(fn (): array => app(ReviewDashboard::class)->jobs($this->actor()))->required(),
                    Select::make('format')->label(__('review.format'))->options(['xlsx' => __('review.xlsx'), 'csv' => __('review.csv')])->default('xlsx')->required(),
                    Textarea::make('purpose')->label(__('review.purpose'))->helperText(__('review.export_helper'))->required()->maxLength(1000),
                    TextInput::make('password')->label(__('review.password'))->password()->autocomplete('current-password')
                        ->visible(fn (): bool => $this->actor()->hasPermission(Permission::ExportsAll))->required(fn (): bool => $this->actor()->hasPermission(Permission::ExportsAll)),
                ])->action(function (array $data): void {
                    $exports = app(ExportApplications::class);
                    if ($this->actor()->hasPermission(Permission::ExportsAll)) {
                        $exports->confirmPassword($this->actor(), $data['password'] ?? '');
                    }
                    $artifact = $exports->create($this->actor(), RecruitmentJob::query()->findOrFail($data['job']), $data['format'], $data['purpose']);
                    $this->redirect(route('review-exports.download', $artifact->id));
                }),
        ];
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isUsable() && $user->can('viewAny', Application::class), 403);

        return $user;
    }
}
