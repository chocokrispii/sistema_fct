<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/BrandingSettings.php';
require_once __DIR__ . '/../Services/BrandingPalette.php';
require_once __DIR__ . '/../Services/BrandLogoProcessor.php';
require_once __DIR__ . '/../Services/BrandLogoShape.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../../config/storage.php';

final class BrandingController
{
    private BrandingSettings $settings;

    public function __construct(?PDO $db = null)
    {
        $this->settings = new BrandingSettings($db);
    }

    public function manage(): void
    {
        $settings = $this->settings->get();
        $palette = [
            'primary' => $settings['primary_color'],
            'secondary' => $settings['secondary_color'],
            'accent' => $settings['accent_color'],
        ];
        $logoShape = BrandLogoShape::normalize($settings['logo_shape'] ?? BrandLogoShape::DEFAULT);
        $error = '';

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            try {
                $palette = BrandingPalette::normalize($_POST);
                $logoShape = BrandLogoShape::normalize($_POST['logo_shape'] ?? null);
                $removeLogo = ($_POST['remove_logo'] ?? '') === '1';
                $upload = $_FILES['logo'] ?? null;
                if (array_key_exists('logo', $_FILES) && !is_array($upload)) {
                    throw new InvalidArgumentException('El archivo del logo no tiene un formato de carga válido.');
                }
                $hasUpload = is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
                if ($removeLogo && $hasUpload) {
                    throw new InvalidArgumentException('Elige subir un logo nuevo o retirar el actual, no ambas opciones.');
                }

                if ($removeLogo) {
                    $oldPath = $this->settings->removeLogo($palette, $logoShape);
                    self::deleteStoredLogo($oldPath);
                } else {
                    $processed = $hasUpload ? self::processUpload($upload) : null;
                    $newPath = $processed === null ? null : self::storeLogo($processed);
                    try {
                        $oldPath = $this->settings->save(
                            $palette,
                            $newPath,
                            $processed['mime'] ?? null,
                            $logoShape
                        );
                    } catch (Throwable $exception) {
                        self::deleteStoredLogo($newPath);
                        throw $exception;
                    }
                    if ($newPath !== null && $oldPath !== $newPath) {
                        self::deleteStoredLogo($oldPath);
                    }
                }

                header('Location: index.php?action=diseno&guardado=1');
                exit;
            } catch (InvalidArgumentException | FileScannerUnavailableException | FileUploadConfigurationException $exception) {
                $error = $exception->getMessage();
            }
        }

        View::render('admin/diseno', [
            'palette' => $palette,
            'logoUrl' => $settings['logo_path'] === null ? null : 'index.php?action=branding-logo',
            'logoShape' => $logoShape,
            'saved' => isset($_GET['guardado']),
            'error' => $error,
        ]);
    }

    public function styles(): void
    {
        $settings = $this->settings->get();
        $palette = [
            'primary' => $settings['primary_color'],
            'secondary' => $settings['secondary_color'],
            'accent' => $settings['accent_color'],
        ];
        foreach (array_keys(BrandingPalette::DEFAULTS) as $name) {
            if (isset($_GET[$name])) {
                $palette[$name] = $_GET[$name];
            }
        }

        try {
            $css = BrandingPalette::stylesheet($palette);
        } catch (InvalidArgumentException) {
            http_response_code(400);
            header('Content-Type: text/css; charset=UTF-8');
            exit;
        }

        header('Content-Type: text/css; charset=UTF-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $css;
        exit;
    }

    public function logo(): void
    {
        $settings = $this->settings->get();
        $relativePath = $settings['logo_path'];
        $mime = $settings['logo_mime'];
        if (!is_string($relativePath) || !is_string($mime) || !self::isSafeLogoPath($relativePath)) {
            http_response_code(404);
            exit;
        }

        $path = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('No se pudo leer el logo privado del portal.');
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new RuntimeException('No se pudo leer el logo privado del portal.');
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: public, max-age=300');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'none'; sandbox");
        header('Cross-Origin-Resource-Policy: same-origin');
        echo $bytes;
        exit;
    }

    private static function processUpload(mixed $upload): array
    {
        if (
            !is_array($upload)
            || !is_int($upload['error'] ?? null)
            || $upload['error'] !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null)
            || !is_int($upload['size'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > BrandLogoProcessor::MAX_BYTES
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new InvalidArgumentException('El logo debe ser una imagen válida de hasta 2 MB.');
        }

        $extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
        $extensionsByMime = [
            'image/png' => ['png'],
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/svg+xml' => ['svg'],
        ];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($upload['tmp_name']);
        if (!is_string($mime) || !in_array($extension, $extensionsByMime[$mime] ?? [], true)) {
            throw new InvalidArgumentException('El formato y el contenido del logo no coinciden. Usa SVG, PNG o JPG.');
        }

        FileScanner::scan($upload['tmp_name']);
        $bytes = file_get_contents($upload['tmp_name']);
        if (!is_string($bytes)) {
            throw new InvalidArgumentException('No se pudo leer el archivo de logo.');
        }

        return BrandLogoProcessor::process($bytes, $mime);
    }

    private static function storeLogo(array $processed): string
    {
        $directory = fctStoragePath() . DIRECTORY_SEPARATOR . 'branding';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado del logo.');
        }
        $resolvedRoot = realpath(fctStoragePath());
        $resolvedDirectory = realpath($directory);
        if ($resolvedRoot === false || $resolvedDirectory !== $resolvedRoot . DIRECTORY_SEPARATOR . 'branding') {
            throw new RuntimeException('La ruta de almacenamiento del logo no es segura.');
        }

        $relativePath = 'branding/' . bin2hex(random_bytes(16)) . '.' . $processed['extension'];
        $destination = $resolvedDirectory . DIRECTORY_SEPARATOR . basename($relativePath);
        $written = file_put_contents($destination, $processed['bytes'], LOCK_EX);
        if ($written !== strlen($processed['bytes']) || !chmod($destination, 0600)) {
            self::deleteStoredLogo($relativePath);
            throw new RuntimeException('No se pudo guardar el logo en el almacenamiento privado.');
        }

        return $relativePath;
    }

    private static function deleteStoredLogo(mixed $relativePath): void
    {
        if (!is_string($relativePath) || !self::isSafeLogoPath($relativePath)) {
            return;
        }
        $path = fctStoragePath() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('No se pudo retirar el logo anterior del almacenamiento privado.');
        }
    }

    private static function isSafeLogoPath(string $relativePath): bool
    {
        return preg_match('/\Abranding\/[a-f0-9]{32}\.(?:png|svg)\z/', $relativePath) === 1;
    }
}
