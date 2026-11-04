<?php

declare(strict_types=1);

final class BrandingPalette
{
    public const DEFAULTS = [
        'primary' => '#004085',
        'secondary' => '#00A8E8',
        'accent' => '#A33A0A',
    ];

    public static function normalize(array $values): array
    {
        $palette = [];
        foreach (array_keys(self::DEFAULTS) as $name) {
            $value = $values[$name] ?? null;
            if (!is_string($value) || preg_match('/\A#[0-9a-fA-F]{6}\z/', $value) !== 1) {
                throw new InvalidArgumentException('Selecciona un color hexadecimal válido para cada tono.');
            }
            $palette[$name] = strtoupper($value);
        }

        return $palette;
    }

    public static function stylesheet(array $values): string
    {
        $palette = self::normalize($values);
        $light = self::tokensForTheme($palette, false);
        $dark = self::tokensForTheme($palette, true);

        return "html:root {\n" . self::declarations($light) . "\n}\n"
            . "html[data-theme=\"dark\"]:root {\n" . self::declarations($dark) . "\n}\n";
    }

    public static function contrastRatio(string $foreground, string $background): float
    {
        $foregroundLuminance = self::luminance($foreground);
        $backgroundLuminance = self::luminance($background);

        return (max($foregroundLuminance, $backgroundLuminance) + 0.05)
            / (min($foregroundLuminance, $backgroundLuminance) + 0.05);
    }

    private static function tokensForTheme(array $palette, bool $dark): array
    {
        $surfaces = $dark ? ['#202A35', '#1D2630'] : ['#F4F5F7', '#FFFFFF'];
        $tokens = [];
        foreach ($palette as $name => $color) {
            if ($dark && $name === 'primary') {
                $color = $palette['secondary'];
            }
            $readable = self::readableColor($color, $surfaces);
            $tokens['--color-' . $name] = $readable;
            if ($name === 'primary') {
                $tokens['--color-primary-hover'] = $readable;
                $tokens['--color-primary-contrast'] = self::contrastText($readable);
            }
        }

        return $tokens;
    }

    private static function readableColor(string $color, array $surfaces): string
    {
        if (self::meetsContrast($color, $surfaces)) {
            return $color;
        }

        $backgroundLuminance = array_sum(array_map(self::luminance(...), $surfaces)) / count($surfaces);
        $target = $backgroundLuminance > 0.5 ? '#000000' : '#FFFFFF';
        $low = 0.0;
        $high = 1.0;
        for ($iteration = 0; $iteration < 24; $iteration++) {
            $amount = ($low + $high) / 2;
            $candidate = self::blend($color, $target, $amount);
            if (self::meetsContrast($candidate, $surfaces)) {
                $high = $amount;
            } else {
                $low = $amount;
            }
        }

        return self::blend($color, $target, $high);
    }

    private static function meetsContrast(string $color, array $surfaces): bool
    {
        foreach ($surfaces as $surface) {
            if (self::contrastRatio($color, $surface) < 4.5) {
                return false;
            }
        }

        return true;
    }

    private static function contrastText(string $background): string
    {
        return self::contrastRatio('#000000', $background) >= self::contrastRatio('#FFFFFF', $background)
            ? '#000000'
            : '#FFFFFF';
    }

    private static function blend(string $color, string $target, float $amount): string
    {
        $sourceChannels = self::channels($color);
        $targetChannels = self::channels($target);
        $channels = [];
        foreach ($sourceChannels as $index => $channel) {
            $channels[] = (int)round($channel + (($targetChannels[$index] - $channel) * $amount));
        }

        return sprintf('#%02X%02X%02X', ...$channels);
    }

    private static function luminance(string $color): float
    {
        $channels = self::channels($color);
        $linear = array_map(
            static function (int $channel): float {
                $value = $channel / 255;
                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            $channels
        );

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    private static function channels(string $color): array
    {
        $hex = ltrim($color, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function declarations(array $tokens): string
    {
        $declarations = [];
        foreach ($tokens as $name => $value) {
            $declarations[] = '    ' . $name . ': ' . $value . ';';
        }

        return implode("\n", $declarations);
    }
}
