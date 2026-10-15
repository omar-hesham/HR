<?php

namespace App\Domain\Criteria;

/**
 * Errors as translation keys + parameters, keyed by input attribute
 * (or "version" for version-level problems).
 */
final class CriteriaValidationResult
{
    /** @var array<string, list<array{key: string, params: array<string, string|int>}>> */
    private array $errors = [];

    /**
     * @param  array<string, string|int>  $params
     */
    public function add(string $attribute, string $key, array $params = []): void
    {
        $this->errors[$attribute][] = ['key' => $key, 'params' => $params];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function has(string $attribute, ?string $key = null): bool
    {
        if (! isset($this->errors[$attribute])) {
            return false;
        }

        if ($key === null) {
            return true;
        }

        foreach ($this->errors[$attribute] as $error) {
            if ($error['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>> translated messages
     */
    public function messages(): array
    {
        $messages = [];

        foreach ($this->errors as $attribute => $errors) {
            foreach ($errors as $error) {
                $messages[$attribute][] = __($error['key'], $error['params']);
            }
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->errors as $errors) {
            foreach ($errors as $error) {
                $keys[] = $error['key'];
            }
        }

        return $keys;
    }
}
