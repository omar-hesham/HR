<?php

namespace App\Filament\Resources\Applications\Pages;

use App\Filament\Resources\Applications\ApplicationResource;
use App\Models\Application;
use App\Models\User;
use App\Services\Review\CompareApplications as Comparison;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class CompareApplications extends Page
{
    protected static string $resource = ApplicationResource::class;

    /** @var list<int|string> */
    #[Url]
    public array $applications = [];

    #[Url]
    public ?int $version = null;

    public function getTitle(): string
    {
        return __('review.compare');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.review.compare')->viewData(fn (): array => ['data' => $this->reviewData()])]);
    }

    /** @return array<string,mixed> */
    protected function reviewData(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->can('viewAny', Application::class), 403);
        $first = ApplicationResource::getEloquentQuery()->with('job.criteriaVersions')->find($this->applications[0] ?? 0);
        $versions = $first?->job?->criteriaVersions->mapWithKeys(fn ($version): array => [$version->id => (string) $version->version])->all() ?? [];
        try {
            return ['rows' => app(Comparison::class)->data($user, $this->applications, $this->version), 'error' => null, 'versions' => $versions];
        } catch (ValidationException $exception) {
            return ['rows' => [], 'error' => $exception->errors()['selection'][0], 'versions' => $versions];
        }
    }
}
