<?php

declare(strict_types=1);

final class FrontendAssets
{
    private const MANIFEST_PATH = __DIR__ . '/../../public/build/.vite/manifest.json';

    public static function render(string $html): string
    {
        if (!str_contains($html, 'data-vue-island')) {
            return '';
        }

        $developmentOrigin = self::developmentOrigin();
        if ($developmentOrigin !== null) {
            return '<script type="module" src="' . $developmentOrigin . '/@vite/client"></script>' . PHP_EOL
                . '<script type="module" src="' . $developmentOrigin . '/frontend/src/main.ts"></script>' . PHP_EOL;
        }

        if (!is_file(self::MANIFEST_PATH)) {
            error_log('No se encontró el bundle de Vue; se servirá el formulario PHP con validación HTML y del servidor.');
            return '';
        }

        $contents = file_get_contents(self::MANIFEST_PATH);
        if (!is_string($contents)) {
            throw new RuntimeException('No se pudo leer el manifiesto de assets de Vite.');
        }

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        $entry = $manifest['frontend/src/main.ts'] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            throw new RuntimeException('El manifiesto de Vite no contiene la entrada frontend/src/main.ts.');
        }

        $tags = '';
        foreach (($entry['css'] ?? []) as $stylesheet) {
            $tags .= '<link rel="stylesheet" href="' . self::assetUrl($stylesheet, 'css') . '">' . PHP_EOL;
        }

        $tags .= '<script type="module" src="' . self::assetUrl($entry['file'], 'js') . '"></script>' . PHP_EOL;
        return $tags;
    }

    public static function isEnabled(): bool
    {
        return self::developmentOrigin() !== null || is_file(self::MANIFEST_PATH);
    }

    public static function developmentOrigin(): ?string
    {
        $origin = getenv('FCT_VITE_DEV_SERVER');
        if (!is_string($origin) || $origin === '') {
            return null;
        }

        if (
            preg_match('/\Ahttp:\/\/(?:localhost|127\.0\.0\.1):([0-9]{1,5})\z/', $origin, $matches) !== 1
            || (int)$matches[1] < 1
            || (int)$matches[1] > 65535
        ) {
            throw new RuntimeException('FCT_VITE_DEV_SERVER solo puede apuntar a localhost por HTTP y con puerto explícito.');
        }

        return $origin;
    }

    private static function assetUrl(mixed $path, string $extension): string
    {
        if (
            !is_string($path)
            || !str_starts_with($path, 'assets/')
            || str_contains($path, '..')
            || !str_ends_with($path, '.' . $extension)
        ) {
            throw new RuntimeException('El manifiesto de Vite contiene una ruta de asset no válida.');
        }

        return './build/' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
    }
}
