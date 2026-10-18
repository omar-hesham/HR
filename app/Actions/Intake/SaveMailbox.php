<?php

namespace App\Actions\Intake;

use App\Models\Mailbox;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

final class SaveMailbox
{
    public const CREDENTIAL_FIELDS = ['username', 'password', 'tenant_id', 'client_id', 'client_secret', 'refresh_token'];

    /** @param array<string,mixed> $input */
    public function handle(User $actor, array $input, ?Mailbox $mailbox = null): Mailbox
    {
        Gate::forUser($actor)->authorize($mailbox === null ? 'create' : 'update', $mailbox ?? Mailbox::class);
        $validated = Validator::make($input, [
            'display_name' => ['required', 'string', 'max:255'], 'address' => ['required', 'email', 'max:255'],
            'provider' => ['required', 'in:imap,m365,google,fake'], 'host' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'], 'encryption' => ['required_unless:provider,fake', 'nullable', 'in:ssl,tls'],
            'folder' => ['required', 'string', 'max:255', 'not_regex:/[\r\n\x00]/'],
            'start_now' => ['sometimes', 'boolean'], 'start_at' => ['nullable', 'date'], 'active' => ['required', 'boolean'],
            'silence_alert_hours' => ['required', 'integer', 'between:1,65535'], 'credentials_input' => ['sometimes', 'array'],
            ...collect(self::CREDENTIAL_FIELDS)->mapWithKeys(fn (string $field): array => ['credentials_input.'.$field => ['nullable', 'string', 'max:4096']])->all(),
        ])->validate();
        $credentials = $mailbox?->provider === $validated['provider'] ? ($mailbox->credentials ?? []) : [];
        foreach (self::CREDENTIAL_FIELDS as $field) {
            $submitted = $validated['credentials_input'][$field] ?? null;
            if (is_string($submitted) && trim($submitted) !== '') {
                $credentials[$field] = $submitted;
            }
        }
        $required = match ($validated['provider']) {
            'imap' => ['password'], 'm365' => ['tenant_id', 'client_id', 'client_secret'],
            'google' => ['client_id', 'client_secret', 'refresh_token'], default => [],
        };
        Validator::make(['credentials_input' => $credentials], collect($required)->mapWithKeys(
            fn (string $field): array => ['credentials_input.'.$field => ['required', 'string']]
        )->all())->validate();

        return DB::transaction(function () use ($actor, $validated, $credentials, $mailbox): Mailbox {
            $record = $mailbox ?? new Mailbox;
            $record->fill(Arr::only($validated, ['display_name', 'address', 'provider', 'host', 'port', 'encryption', 'folder', 'active', 'silence_alert_hours']));
            if (($validated['start_now'] ?? ! $record->exists) || ! $record->exists) {
                $record->start_at = ($validated['start_now'] ?? true) ? now() : ($validated['start_at'] ?? now());
            } elseif (! empty($validated['start_at'])) {
                $record->start_at = $validated['start_at'];
            }
            if ($record->isDirty(['start_at', 'provider', 'host', 'folder', 'address'])) {
                $record->cursor = null;
                $record->uidvalidity = null;
            }
            $record->auth_type = in_array($validated['provider'], ['m365', 'google'], true) ? 'oauth2' : 'password';
            $record->credential_ref = $record->credential_ref ?? 'ui';
            $record->credentials = $validated['provider'] === 'fake' ? null : $credentials;
            $record->save();
            activity('configuration')->causedBy($actor)->performedOn($record)->event('mailbox.saved')->log('mailbox.saved');

            return $record;
        });
    }
}
