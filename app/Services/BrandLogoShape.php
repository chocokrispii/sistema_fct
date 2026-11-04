<?php

declare(strict_types=1);

final class BrandLogoShape
{
    public const DEFAULT = 'original';
    public const OPTIONS = ['original', 'rounded', 'square', 'circle'];

    public static function normalize(mixed $shape): string
    {
        if (!is_string($shape) || !in_array($shape, self::OPTIONS, true)) {
            throw new InvalidArgumentException('Selecciona una forma válida para el logo.');
        }

        return $shape;
    }
}
