<?php

namespace App\Filament\Pages;

use App\Actions\Documents\UploadDocuments as UploadDocumentsAction;
use App\Actions\Documents\UploadLimitExceeded;
use App\Domain\Access\JobScope;
use App\Domain\Access\Permission;
use App\Domain\Documents\Disposition;
use App\Filament\Resources\NeedsReview\NeedsReviewResource;
use App\Models\DocumentSource;
use App\Models\RecruitmentJob;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Manual single/bulk upload (M2-P0-01). Files go straight to the UploadDocuments action
 * (no Filament storage, so nothing unencrypted lands on a disk we keep); Livewire's
 * private temp copy is deleted right after.
 *
 * @property-read Schema $form
 */
class UploadDocuments extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'upload';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('app.upload.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.screening');
    }

    public function getTitle(): string
    {
        return __('app.upload.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::DocumentsUpload);
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        $settings = app(SettingsRepository::class);

        return $schema
            ->components([
                Select::make('recruitment_job_id')
                    ->label(__('app.jobs.singular'))
                    ->options(fn (): array => $this->jobOptions())
                    ->placeholder(__('app.upload.no_job'))
                    ->required(fn (): bool => ! $this->user()->hasPermission(Permission::ApplicationsViewUnassigned) && ! $this->user()->hasPermission(Permission::ApplicationsViewAll))
                    ->helperText(__('app.upload.job_help')),
                FileUpload::make('files')
                    ->label(__('app.upload.files'))
                    ->helperText(__('app.upload.files_help', [
                        'mb' => $settings->int('upload.max_file_mb'),
                        'count' => $settings->int('upload.max_batch_files'),
                    ]))
                    ->multiple()
                    ->maxFiles($settings->int('upload.max_batch_files'))
                    ->storeFiles(false)
                    ->required(),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('upload')
                ->footer([
                    Actions::make([
                        Action::make('upload')->label(__('app.upload.submit'))->submit('upload'),
                    ]),
                ]),
        ]);
    }

    public function upload(): void
    {
        $data = $this->form->getState();
        $user = $this->user();
        $jobId = $data['recruitment_job_id'] ?? null;
        $job = $jobId !== null ? RecruitmentJob::query()->find($jobId) : null;

        /** @var list<UploadedFile> $files */
        $files = array_values(array_filter((array) ($data['files'] ?? []), fn ($f): bool => $f instanceof UploadedFile));

        try {
            $result = app(UploadDocumentsAction::class)->handle($files, $user, $job);
        } catch (UploadLimitExceeded $e) {
            Notification::make()->danger()->title(__($e->translationKey, ['limit' => $e->limit]))->send();

            return;
        } finally {
            foreach ($files as $file) {
                if ($file instanceof TemporaryUploadedFile) {
                    $file->delete();
                }
            }
        }

        $lines = [];

        foreach ($result->items as $item) {
            $source = DocumentSource::query()->find($item['source_id']);
            $name = (string) $source?->originalFilename();
            $outcome = $item['disposition']->label().($item['reason'] !== null ? ' — '.$item['reason']->label() : '');
            $lines[] = "\u{2068}{$name}\u{2069}: {$outcome}";
        }

        $hasProblems = $result->count(Disposition::Rejected) > 0;

        Notification::make()
            ->title(__('app.upload.done', [
                'stored' => $result->count(Disposition::Stored),
                'duplicates' => $result->count(Disposition::DuplicateLinked),
                'rejected' => $result->count(Disposition::Rejected),
            ]))
            ->body(implode("\n", $lines))
            ->status($hasProblems ? 'warning' : 'success')
            ->actions($hasProblems ? [
                Action::make('review')->label(__('app.review.plural'))->url(NeedsReviewResource::getUrl()),
            ] : [])
            ->persistent()
            ->send();

        $this->form->fill(['recruitment_job_id' => $jobId]);
    }

    /**
     * @return array<int, string>
     */
    private function jobOptions(): array
    {
        $user = $this->user();

        return RecruitmentJob::query()
            ->where('active', true)
            ->orderBy('title_ar')
            ->get()
            ->filter(fn (RecruitmentJob $job): bool => JobScope::canWorkOnJob($user, $job->id))
            ->mapWithKeys(fn (RecruitmentJob $job): array => [$job->id => $job->title()])
            ->all();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
