<?php

namespace App\Helper;

class PhoneNormalizer
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $cleaned = preg_replace('/[\s\-\(\)\.]/', '', $raw);

        if ($cleaned === '' || $cleaned === null) {
            return null;
        }

        $defaultCc = (string) config('ultramsg.default_country_code', '964');

        if (str_starts_with($cleaned, '+')) {
            return self::validateE164($cleaned);
        }

        if (str_starts_with($cleaned, '00')) {
            return self::validateE164('+' . substr($cleaned, 2));
        }

        if (str_starts_with($cleaned, '0')) {
            return self::validateE164('+' . $defaultCc . substr($cleaned, 1));
        }

        if (preg_match('/^7\d{9}$/', $cleaned)) {
            return self::validateE164('+' . $defaultCc . $cleaned);
        }

        return null;
    }

    private static function validateE164(string $number): ?string
    {
        if (preg_match('/^\+\d{8,15}$/', $number)) {
            return $number;
        }

        return null;
    }

    public static function forUltraMsg(string $normalized): string
    {
        return ltrim($normalized, '+');
    }
}
