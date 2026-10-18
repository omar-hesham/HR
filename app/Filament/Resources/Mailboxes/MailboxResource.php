<?php

namespace App\Filament\Resources\Mailboxes;

use App\Actions\Intake\SaveMailbox;
use App\Filament\Resources\Mailboxes\Pages\CreateMailbox;
use App\Filament\Resources\Mailboxes\Pages\EditMailbox;
use App\Filament\Resources\Mailboxes\Pages\ListMailboxes;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\Intake\MailboxOperations;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MailboxResource extends Resource
{
    protected static ?string $model = Mailbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 65;

    public static function getModelLabel(): string
    {
        return __('mailboxes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mailboxes.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.nav.administration');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('mailboxes.connection'))->description(__('mailboxes.read_only'))->columns(2)->schema([
                TextInput::make('display_name')->label(__('mailboxes.display_name'))->required()->maxLength(255),
                TextInput::make('address')->label(__('mailboxes.address'))->email()->required()->maxLength(255),
                Select::make('provider')->label(__('mailboxes.provider'))->options([
                    'imap' => __('mailboxes.providers.imap'), 'm365' => __('mailboxes.providers.m365'),
                    'google' => __('mailboxes.providers.google'), 'fake' => __('mailboxes.providers.fake'),
                ])->default('imap')->required()->live(),
                TextInput::make('host')->label(__('mailboxes.host'))->helperText(__('mailboxes.host_help'))->required()->maxLength(255),
                TextInput::make('port')->label(__('mailboxes.port'))->numeric()->minValue(1)->maxValue(65535)->default(993),
                Select::make('encryption')->label(__('mailboxes.encryption'))->options(['ssl' => __('mailboxes.ssl'), 'tls' => __('mailboxes.tls')])->default('ssl')->required(),
                TextInput::make('folder')->label(__('mailboxes.folder'))->default('INBOX')->required()->maxLength(255),
            ]),
            Section::make(__('mailboxes.credentials'))->description(__('mailboxes.write_only'))->columns(2)->schema(
                array_map(fn (string $field): TextInput => TextInput::make('credentials_input.'.$field)
                    ->label(__('mailboxes.'.$field))->password()->autocomplete('new-password')->maxLength(4096)
                    ->visible(fn (Get $get): bool => match ($field) {
                        'password' => $get('provider') === 'imap', 'tenant_id' => $get('provider') === 'm365',
                        'client_id', 'client_secret' => in_array($get('provider'), ['m365', 'google'], true),
                        'refresh_token' => $get('provider') === 'google', default => $get('provider') !== 'fake',
                    }), SaveMailbox::CREDENTIAL_FIELDS)
            ),
            Section::make(__('mailboxes.polling'))->columns(2)->schema([
                Toggle::make('start_now')->label(__('mailboxes.start_now'))->default(true)->live(),
                DateTimePicker::make('start_at')->label(__('mailboxes.start_at'))->seconds(false)
                    ->visible(fn (Get $get): bool => ! $get('start_now'))->required(fn (Get $get): bool => ! $get('start_now')),
                Toggle::make('active')->label(__('mailboxes.active'))->default(true),
                TextInput::make('silence_alert_hours')->label(__('mailboxes.silence'))->numeric()->minValue(1)->maxValue(65535)->default(48)->required(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('display_name')->label(__('mailboxes.display_name'))->searchable(),
            TextColumn::make('address')->label(__('mailboxes.address'))->searchable(),
            TextColumn::make('provider')->label(__('mailboxes.provider'))->formatStateUsing(fn (string $state): string => __('mailboxes.providers.'.$state)),
            TextColumn::make('folder')->label(__('mailboxes.folder')),
            IconColumn::make('active')->label(__('mailboxes.active'))->boolean(),
            TextColumn::make('last_success_at')->label(__('mailboxes.last_success'))->dateTime(),
        ])->recordActions([
            EditAction::make(),
            Action::make('testConnection')->label(__('mailboxes.test'))->action(function (Mailbox $record): void {
                $code = app(MailboxOperations::class)->testConnection(self::actor(), $record);
                $notification = Notification::make()->title(__('mailboxes.'.$code));
                ($code === 'connection_ok' ? $notification->success() : $notification->danger())->send();
            }),
            Action::make('pollNow')->label(__('mailboxes.poll'))->disabled(fn (Mailbox $record): bool => ! $record->active)
                ->action(function (Mailbox $record): void {
                    app(MailboxOperations::class)->poll(self::actor(), $record);
                    Notification::make()->success()->title(__('mailboxes.poll_queued'))->send();
                }),
        ]);
    }

    public static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    public static function getPages(): array
    {
        return ['index' => ListMailboxes::route('/'), 'create' => CreateMailbox::route('/create'), 'edit' => EditMailbox::route('/{record}/edit')];
    }
}
