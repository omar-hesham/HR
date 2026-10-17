<?php

namespace App\Filament\Resources\Users;

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\RecruitmentJob;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

/**
 * Accounts (M9). Role/permission/job-scope changes go through SyncUserAccess so they are
 * logged and revoke sessions; the coordinator never sees the access section (D-14).
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 90;

    /**
     * "Restricted" cells of the §4 matrix an owner may grant to one user (D-11).
     */
    public const GRANTABLE_PERMISSIONS = [
        Permission::CriteriaApprove,
        Permission::DocumentsDownloadAssigned,
        Permission::ExportsAssigned,
        Permission::AccessLogViewAssigned,
    ];

    public static function getModelLabel(): string
    {
        return __('app.users.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('app.users.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('app.users.section_account'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')->label(__('app.users.name'))->required()->maxLength(255),
                    TextInput::make('email')->label(__('app.users.email'))->email()->required()->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->extraInputAttributes(['dir' => 'ltr']),
                    Select::make('locale')->label(__('app.users.locale'))->options([
                        'ar' => __('app.locale.ar'),
                        'en' => __('app.locale.en'),
                    ])->required()->default('ar'),
                    TextInput::make('password')
                        ->label(fn (?Model $record): string => $record === null ? __('app.users.password') : __('app.users.new_password'))
                        ->password()
                        ->revealable()
                        ->rule(Password::min(12)->mixedCase()->numbers())
                        ->required(fn ($livewire): bool => $livewire instanceof CreateRecord)
                        ->dehydrated(fn (?string $state): bool => filled($state)),
                    Toggle::make('active')->label(__('app.users.active'))->default(true),
                    DateTimePicker::make('access_expires_at')->label(__('app.users.access_expires_at'))->helperText(__('app.users.access_expires_help')),
                    Toggle::make('name_blind_review')->label(__('app.users.name_blind_review')),
                ]),
            Section::make(__('app.users.section_access'))
                ->description(__('app.users.section_access_help'))
                ->visible(fn (?User $record): bool => self::canAssign($record))
                ->columns(3)
                ->schema([
                    CheckboxList::make('roles_input')->label(__('app.users.roles'))->options(Role::options()),
                    CheckboxList::make('permissions_input')->label(__('app.users.extra_permissions'))
                        ->options(collect(self::GRANTABLE_PERMISSIONS)->mapWithKeys(fn (Permission $p): array => [$p->value => __('enums.permission.'.$p->value)])->all() + ['ai.pipeline.manage' => __('ai.manage_permission')]),
                    CheckboxList::make('jobs_input')->label(__('app.users.jobs'))
                        ->options(fn (): array => RecruitmentJob::query()->orderBy('title_ar')->get()->mapWithKeys(fn (RecruitmentJob $job): array => [$job->id => $job->title()])->all()),
                ]),
        ]);
    }

    public static function canAssign(?User $target): bool
    {
        $actor = auth()->user();

        if (! $actor instanceof User) {
            return false;
        }

        return $target === null
            ? $actor->hasPermission(Permission::UsersManage)
            : $actor->can('assignRoles', $target);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('app.users.name'))->searchable(),
                TextColumn::make('email')->label(__('app.users.email'))->searchable()->extraAttributes(['dir' => 'ltr']),
                TextColumn::make('roles.name')
                    ->label(__('app.users.roles'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Role::tryFrom($state)?->label() ?? $state),
                IconColumn::make('active')->label(__('app.users.active'))->boolean(),
                IconColumn::make('app_authentication_secret')
                    ->label(__('app.users.mfa'))
                    ->state(fn (User $record): bool => filled($record->getAppAuthenticationSecret()))
                    ->boolean(),
                TextColumn::make('access_expires_at')->label(__('app.users.access_expires_at'))->dateTime()->placeholder('—'),
                TextColumn::make('last_login_at')->label(__('app.users.last_login_at'))->dateTime()->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
