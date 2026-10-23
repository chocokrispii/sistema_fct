<?php

declare(strict_types=1);

require_once __DIR__ . '/FileScanner.php';
require_once __DIR__ . '/../../config/storage.php';

final class PrivatePdfStorage
{
    /**
     * @return array{filename: string, path: string, relative_path: string}
     */
    public static function store(array $upload, string $relativeDirectory): array
    {
        if (
            preg_match('/\A[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9]+)?(?:\/[a-z0-9]+(?:[a-z0-9_-]*[a-z0-9]+)?)*\z/', $relativeDirectory) !== 1
        ) {
            throw new InvalidArgumentException('El destino privado del archivo no es válido.');
        }
        if (
            ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_int($upload['size'] ?? null)
            || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 10 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new InvalidArgumentException('Adjunta un PDF válido de hasta 10 MB.');
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('El documento debe ser un archivo PDF.');
        }

        FileScanner::scan($upload['tmp_name']);

        $storageRoot = fctStoragePath();
        if (is_link($storageRoot) || !chmod($storageRoot, 0700)) {
            throw new RuntimeException('No se pudieron establecer los permisos privados de almacenamiento.');
        }
        $directory = $storageRoot;
        foreach (explode('/', $relativeDirectory) as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            if (!is_dir($directory) && !mkdir($directory, 0700) && !is_dir($directory)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
            }
            if (is_link($directory) || !chmod($directory, 0700)) {
                throw new RuntimeException('No se pudieron establecer los permisos privados de almacenamiento.');
            }
        }

        $filename = bin2hex(random_bytes(24)) . '.pdf';
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el archivo privado del convenio.');
        }
        if (!chmod($destination, 0600)) {
            if (!unlink($destination)) {
                error_log('No se pudo retirar un PDF con permisos privados inseguros.');
            }
            throw new RuntimeException('No se pudieron establecer permisos privados para el convenio.');
        }

        return [
            'filename' => $filename,
            'path' => $destination,
            'relative_path' => $relativeDirectory . '/' . $filename,
        ];
    }
}
