<?php

declare(strict_types=1);

final class EuropeanDate
{
    public static function format(?string $value, string $fallback = ''): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        return $date instanceof DateTimeImmutable ? $date->format('d/m/Y') : $fallback;
    }

    public static function dateTime(?string $value, string $fallback = ''): string
    {
        if ($value === null || trim($value) === '') {
            return $fallback;
        }
        try {
            return (new DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (Throwable) {
            return $fallback;
        }
    }

    public static function period(?string $start, ?string $end, string $separator = ' – '): string
    {
        return self::format($start) . $separator . self::format($end);
    }

    public static function periodBlock(?string $start, ?string $end): string
    {
        $startIso = self::isoDate($start);
        $endIso = self::isoDate($end);
        if ($startIso === null || $endIso === null) {
            return '<span class="text-muted">Periodo no definido</span>';
        }
        return '<span class="period-range" aria-label="Periodo del ' . self::format($startIso) . ' al ' . self::format($endIso) . '">' .
            '<span class="period-range__row"><span class="period-range__label">Inicio</span><time datetime="' . $startIso . '">' . self::format($startIso) . '</time></span>' .
            '<span class="period-range__row"><span class="period-range__label">Fin</span><time datetime="' . $endIso . '">' . self::format($endIso) . '</time></span>' .
            '</span>';
    }

    private static function isoDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $candidate = substr($value, 0, 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $candidate);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $candidate ? $candidate : null;
    }
}
