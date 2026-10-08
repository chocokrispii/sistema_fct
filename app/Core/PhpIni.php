<?php

declare(strict_types=1);

final class PhpIni
{
    public static function sizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '0') {
            return PHP_INT_MAX;
        }
        if (!preg_match('/\A(\d+)\s*([KMG]?)\z/i', $value, $matches)) {
            return 0;
        }

        $size = (int)$matches[1];
        $power = match (strtolower($matches[2])) {
            'g' => 3,
            'm' => 2,
            'k' => 1,
            default => 0,
        };

        return $size * (1024 ** $power);
    }
}
