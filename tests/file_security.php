<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/storage.php';
require_once __DIR__ . '/../app/Core/FileScanner.php';
require_once __DIR__ . '/../app/Core/PhpIni.php';

function assertFileSecurity(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$routes = require __DIR__ . '/../routes/web.php';
assertFileSecurity(
    $routes['descargar-convenio']['roles'] === ['Administrador', 'Coordinador', 'Empresa'],
    'La matriz de acceso a convenios concede acceso a un rol no autorizado.'
);
assertFileSecurity(PhpIni::sizeToBytes('12M') === 12 * 1024 * 1024, 'No se interpretó el límite PHP de 12 MiB.');
assertFileSecurity(PhpIni::sizeToBytes('512K') === 512 * 1024, 'No se interpretó un límite PHP en KiB.');
assertFileSecurity(PhpIni::sizeToBytes('0') === PHP_INT_MAX, 'El límite PHP 0 debe representar tamaño ilimitado.');
assertFileSecurity(PhpIni::sizeToBytes('invalid') === 0, 'Un límite PHP inválido no se rechazó de forma segura.');

$publicPath = realpath(__DIR__ . '/../public');
$originalStorage = getenv('FCT_STORAGE_PATH');
$hadDocumentRoot = array_key_exists('DOCUMENT_ROOT', $_SERVER);
$originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
$testStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fct-file-security-' . bin2hex(random_bytes(8));
assertFileSecurity($publicPath !== false, 'No se pudo resolver el document root de prueba.');
assertFileSecurity(mkdir($testStoragePath), 'No se pudo crear el almacenamiento temporal de prueba.');
$_SERVER['DOCUMENT_ROOT'] = $publicPath;

try {
    putenv('FCT_STORAGE_PATH=' . $testStoragePath);
    $storagePath = fctStoragePath();
    assertFileSecurity(
        $storagePath === realpath($testStoragePath),
        'No se aceptó un almacenamiento correctamente configurado fuera del document root.'
    );

    putenv('FCT_STORAGE_PATH');
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó el almacenamiento sin configurar.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'FCT_STORAGE_PATH debe configurarse'),
            'La ausencia de FCT_STORAGE_PATH se rechazó por un motivo inesperado.'
        );
    }

    putenv('FCT_STORAGE_PATH=' . realpath(__DIR__ . '/../public/assets'));
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó una ruta de almacenamiento dentro del document root.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'fuera y separado del document root'),
            'La ruta dentro del document root se rechazó por un motivo inesperado.'
        );
    }

    putenv('FCT_STORAGE_PATH=' . realpath(__DIR__ . '/..'));
    try {
        fctStoragePath();
        throw new RuntimeException('Se aceptó un almacenamiento que contiene el document root.');
    } catch (RuntimeException $exception) {
        assertFileSecurity(
            str_contains($exception->getMessage(), 'fuera y separado del document root'),
            'Una ruta que contiene el document root se rechazó por un motivo inesperado.'
        );
    }
} finally {
    if ($originalStorage === false) {
        putenv('FCT_STORAGE_PATH');
    } else {
        putenv('FCT_STORAGE_PATH=' . $originalStorage);
    }
    if ($hadDocumentRoot) {
        $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot;
    } else {
        unset($_SERVER['DOCUMENT_ROOT']);
    }
    rmdir($testStoragePath);
}

$originalScanner = getenv('FCT_CLAMSCAN');
putenv('FCT_CLAMSCAN');
$scannerRejected = false;
try {
    FileScanner::scan(__FILE__);
} catch (FileScannerUnavailableException) {
    $scannerRejected = true;
} finally {
    if ($originalScanner === false) {
        putenv('FCT_CLAMSCAN');
    } else {
        putenv('FCT_CLAMSCAN=' . $originalScanner);
    }
}
assertFileSecurity($scannerRejected, 'Se aceptó un archivo sin análisis antivirus configurado.');

echo "OK: matriz de descargas, ruta privada y rechazo sin antivirus.\n";
