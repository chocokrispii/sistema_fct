<?php

declare(strict_types=1);

final class SpanishDataValidator
{
    public static function isDniNie(string $value): bool
    {
        $document = strtoupper((string)preg_replace('/[\s-]+/u', '', trim($value)));
        if (preg_match('/\A\d{8}[A-Z]\z/', $document) === 1) {
            $number = (int)substr($document, 0, 8);
            $letter = $document[8];
        } elseif (preg_match('/\A[XYZ]\d{7}[A-Z]\z/', $document) === 1) {
            $prefix = ['X' => '0', 'Y' => '1', 'Z' => '2'][$document[0]];
            $number = (int)($prefix . substr($document, 1, 7));
            $letter = $document[8];
        } else {
            return false;
        }

        return $letter === 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
    }

    public static function isSpanishPhone(string $value): bool
    {
        return preg_match('/\A[6789]\d{8}\z/', self::normalizePhone($value)) === 1;
    }

    public static function isSpanishPostcode(string $value): bool
    {
        return preg_match('/\A(?:0[1-9]|[1-4]\d|5[0-2])\d{3}\z/', trim($value)) === 1;
    }

    public static function isEmail(string $value, int $maxLength = 100): bool
    {
        return strlen($value) <= $maxLength && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isPersonName(string $value, int $maxLength): bool
    {
        return mb_strlen($value) <= $maxLength
            && preg_match('/\A[\p{L}\p{M}][\p{L}\p{M} \t.\x{27}\x{2019}-]*\z/u', $value) === 1;
    }

    public static function normalizeDniNie(string $value): string
    {
        return strtoupper((string)preg_replace('/[\s-]+/u', '', trim($value)));
    }

    public static function normalizePhone(string $value): string
    {
        $phone = trim($value);
        if (preg_match('/\A(?:\+34|0034)\s*/', $phone) === 1) {
            $phone = preg_replace('/\A(?:\+34|0034)\s*/', '', $phone) ?? '';
        }
        return (string)preg_replace('/[\s().-]+/', '', $phone);
    }
}
