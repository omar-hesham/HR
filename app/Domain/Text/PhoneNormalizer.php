<?php

namespace App\Domain\Text;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * TXT-P0-04: Egyptian numbers → E.164 "+20…"; other international numbers keep
 * their own country code; anything incomplete or ambiguous returns null.
 * The original string is always kept by the caller. Digits are never guessed.
 */
final class PhoneNormalizer
{
    public function __construct(private readonly PhoneNumberUtil $util) {}

    public static function make(): self
    {
        return new self(PhoneNumberUtil::getInstance());
    }

    public function normalize(string $original): PhoneNumberResult
    {
        $digits = SearchNormalizer::westernDigits(trim($original));
        // Separators people write inside numbers: spaces, dashes, dots, slashes, parentheses, bidi marks.
        $compact = (string) preg_replace('/[\s\-\.\/\(\)\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $digits);

        if ($compact === '' || preg_match('/^\+?\d+$/', $compact) !== 1) {
            return new PhoneNumberResult($original, null, PhoneNumberResult::STATUS_INVALID);
        }

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        $isInternational = str_starts_with($compact, '+');

        // A national number must carry Egypt's trunk prefix 0; without it we cannot know the country.
        if (! $isInternational && ! str_starts_with($compact, '0')) {
            return new PhoneNumberResult($original, null, PhoneNumberResult::STATUS_AMBIGUOUS);
        }

        try {
            $number = $this->util->parse($compact, $isInternational ? null : 'EG');
        } catch (NumberParseException) {
            return new PhoneNumberResult($original, null, PhoneNumberResult::STATUS_INVALID);
        }

        if (! $this->util->isValidNumber($number)) {
            return new PhoneNumberResult($original, null, PhoneNumberResult::STATUS_INVALID);
        }

        $e164 = $this->util->format($number, PhoneNumberFormat::E164);
        $status = $number->getCountryCode() === 20 ? PhoneNumberResult::STATUS_EGYPT : PhoneNumberResult::STATUS_INTERNATIONAL;

        return new PhoneNumberResult($original, $e164, $status);
    }
}
