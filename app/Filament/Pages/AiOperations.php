<?php

namespace App\Filament\Pages;

use App\Domain\Access\Permission;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** @property-read Schema $form */
class AiOperations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?int $navigationSort = 61;

    /** @var array<string,mixed>|null */
    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('ai.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public function getTitle(): string
    {
        return __('ai.title');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ($user->hasPermission(Permission::OpsHealthView) || $user->can('ai.pipeline.manage'));
    }

    public static function canManage(): bool
    {
        return auth()->user()?->can('ai.pipeline.manage') ?? false;
    }

    public function mount(): void
    {
        if (! self::canManage()) {
            return;
        }
        $values = [];
        foreach (app(SettingsRepository::class)->all() as $key => $value) {
            if (str_starts_with($key, 'ai.') || str_starts_with($key, 'scoring.')) {
                data_set($values, $key, $value);
            }
        }
        $values['synonyms_json'] = json_encode(data_get($values, 'scoring.synonyms'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        $fields = [Toggle::make('ai.enabled')->label(__('ai.settings.enabled'))];
        foreach (['ai.monthly_cap_egp', 'ai.breaker_threshold', 'ai.recovery_seconds', 'ai.chars_per_token', 'ai.max_output_tokens', 'ai.input_egp_per_1k', 'ai.output_egp_per_1k', 'scoring.strong', 'scoring.review', 'scoring.confidence'] as $key) {
            $fields[] = TextInput::make($key)->label(__('ai.settings.'.str_replace('.', '_', $key)))->numeric()->required();
        }
        foreach (['ai.legal_approval_ref', 'ai.military_legal_ref', 'ai.minimum_age_legal_ref'] as $key) {
            $fields[] = TextInput::make($key)->label(__('ai.settings.'.str_replace('.', '_', $key)))->maxLength(128);
        }
        $fields[] = Toggle::make('ai.military_enabled')->label(__('ai.settings.military_enabled'));
        $fields[] = Textarea::make('synonyms_json')->label(__('ai.settings.synonyms'))->json()->columnSpanFull();

        return $schema->components($fields)->columns(3)->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        $components = [View::make('filament.pages.ai-operations')];
        if (self::canManage()) {
            $components[] = Section::make(__('ai.settings.title'))->schema([Form::make([EmbeddedSchema::make('form')])->livewireSubmitHandler('save')->footer([Actions::make([Action::make('save')->label(__('app.common.save'))->submit('save')])])]);
        }

        return $schema->components($components);
    }

    public function save(): void
    {
        abort_unless(self::canManage(), 403);
        $state = $this->form->getState();
        $values = [];
        $repository = app(SettingsRepository::class);
        foreach (array_keys($repository->defaults()) as $key) {
            if (str_starts_with($key, 'ai.') || str_starts_with($key, 'scoring.')) {
                $value = data_get($state, $key);
                if ($value !== null) {
                    $values[$key] = $value;
                }
            }
        }
        $values['scoring.synonyms'] = json_decode($state['synonyms_json'], true, 512, JSON_THROW_ON_ERROR);
        foreach (['ai.legal_approval_ref', 'ai.military_legal_ref', 'ai.minimum_age_legal_ref'] as $key) {
            $values[$key] = (string) data_get($state, $key, '');
        }
        $actor = auth()->user();
        $repository->update($values, $actor instanceof User ? $actor : null);
        Notification::make()->success()->title(__('app.settings.saved'))->send();
    }
}
