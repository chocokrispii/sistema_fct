<?php

declare(strict_types=1);

function fctStoragePath(): string
{
    $configuredPath = getenv('FCT_STORAGE_PATH');
    $path = is_string($configuredPath) ? trim($configuredPath) : '';

    $isAbsolute = DIRECTORY_SEPARATOR === '\\'
        ? preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1
        : str_starts_with($path, DIRECTORY_SEPARATOR);
    if (!$isAbsolute || !is_dir($path)) {
        throw new RuntimeException('FCT_STORAGE_PATH debe configurarse con la ruta absoluta a un directorio existente.');
    }

    $resolvedPath = realpath($path);
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
    if (!is_string($documentRoot) || trim($documentRoot) === '') {
        $documentRoot = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public';
    }
    $resolvedDocumentRoot = realpath($documentRoot);
    if ($resolvedPath === false || $resolvedDocumentRoot === false) {
        throw new RuntimeException('No se pudo resolver la ruta de almacenamiento privado.');
    }

    $isWindows = DIRECTORY_SEPARATOR === '\\';
    $isSamePath = static fn (string $left, string $right): bool => $isWindows
        ? strcasecmp($left, $right) === 0
        : $left === $right;
    $pathPrefix = static function (string $candidate): string {
        $normalized = rtrim($candidate, DIRECTORY_SEPARATOR);
        if ($normalized === '') {
            $normalized = DIRECTORY_SEPARATOR;
        }

        return rtrim($normalized, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    };
    $isWithin = static function (string $candidate, string $parent) use ($isSamePath, $pathPrefix, $isWindows): bool {
        return $isSamePath($candidate, $parent)
            || ($isWindows
                ? stripos($pathPrefix($candidate), $pathPrefix($parent)) === 0
                : str_starts_with($pathPrefix($candidate), $pathPrefix($parent)));
    };
    if (
        $isWithin($resolvedPath, $resolvedDocumentRoot)
        || $isWithin($resolvedDocumentRoot, $resolvedPath)
    ) {
        throw new RuntimeException('El almacenamiento debe estar fuera y separado del document root.');
    }

    return $resolvedPath;
}
