<?php

declare(strict_types=1);

final class BrandLogoProcessor
{
    public const MAX_BYTES = 2_097_152;
    private const MAX_PIXELS = 12_000_000;
    private const MAX_WIDTH = 512;
    private const MAX_HEIGHT = 128;
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';
    private const SVG_ELEMENTS = [
        'svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
    ];
    private const SVG_ATTRIBUTES = [
        'xmlns', 'viewBox', 'width', 'height', 'preserveAspectRatio', 'fill', 'fill-opacity',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'opacity', 'd',
        'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'points', 'transform',
    ];

    public static function process(string $bytes, string $mime): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('El logo no puede superar los 2 MB ni estar vacío.');
        }

        if ($mime === 'image/svg+xml') {
            return ['bytes' => self::sanitizeSvg($bytes), 'extension' => 'svg', 'mime' => $mime];
        }

        if (!in_array($mime, ['image/png', 'image/jpeg'], true)) {
            throw new InvalidArgumentException('El logo debe ser una imagen SVG, PNG o JPG válida.');
        }

        $dimensions = getimagesizefromstring($bytes);
        if (
            !is_array($dimensions)
            || ($dimensions['mime'] ?? null) !== $mime
            || !is_int($dimensions[0] ?? null)
            || !is_int($dimensions[1] ?? null)
            || $dimensions[0] < 1
            || $dimensions[1] < 1
            || $dimensions[0] * $dimensions[1] > self::MAX_PIXELS
        ) {
            throw new InvalidArgumentException('No se pudo procesar la imagen o sus dimensiones superan el límite seguro.');
        }

        $source = imagecreatefromstring($bytes);
        if (!$source instanceof GdImage) {
            throw new InvalidArgumentException('No se pudo decodificar la imagen del logo.');
        }

        $scale = min(
            self::MAX_WIDTH / $dimensions[0],
            self::MAX_HEIGHT / $dimensions[1],
            1.0
        );
        $width = max(1, (int)round($dimensions[0] * $scale));
        $height = max(1, (int)round($dimensions[1] * $scale));
        $resized = imagecreatetruecolor($width, $height);
        if (!$resized instanceof GdImage) {
            imagedestroy($source);
            throw new RuntimeException('No se pudo crear la imagen redimensionada del logo.');
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefill($resized, 0, 0, $transparent);
        $copied = imagecopyresampled(
            $resized,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $dimensions[0],
            $dimensions[1]
        );
        imagedestroy($source);
        if (!$copied) {
            imagedestroy($resized);
            throw new RuntimeException('No se pudo redimensionar el logo.');
        }

        ob_start();
        $encoded = imagepng($resized, null, 9);
        $processed = ob_get_clean();
        imagedestroy($resized);
        if (!$encoded || !is_string($processed)) {
            throw new RuntimeException('No se pudo codificar el logo procesado.');
        }

        return ['bytes' => $processed, 'extension' => 'png', 'mime' => 'image/png'];
    }

    private static function sanitizeSvg(string $bytes): string
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes) === 1) {
            throw new InvalidArgumentException('El SVG no puede incluir declaraciones XML externas.');
        }

        $previousErrorSetting = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument();
            $loaded = $document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            $root = $document->documentElement;
            if (!$loaded || !$root instanceof DOMElement || $root->localName !== 'svg' || $root->namespaceURI !== self::SVG_NAMESPACE) {
                throw new InvalidArgumentException('El SVG no tiene una estructura válida.');
            }

            self::validateSvgElement($root, true);
            $viewBox = self::viewBox($root);
            $root->setAttribute('width', (string)max(1, (int)round(min(self::MAX_WIDTH / $viewBox[2], self::MAX_HEIGHT / $viewBox[3]) * $viewBox[2])));
            $root->setAttribute('height', (string)max(1, (int)round(min(self::MAX_WIDTH / $viewBox[2], self::MAX_HEIGHT / $viewBox[3]) * $viewBox[3])));
            if (!$root->hasAttribute('preserveAspectRatio')) {
                $root->setAttribute('preserveAspectRatio', 'xMidYMid meet');
            }
            $sanitized = $document->saveXML($root);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        if (!is_string($sanitized) || $sanitized === '' || strlen($sanitized) > self::MAX_BYTES) {
            throw new InvalidArgumentException('No se pudo sanear el SVG del logo.');
        }

        return $sanitized;
    }

    private static function validateSvgElement(DOMElement $element, bool $root): void
    {
        if (
            !in_array($element->localName, self::SVG_ELEMENTS, true)
            || $element->namespaceURI !== self::SVG_NAMESPACE
        ) {
            throw new InvalidArgumentException('El SVG contiene elementos que no se admiten por seguridad.');
        }

        foreach ($element->attributes as $attribute) {
            if (
                !in_array($attribute->name, self::SVG_ATTRIBUTES, true)
                || ($attribute->name === 'xmlns' && $attribute->value !== self::SVG_NAMESPACE)
                || ($attribute->name !== 'xmlns' && !self::safeSvgAttribute($attribute->name, $attribute->value))
            ) {
                throw new InvalidArgumentException('El SVG contiene atributos no seguros o no compatibles.');
            }
        }

        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                self::validateSvgElement($child, false);
                continue;
            }
            if ($child instanceof DOMText && trim($child->textContent) === '') {
                continue;
            }
            throw new InvalidArgumentException('El SVG contiene contenido activo o no compatible.');
        }

        if ($root && !$element->hasAttribute('viewBox')) {
            $width = self::svgDimension($element->getAttribute('width'));
            $height = self::svgDimension($element->getAttribute('height'));
            if ($width === null || $height === null) {
                throw new InvalidArgumentException('El SVG debe definir un viewBox o dimensiones numéricas.');
            }
            $element->setAttribute('viewBox', '0 0 ' . $width . ' ' . $height);
        }
    }

    private static function safeSvgAttribute(string $name, string $value): bool
    {
        if ($value === '' || strlen($value) > 500_000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F<>]/', $value) === 1) {
            return false;
        }

        return match ($name) {
            'viewBox' => self::viewBoxValues($value) !== null,
            'width', 'height', 'x', 'y', 'x1', 'x2', 'y1', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
            'stroke-width', 'opacity', 'fill-opacity' => preg_match('/\A-?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?(?:px)?\z/i', $value) === 1,
            'fill', 'stroke' => preg_match('/\A(?:none|currentColor|transparent|#[0-9a-fA-F]{3,8}|[a-zA-Z]+|rgba?\([0-9.,%\s]+\))\z/i', $value) === 1,
            'stroke-linecap' => in_array($value, ['butt', 'round', 'square'], true),
            'stroke-linejoin' => in_array($value, ['miter', 'round', 'bevel'], true),
            'preserveAspectRatio' => preg_match('/\A(?:none|x(?:Min|Mid|Max)Y(?:Min|Mid|Max)(?: meet| slice)?)\z/', $value) === 1,
            'd' => preg_match('/\A[0-9a-zA-Z+\-.,\s]+\z/', $value) === 1,
            'points' => preg_match('/\A[0-9eE+\-.,\s]+\z/', $value) === 1,
            'transform' => preg_match('/\A(?:(?:matrix|translate|scale|rotate|skewX|skewY)\s*\([0-9eE+.,\-\s]+\)\s*)+\z/', $value) === 1,
            default => false,
        };
    }

    private static function viewBox(DOMElement $root): array
    {
        $viewBox = self::viewBoxValues($root->getAttribute('viewBox'));
        if ($viewBox === null) {
            throw new InvalidArgumentException('El SVG debe tener un viewBox con anchura y altura positivas.');
        }

        return $viewBox;
    }

    private static function viewBoxValues(string $value): ?array
    {
        if (preg_match('/\A\s*(-?(?:\d+(?:\.\d*)?|\.\d+))[\s,]+(-?(?:\d+(?:\.\d*)?|\.\d+))[\s,]+(\d+(?:\.\d*)?|\.\d+)[\s,]+(\d+(?:\.\d*)?|\.\d+)\s*\z/', $value, $matches) !== 1) {
            return null;
        }
        $values = [(float)$matches[1], (float)$matches[2], (float)$matches[3], (float)$matches[4]];

        return $values[2] > 0 && $values[3] > 0 ? $values : null;
    }

    private static function svgDimension(string $value): ?float
    {
        if (preg_match('/\A(\d+(?:\.\d*)?|\.\d+)(?:px)?\z/i', $value, $matches) !== 1) {
            return null;
        }
        $dimension = (float)$matches[1];

        return $dimension > 0 ? $dimension : null;
    }
}
