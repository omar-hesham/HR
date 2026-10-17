<?php

namespace App\Filament\Pages;

use App\Domain\Access\Permission;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Business settings (M11-P0-03): retention (two tracks), text-quality thresholds,
 * upload limits, default language. Owner only; every change is versioned and logged.
 *
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 99;

    protected static ?string $slug = 'settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('app.settings.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public function getTitle(): string
    {
        return __('app.settings.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasPermission(Permission::SettingsBusiness);
    }

    public function mount(): void
    {
        $values = [];

        foreach (app(SettingsRepository::class)->all() as $key => $value) {
            if (str_starts_with($key, 'ai.') || str_starts_with($key, 'scoring.')) {
                continue;
            }
            data_set($values, $key, $value);
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $days = fn (string $name, string $label): TextInput => TextInput::make($name)
            ->label(__($label))->integer()->required()->minValue(1)->maxValue(3650)->suffix(__('app.settings.days'));
        $ratio = fn (string $name, string $label): TextInput => TextInput::make($name)
            ->label(__($label))->numeric()->required()->minValue(0)->maxValue(1)->step(0.01);

        return $schema
            ->components([
                Section::make(__('app.settings.section_retention'))
                    ->description(__('app.settings.retention_help'))
                    ->columns(3)
                    ->schema([
                        $days('retention.applicant_days', 'app.settings.retention_applicant_days'),
                        $days('retention.idle_days', 'app.settings.retention_idle_days'),
                        $days('retention.hard_max_days', 'app.settings.retention_hard_max_days')
                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                if ((int) $value < (int) $get('retention.applicant_days')) {
                                    $fail(__('app.settings.hard_max_too_small'));
                                }
                            }),
                        $days('retention.owner_notice_days', 'app.settings.retention_owner_notice_days'),
                        $days('retention.hired_handover_days', 'app.settings.retention_hired_handover_days'),
                    ]),
                Section::make(__('app.settings.section_quality'))
                    ->description(__('app.settings.quality_help'))
                    ->columns(4)
                    ->schema([
                        $ratio('quality.min_letter_ratio', 'app.settings.min_letter_ratio'),
                        $ratio('quality.min_wordlist_ratio', 'app.settings.min_wordlist_ratio'),
                        $ratio('quality.max_reversed_ratio', 'app.settings.max_reversed_ratio'),
                        TextInput::make('quality.min_letters')->label(__('app.settings.min_letters'))->integer()->required()->minValue(0)->maxValue(10000),
                    ]),
                Section::make(__('app.settings.section_upload'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('upload.max_file_mb')->label(__('app.settings.max_file_mb'))->integer()->required()->minValue(1)->maxValue(50),
                        TextInput::make('upload.max_batch_files')->label(__('app.settings.max_batch_files'))->integer()->required()->minValue(1)->maxValue(200),
                        TextInput::make('upload.max_batches_per_hour')->label(__('app.settings.max_batches_per_hour'))->integer()->required()->minValue(1)->maxValue(100),
                    ]),
                Section::make(__('app.settings.section_general'))
                    ->columns(3)
                    ->schema([
                        Select::make('ui.default_locale')->label(__('app.settings.default_locale'))->options([
                            'ar' => __('app.locale.ar'),
                            'en' => __('app.locale.en'),
                        ])->required(),
                        Toggle::make('privacy.name_blind_default')->label(__('app.settings.name_blind_default')),
                        Toggle::make('privacy.keep_id_card_attachments')->label(__('app.settings.keep_id_cards'))->helperText(__('app.settings.keep_id_cards_help')),
                    ]),
                Section::make(__('app.settings.section_intake'))
                    ->description(__('app.settings.notice_help'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('intake.silence_alert_hours')->label(__('app.settings.silence_alert_hours'))->integer()->required()->minValue(1)->maxValue(24 * 30),
                        TextInput::make('intake.privacy_notice_resend_days')->label(__('app.settings.privacy_notice_resend_days'))->integer()->required()->minValue(1)->maxValue(365),
                        TextInput::make('privacy.notice_version')->label(__('app.settings.notice_version'))->required()->maxLength(32),
                        Textarea::make('privacy.notice_text_ar')->label(__('app.settings.notice_text_ar'))->columnSpan(3)->rows(4),
                        Textarea::make('privacy.notice_text_en')->label(__('app.settings.notice_text_en'))->columnSpan(3)->rows(4),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label(__('app.common.save'))->submit('save'),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $repository = app(SettingsRepository::class);
        $values = [];

        foreach (array_keys($repository->defaults()) as $key) {
            if (str_starts_with($key, 'ai.') || str_starts_with($key, 'scoring.')) {
                continue;
            }
            if (data_get($state, $key) !== null) {
                $values[$key] = data_get($state, $key);
            }
        }

        $user = auth()->user();
        $repository->update($values, $user instanceof User ? $user : null);

        Notification::make()->success()->title(__('app.settings.saved'))->send();
    }
}
