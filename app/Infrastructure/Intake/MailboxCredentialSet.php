<?php

namespace App\Infrastructure\Intake;

use App\Models\Mailbox;

/**
 * Mailbox credentials from the encrypted model field or a named config set.
 * Config sets are resolved before config caching; the default uses MAILBOX_* env.
 */
final class MailboxCredentialSet
{
    /** @param array<string, string> $credentials */
    private function __construct(
        public readonly string $prefix,
        private readonly array $credentials = [],
    ) {}

    public static function forMailbox(Mailbox $mailbox): self
    {
        return new self($mailbox->credential_ref, $mailbox->credentials ?? []);
    }

    public function username(): string
    {
        return (string) $this->value('USERNAME');
    }

    public function password(): string
    {
        return (string) $this->value('PASSWORD');
    }

    public function tenantId(): ?string
    {
        return $this->value('TENANT_ID');
    }

    public function clientId(): ?string
    {
        return $this->value('CLIENT_ID');
    }

    public function clientSecret(): ?string
    {
        return $this->value('CLIENT_SECRET');
    }

    public function refreshToken(): ?string
    {
        return $this->value('REFRESH_TOKEN');
    }

    private function value(string $key): ?string
    {
        $reference = in_array($this->prefix, ['MAILBOX', 'default', ''], true) ? 'default' : $this->prefix;
        $value = $this->credentials[strtolower($key)] ?? config('recruitment.intake.credentials.'.$reference.'.'.strtolower($key));

        return is_string($value) && $value !== '' ? $value : null;
    }
}
