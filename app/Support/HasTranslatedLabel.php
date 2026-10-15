<?php

namespace App\Support;

/**
 * Translated labels for backed enums: lang/{locale}/enums.php → [group][value].
 *
 * @phpstan-require-implements \BackedEnum
 */
trait HasTranslatedLabel
{
    abstract public static function translationGroup(): string;

    public function label(): string
    {
        return __(static::translationGroup().'.'.$this->value);
    }

    /**
     * @return array<string, string> value => label, for selects and filters.
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[(string) $case->value] = $case->label();
        }

        return $options;
    }
}
