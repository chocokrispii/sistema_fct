<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/BrandingPalette.php';
require_once __DIR__ . '/../app/Services/BrandLogoProcessor.php';
require_once __DIR__ . '/../app/Services/BrandLogoShape.php';

function assertBranding(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$palette = BrandingPalette::normalize([
    'primary' => '#FFFFFF',
    'secondary' => '#00A8E8',
    'accent' => '#000000',
]);
$stylesheet = BrandingPalette::stylesheet($palette);
preg_match('/\Ahtml:root\s*\{([^}]*)\}/', $stylesheet, $lightMatch);
preg_match('/html\[data-theme="dark"\]:root\s*\{([^}]*)\}/', $stylesheet, $darkMatch);
assertBranding(isset($lightMatch[1], $darkMatch[1]), 'La hoja debe generar tokens para ambos temas.');
preg_match('/--color-primary:\s*(#[0-9A-F]{6})/i', $darkMatch[1], $darkPrimaryMatch);
preg_match('/--color-secondary:\s*(#[0-9A-F]{6})/i', $darkMatch[1], $darkSecondaryMatch);
assertBranding(
    isset($darkPrimaryMatch[1], $darkSecondaryMatch[1])
        && $darkPrimaryMatch[1] === $darkSecondaryMatch[1],
    'En modo oscuro, los elementos principales deben usar el color secundario de marca.'
);

foreach ([
    ['tokens' => $lightMatch[1], 'surfaces' => ['#F4F5F7', '#FFFFFF']],
    ['tokens' => $darkMatch[1], 'surfaces' => ['#202A35', '#1D2630']],
] as $theme) {
    foreach (['primary', 'secondary', 'accent'] as $name) {
        preg_match('/--color-' . $name . ':\s*(#[0-9A-F]{6})/i', $theme['tokens'], $colorMatch);
        assertBranding(isset($colorMatch[1]), 'Falta el token de color ' . $name . '.');
        foreach ($theme['surfaces'] as $surface) {
            assertBranding(
                BrandingPalette::contrastRatio($colorMatch[1], $surface) >= 4.5,
                'El token ' . $name . ' no cumple WCAG 4.5:1 en su superficie.'
            );
        }
    }

    preg_match('/--color-primary:\s*(#[0-9A-F]{6})/i', $theme['tokens'], $primaryMatch);
    preg_match('/--color-primary-contrast:\s*(#[0-9A-F]{6})/i', $theme['tokens'], $buttonTextMatch);
    assertBranding(
        BrandingPalette::contrastRatio($buttonTextMatch[1], $primaryMatch[1]) >= 4.5,
        'El texto del botón principal debe cumplir WCAG 4.5:1.'
    );
}

$invalidPaletteRejected = false;
try {
    BrandingPalette::normalize(['primary' => 'url(javascript:alert(1))', 'secondary' => '#FFFFFF', 'accent' => '#000000']);
} catch (InvalidArgumentException) {
    $invalidPaletteRejected = true;
}
assertBranding($invalidPaletteRejected, 'Se deben rechazar colores que no sean hexadecimales.');

foreach (BrandLogoShape::OPTIONS as $shape) {
    assertBranding(BrandLogoShape::normalize($shape) === $shape, 'Debe aceptarse la forma válida ' . $shape . '.');
}
$invalidShapeRejected = false;
try {
    BrandLogoShape::normalize('triangle');
} catch (InvalidArgumentException) {
    $invalidShapeRejected = true;
}
assertBranding($invalidShapeRejected, 'Se deben rechazar formas de logo no permitidas.');

foreach ([[4000, 100], [600, 600], [100, 4000]] as [$sourceWidth, $sourceHeight]) {
    $source = imagecreatetruecolor($sourceWidth, $sourceHeight);
    imagealphablending($source, false);
    imagesavealpha($source, true);
    $transparent = imagecolorallocatealpha($source, 255, 255, 255, 127);
    imagefill($source, 0, 0, $transparent);
    $color = imagecolorallocatealpha($source, 30, 80, 140, 0);
    imagefilledrectangle($source, 0, 0, $sourceWidth - 1, $sourceHeight - 1, $color);
    ob_start();
    imagepng($source);
    $bytes = ob_get_clean();
    imagedestroy($source);

    $processed = BrandLogoProcessor::process($bytes, 'image/png');
    $dimensions = getimagesizefromstring($processed['bytes']);
    assertBranding($processed['mime'] === 'image/png', 'Las imágenes raster deben normalizarse a PNG.');
    assertBranding($dimensions[0] <= 512 && $dimensions[1] <= 128, 'El logo debe caber dentro de 512x128.');
    assertBranding(
        abs((($dimensions[0] / $dimensions[1]) / ($sourceWidth / $sourceHeight)) - 1) < 0.07,
        'El redimensionado no debe deformar el aspect-ratio.'
    );
}

$jpegSource = imagecreatetruecolor(1200, 100);
ob_start();
imagejpeg($jpegSource, null, 85);
$jpegBytes = ob_get_clean();
imagedestroy($jpegSource);
$processedJpeg = BrandLogoProcessor::process($jpegBytes, 'image/jpeg');
assertBranding($processedJpeg['mime'] === 'image/png', 'Los logos JPG también deben normalizarse a PNG.');

$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#123456" d="M0 0h24v24H0z"/></svg>';
$processedSvg = BrandLogoProcessor::process($svg, 'image/svg+xml');
assertBranding($processedSvg['extension'] === 'svg', 'El formato SVG debe conservarse tras sanearse.');
assertBranding(
    str_contains($processedSvg['bytes'], 'preserveAspectRatio="xMidYMid meet"')
        && !str_contains($processedSvg['bytes'], '<script'),
    'El SVG debe ajustarse sin deformarse ni conservar contenido activo.'
);
preg_match('/<svg[^>]*width="(\d+)"[^>]*height="(\d+)"/', $processedSvg['bytes'], $svgDimensions);
assertBranding(
    isset($svgDimensions[1], $svgDimensions[2])
        && (int)$svgDimensions[1] <= 512
        && (int)$svgDimensions[2] <= 128,
    'Los SVG también deben limitarse al marco máximo del logo.'
);
$exampleSvg = file_get_contents(__DIR__ . '/../public/assets/images/logo-sistema-fct-ejemplo.svg');
assertBranding(is_string($exampleSvg), 'Debe estar disponible el SVG de ejemplo de Sistema FCT.');
$processedExampleSvg = BrandLogoProcessor::process($exampleSvg, 'image/svg+xml');
assertBranding(
    str_contains($processedExampleSvg['bytes'], '<svg')
        && str_contains($processedExampleSvg['bytes'], 'preserveAspectRatio="xMidYMid meet"'),
    'El SVG de ejemplo debe superar el saneamiento y conservar su proporción.'
);

$unsafeSvgRejected = false;
try {
    BrandLogoProcessor::process(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><script>alert(1)</script></svg>',
        'image/svg+xml'
    );
} catch (InvalidArgumentException) {
    $unsafeSvgRejected = true;
}
assertBranding($unsafeSvgRejected, 'El SVG no debe admitir elementos activos.');

echo "OK: tokens accesibles WCAG y logos SVG/PNG con dimensiones extremas.\n";
