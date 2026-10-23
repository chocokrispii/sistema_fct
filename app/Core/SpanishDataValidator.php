<?php

declare(strict_types=1);

final class SpanishDataValidator
{
    public const DOCUMENT_TYPE_NIF_NIE_DNI = 'NIF_NIE_DNI';
    public const DOCUMENT_TYPE_PASSPORT = 'PASAPORTE';

    public static function isDniNie(string $value): bool
    {
        $document = self::normalizeDniNie($value);
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

    public static function isDniNieFormat(string $value): bool
    {
        return preg_match('/\A(?:\d{8}[A-Z]|[XYZ]\d{7}[A-Z])\z/', self::normalizeDniNie($value)) === 1;
    }

    public static function isNifPersonaJuridica(string $value): bool
    {
        $identifier = self::normalizeDniNie($value);
        if (preg_match('/\A([ABCDEFGHJNPQRSUVW])(\d{7})([0-9A-J])\z/', $identifier, $matches) !== 1) {
            return false;
        }

        $sum = 0;
        for ($index = 0; $index < 7; $index++) {
            $digit = (int)$matches[2][$index];
            if ($index % 2 === 0) {
                $doubled = $digit * 2;
                $sum += intdiv($doubled, 10) + ($doubled % 10);
            } else {
                $sum += $digit;
            }
        }

        $control = (10 - ($sum % 10)) % 10;
        $controlDigit = (string)$control;
        $controlLetter = 'JABCDEFGHI'[$control];
        $prefix = $matches[1];

        if (str_contains('ABEH', $prefix)) {
            return $matches[3] === $controlDigit;
        }
        if (str_contains('PQRS', $prefix)) {
            return $matches[3] === $controlLetter;
        }

        return $matches[3] === $controlDigit || $matches[3] === $controlLetter;
    }

    public static function isNifPersonaJuridicaFormat(string $value): bool
    {
        return preg_match(
            '/\A[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]\z/',
            self::normalizeFiscalIdentifier($value)
        ) === 1;
    }

    public static function isPassport(string $value): bool
    {
        return preg_match('/\A[A-Z0-9]{6,15}\z/', strtoupper(trim($value))) === 1;
    }

    public static function isStudentDocumentType(string $value): bool
    {
        return in_array($value, [self::DOCUMENT_TYPE_NIF_NIE_DNI, self::DOCUMENT_TYPE_PASSPORT], true);
    }

    public static function normalizeFiscalIdentifier(string $value): string
    {
        return strtoupper((string)preg_replace('/[\s.-]+/u', '', trim($value)));
    }

    public static function isSpanishPhone(string $value): bool
    {
        $phone = trim($value);
        if (preg_match('/\A(?:\+34|0034)\s*/', $phone) === 1) {
            $phone = preg_replace('/\A(?:\+34|0034)\s*/', '', $phone) ?? '';
        }
        $digits = preg_replace('/[\s().-]+/', '', $phone);
        return is_string($digits) && preg_match('/\A[6789]\d{8}\z/', $digits) === 1;
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
        return self::normalizeFiscalIdentifier($value);
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
