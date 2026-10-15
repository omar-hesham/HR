<?php

namespace App\Filament\Resources\CriteriaVersions\RelationManagers;

use App\Actions\Criteria\CriteriaValidationException;
use App\Actions\Criteria\SaveCriterion;
use App\Domain\Criteria\CriterionField;
use App\Domain\Criteria\CriterionOperator;
use App\Domain\Criteria\CriterionType;
use App\Models\CriteriaVersion;
use App\Models\Criterion;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Criteria editor (M4-P0-01/03). All validation happens in SaveCriterion /
 * CriteriaValidator; this class only maps form fields to the action input.
 */
class CriteriaRelationManager extends RelationManager
{
    protected static string $relationship = 'criteria';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('app.criteria.plural');
    }

    public function isReadOnly(): bool
    {
        return ! $this->canEditVersion();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                Select::make('type')
                    ->label(__('app.criteria.type'))
                    ->options(CriterionType::options())
                    ->required()
                    ->live(),
                Select::make('field')
                    ->label(__('app.criteria.field'))
                    ->options(CriterionField::options())
                    ->helperText(__('app.criteria.field_help'))
                    ->required()
                    ->live(),
                Select::make('operator')
                    ->label(__('app.criteria.operator'))
                    ->options(fn (Get $get): array => self::operatorOptions($get('field')))
                    ->required()
                    ->live(),
            ]),
            Grid::make(3)->schema([
                Select::make('target_value_choice')
                    ->label(__('app.criteria.target_value'))
                    ->options(fn (Get $get): array => self::choiceOptions($get('field')))
                    ->visible(fn (Get $get): bool => self::needs($get, 'single') && self::hasChoices($get('field'))),
                TextInput::make('target_value')
                    ->label(__('app.criteria.target_value'))
                    ->numeric()
                    ->minValue(0)
                    ->visible(fn (Get $get): bool => self::needs($get, 'single') && ! self::hasChoices($get('field'))),
                Select::make('target_values_choice')
                    ->label(__('app.criteria.target_values'))
                    ->multiple()
                    ->options(fn (Get $get): array => self::choiceOptions($get('field')))
                    ->visible(fn (Get $get): bool => self::needs($get, 'list') && self::hasChoices($get('field'))),
                TagsInput::make('target_values')
                    ->label(__('app.criteria.target_values'))
                    ->helperText(__('app.criteria.target_values_help'))
                    ->visible(fn (Get $get): bool => self::needs($get, 'list') && ! self::hasChoices($get('field'))),
                TextInput::make('target_min')
                    ->label(__('app.criteria.target_min'))
                    ->numeric()->minValue(0)
                    ->visible(fn (Get $get): bool => self::needs($get, 'range')),
                TextInput::make('target_max')
                    ->label(__('app.criteria.target_max'))
                    ->numeric()->minValue(0)
                    ->visible(fn (Get $get): bool => self::needs($get, 'range')),
                TextInput::make('weight')
                    ->label(__('app.criteria.weight'))
                    ->helperText(__('app.criteria.weight_help'))
                    ->integer()->minValue(1)->maxValue(100)
                    ->visible(fn (Get $get): bool => $get('type') === CriterionType::NiceToHave->value),
            ]),
            Grid::make(2)->schema([
                TextInput::make('label_ar')->label(__('app.criteria.label_ar'))->required()->maxLength(255)->extraInputAttributes(['dir' => 'rtl']),
                TextInput::make('label_en')->label(__('app.criteria.label_en'))->required()->maxLength(255)->extraInputAttributes(['dir' => 'ltr']),
                Textarea::make('justification')->label(__('app.criteria.justification'))->helperText(__('app.criteria.justification_help'))->required()->rows(2)->extraInputAttributes(['dir' => 'auto']),
                Textarea::make('legal_basis_note')->label(__('app.criteria.legal_basis_note'))->helperText(__('app.criteria.legal_basis_help'))->rows(2)->extraInputAttributes(['dir' => 'auto']),
            ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('app.criteria.singular'))
            ->pluralModelLabel(__('app.criteria.plural'))
            ->reorderable(fn (): bool => false)
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('type')
                    ->label(__('app.criteria.type'))
                    ->badge()
                    ->formatStateUsing(fn (CriterionType $state): string => $state->label())
                    ->color(fn (CriterionType $state): string => match ($state) {
                        CriterionType::MustHave => 'primary',
                        CriterionType::NiceToHave => 'info',
                        CriterionType::Disqualifier => 'danger',
                    }),
                TextColumn::make('label_ar')
                    ->label(__('app.criteria.label'))
                    ->formatStateUsing(fn (Criterion $record): string => $record->label())
                    ->extraAttributes(['dir' => 'auto'])
                    ->wrap(),
                TextColumn::make('field')
                    ->label(__('app.criteria.field'))
                    ->formatStateUsing(fn (string $state): string => CriterionField::tryFrom($state)?->label() ?? $state),
                TextColumn::make('operator')
                    ->label(__('app.criteria.operator'))
                    ->formatStateUsing(fn (CriterionOperator $state): string => $state->label()),
                TextColumn::make('target_json')
                    ->label(__('app.criteria.target'))
                    ->state(fn (Criterion $record): string => self::describeTarget($record))
                    ->extraAttributes(['dir' => 'auto']),
                TextColumn::make('weight')->label(__('app.criteria.weight'))->placeholder(__('app.criteria.gate')),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn (): bool => $this->canEditVersion())
                    ->using(function (array $data, CreateAction $action): Criterion {
                        return $this->save($data, null, $action);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (): bool => $this->canEditVersion())
                    ->fillForm(fn (Criterion $record): array => self::toForm($record))
                    ->using(function (Criterion $record, array $data, EditAction $action): Criterion {
                        return $this->save($data, $record, $action);
                    }),
                DeleteAction::make()
                    ->visible(fn (): bool => $this->canEditVersion())
                    ->using(function (Criterion $record): bool {
                        app(SaveCriterion::class)->delete($record, $this->user());

                        return true;
                    }),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(array $data, ?Criterion $criterion, Action $action): Criterion
    {
        try {
            return app(SaveCriterion::class)->handle($this->version(), self::toInput($data), $this->user(), $criterion);
        } catch (CriteriaValidationException $e) {
            Notification::make()
                ->danger()
                ->title(__('app.criteria.save_blocked'))
                ->body(implode("\n", $e->messages()))
                ->persistent()
                ->send();

            $action->halt();

            throw $e;
        }
    }

    /**
     * Form state → action input (target JSON shape per operator).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toInput(array $data): array
    {
        $operator = CriterionOperator::tryFrom((string) ($data['operator'] ?? ''));
        $field = CriterionField::tryFrom((string) ($data['field'] ?? ''));
        $choices = $field !== null && self::hasChoices($field->value);

        $target = match ($operator) {
            CriterionOperator::Gte, CriterionOperator::Lte, CriterionOperator::Equals => [
                'value' => $choices ? ($data['target_value_choice'] ?? null) : (isset($data['target_value']) && $data['target_value'] !== '' ? (float) $data['target_value'] : null),
            ],
            CriterionOperator::In, CriterionOperator::ContainsAny, CriterionOperator::ContainsAll => [
                'values' => array_values((array) ($choices ? ($data['target_values_choice'] ?? []) : ($data['target_values'] ?? []))),
            ],
            CriterionOperator::Between => [
                'min' => isset($data['target_min']) && $data['target_min'] !== '' ? (float) $data['target_min'] : null,
                'max' => isset($data['target_max']) && $data['target_max'] !== '' ? (float) $data['target_max'] : null,
            ],
            default => [],
        };

        return [
            'type' => $data['type'] ?? '',
            'field' => $data['field'] ?? '',
            'operator' => $data['operator'] ?? '',
            'target' => $target,
            'weight' => ($data['type'] ?? null) === CriterionType::NiceToHave->value ? ($data['weight'] ?? null) : null,
            'label_ar' => $data['label_ar'] ?? '',
            'label_en' => $data['label_en'] ?? '',
            'justification' => $data['justification'] ?? '',
            'legal_basis_note' => $data['legal_basis_note'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toForm(Criterion $record): array
    {
        $target = $record->target_json ?? [];
        $choices = self::hasChoices($record->field);

        return [
            'type' => $record->type->value,
            'field' => $record->field,
            'operator' => $record->operator->value,
            'target_value' => $choices ? null : ($target['value'] ?? null),
            'target_value_choice' => $choices ? ($target['value'] ?? null) : null,
            'target_values' => $choices ? [] : ($target['values'] ?? []),
            'target_values_choice' => $choices ? ($target['values'] ?? []) : [],
            'target_min' => $target['min'] ?? null,
            'target_max' => $target['max'] ?? null,
            'weight' => $record->weight,
            'label_ar' => $record->label_ar,
            'label_en' => $record->label_en,
            'justification' => $record->justification,
            'legal_basis_note' => $record->legal_basis_note,
        ];
    }

    private static function describeTarget(Criterion $record): string
    {
        $target = $record->target_json ?? [];
        $field = $record->fieldEnum();
        $label = function (mixed $value) use ($field): string {
            if (! is_scalar($value)) {
                return '';
            }

            return match ($field?->valueType()) {
                'level' => __('enums.qualification_level.'.$value),
                'military' => __('enums.military_status.'.$value),
                default => (string) $value,
            };
        };

        return match ($record->operator) {
            CriterionOperator::IsExplicitlyTrue => __('app.criteria.explicit_yes'),
            CriterionOperator::Between => ($target['min'] ?? '').' – '.($target['max'] ?? ''),
            CriterionOperator::In, CriterionOperator::ContainsAny, CriterionOperator::ContainsAll => implode(', ', array_map($label, (array) ($target['values'] ?? []))),
            default => $label($target['value'] ?? ''),
        };
    }

    private static function needs(Get $get, string $shape): bool
    {
        $operator = CriterionOperator::tryFrom((string) $get('operator'));

        return match ($shape) {
            'single' => in_array($operator, [CriterionOperator::Gte, CriterionOperator::Lte, CriterionOperator::Equals], true),
            'list' => in_array($operator, [CriterionOperator::In, CriterionOperator::ContainsAny, CriterionOperator::ContainsAll], true),
            'range' => $operator === CriterionOperator::Between,
            default => false,
        };
    }

    private static function hasChoices(mixed $field): bool
    {
        return in_array(CriterionField::tryFrom((string) $field)?->valueType(), ['level', 'military'], true);
    }

    /**
     * @return array<string, string>
     */
    private static function choiceOptions(mixed $field): array
    {
        $field = CriterionField::tryFrom((string) $field);

        /** @var list<string> $values */
        $values = match ($field?->valueType()) {
            'level' => config('recruitment.qualification_levels', []),
            'military' => CriterionField::MILITARY_VALUES,
            default => [],
        };

        $group = $field?->valueType() === 'level' ? 'enums.qualification_level.' : 'enums.military_status.';
        $options = [];

        foreach ($values as $value) {
            $options[$value] = __($group.$value);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function operatorOptions(mixed $field): array
    {
        $field = CriterionField::tryFrom((string) $field);

        if ($field === null) {
            return [];
        }

        $options = [];

        foreach ($field->operators() as $operator) {
            $options[$operator->value] = $operator->label();
        }

        return $options;
    }

    private function canEditVersion(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('update', $this->version());
    }

    private function version(): CriteriaVersion
    {
        /** @var CriteriaVersion $version */
        $version = $this->getOwnerRecord();

        return $version;
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
