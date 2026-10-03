<?php

namespace App\Support;

// ══════════════════════════════════════════════════════════════════
//  El Tara — EgyptPhone
//  Location: app/Support/EgyptPhone.php
//
//  One way to write an Egyptian mobile number, so the same number is
//  always stored — and found, and matched as a duplicate — the same
//  way, however it was typed:
//
//      '+20 100 123 4567'  → '01001234567'
//      '٠١٠٠١٢٣٤٥٦٧'       → '01001234567'
//      '0020-100-1234567'  → '01001234567'
//      '1001234567'        → '01001234567'
//
//  Valid: 11 digits starting 010, 011, 012 or 015 (PATTERN). Anything
//  else is returned tidied but unchanged, so the form can say it is
//  not a valid mobile instead of silently changing it.
//  Used for office users, client users and driver sign-in (mobile + PIN).
// ══════════════════════════════════════════════════════════════════

final class EgyptPhone
{
    public const PATTERN = '/^01[0125]\d{8}$/';

    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $digits = self::digits((string) $value);
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0020')) {
            $digits = '0'.substr($digits, 4);
        } elseif (str_starts_with($digits, '20') && strlen($digits) === 12) {
            $digits = '0'.substr($digits, 2);
        } elseif (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /** Only the digits, with Arabic-Indic and Persian digits turned into 0-9. */
    public static function digits(string $value): string
    {
        $value = strtr($value, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        return preg_replace('/\D+/', '', $value) ?? '';
    }

    public static function isValid(?string $normalized): bool
    {
        return $normalized !== null && preg_match(self::PATTERN, $normalized) === 1;
    }
}
