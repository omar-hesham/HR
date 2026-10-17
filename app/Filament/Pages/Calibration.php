<?php

namespace App\Filament\Pages;

use App\Models\Application;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Calibration\Calibration as CalibrationService;
use App\Services\Calibration\HumanFieldVerification;
use App\Services\Review\ReviewApplications;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

class Calibration extends Page
{
    #[Url]
    public ?int $batch = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->isUsable() && $u->can('calibration.manage');
    }

    public static function getNavigationLabel(): string
    {
        return __('ops.calibration');
    }

    public function getTitle(): string
    {
        return __('ops.calibration');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    private function actor(): User
    {
        $u = auth()->user();
        abort_unless($u instanceof User && self::canAccess(), 403);

        return $u;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([View::make('filament.operations.calibration')->viewData(fn (): array => $this->calibrationData())]);
    }

    /** @return array<string,mixed> */
    protected function calibrationData(): array
    {
        $actor = $this->actor();
        $batches = DB::table('calibration_batches')->whereIn('recruitment_job_id', $actor->can('applications.view_all') ? RecruitmentJob::query()->select('id') : $actor->assignedJobIds())->orderByDesc('id')->get();
        if ($this->batch === null) {
            return ['batches' => $batches, 'entries' => [], 'metrics' => null];
        }
        $batch = DB::table('calibration_batches')->where('id', $this->batch)->first();
        abort_unless($batch !== null, 404);
        app(CalibrationService::class)->authorize($actor, $batch->recruitment_job_id);

        return ['batches' => $batches, 'entries' => app(CalibrationService::class)->blindEntries($actor, $this->batch), 'metrics' => $batch->locked_at === null ? null : app(CalibrationService::class)->results($actor, $this->batch)];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('start')->label(__('ops.start_calibration'))->schema([
                Select::make('sample_type')->label(__('ops.sample_type'))->options(['synthetic' => __('ops.synthetic_sample'), 'egypt_pilot' => __('ops.egypt_pilot')])->default('synthetic')->required(),
                Select::make('job')->label(__('ops.job'))->options(fn (): array => RecruitmentJob::query()->when(! $this->actor()->can('applications.view_all'), fn ($q) => $q->whereIn('id', $this->actor()->assignedJobIds()))->pluck('title_en', 'id')->all())->required(),
                Select::make('applications')->label(__('ops.applications'))->multiple()->options(fn (): array => app(ReviewApplications::class)->visible($this->actor())->get()->mapWithKeys(fn (Application $a): array => [$a->id => '#'.$a->id])->all())->required(),
            ])->action(function (array $data): void {
                $this->batch = app(CalibrationService::class)->start($this->actor(), RecruitmentJob::query()->findOrFail($data['job']), array_map('intval', $data['applications']), $data['sample_type']);
            }),
            Action::make('input')->label(__('ops.blind_input'))->visible(fn (): bool => $this->batch !== null)->schema([TextInput::make('application')->label(__('ops.application'))->integer()->required(), TextInput::make('rank')->label(__('ops.manager_rank'))->numeric()->minValue(.001)->maxValue(100)->required(), Toggle::make('suitable')->label(__('ops.suitable'))])->action(fn (array $data) => app(CalibrationService::class)->input($this->actor(), (int) $this->batch, (int) $data['application'], (float) $data['rank'], (bool) $data['suitable'])),
            Action::make('checkpoint')->label(__('ops.week3'))->visible(fn (): bool => $this->actor()->can('candidates.delete_approve') && $this->batch !== null)->schema([Select::make('outcome')->label(__('ops.outcome'))->options(['approved' => __('ops.states.approved'), 'rules_only' => __('ops.rules_only')])->required(), Textarea::make('reason')->label(__('ops.reason'))->required()])->action(fn (array $data) => app(CalibrationService::class)->checkpoint($this->actor(), (int) $this->batch, $data['outcome'], $data['reason'])),
            Action::make('verify_fields')->label(__('ops.verify_fields'))->schema([TextInput::make('application')->label(__('ops.application'))->integer()->required(), Textarea::make('payload')->label(__('ops.manual_fields'))->helperText(__('ops.manual_fields_help'))->json(), Textarea::make('reason')->label(__('ops.reason'))->required()])->action(function (array $data): void {
                $application = Application::query()->findOrFail($data['application']);
                if (trim((string) ($data['payload'] ?? '')) !== '') {
                    app(HumanFieldVerification::class)->enter($this->actor(), $application, json_decode($data['payload'], true, 512, JSON_THROW_ON_ERROR), $data['reason']);
                } else {
                    app(HumanFieldVerification::class)->verify($this->actor(), $application, $data['reason']);
                }
            }),
        ];
    }
}
