<?php

declare(strict_types=1);

final class FileScannerUnavailableException extends RuntimeException
{
}

final class FileUploadConfigurationException extends RuntimeException
{
}

final class FileScanner
{
    public static function scan(string $file): void
    {
        $scanner = getenv('FCT_CLAMSCAN');
        if (!is_string($scanner) || trim($scanner) === '') {
            throw new FileScannerUnavailableException('El análisis antivirus no está configurado.');
        }
        $scanner = trim($scanner);
        $isAbsolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $scanner) === 1
            : str_starts_with($scanner, DIRECTORY_SEPARATOR);
        if (!$isAbsolute || !is_file($scanner)) {
            throw new FileScannerUnavailableException('La ruta del análisis antivirus no es válida.');
        }
        if (!function_exists('proc_open')) {
            throw new FileScannerUnavailableException('PHP no permite iniciar el análisis antivirus.');
        }

        $arguments = [$scanner, '--no-summary', '--stdout', '--fail-if-cvd-older-than=3'];
        $database = getenv('FCT_CLAMAV_DATABASE');
        if (is_string($database) && trim($database) !== '') {
            $database = trim($database);
            $databaseIsAbsolute = DIRECTORY_SEPARATOR === '\\'
                ? preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $database) === 1
                : str_starts_with($database, DIRECTORY_SEPARATOR);
            if (!$databaseIsAbsolute || !is_dir($database)) {
                throw new FileScannerUnavailableException('La ruta de la base antivirus no es válida.');
            }
            $arguments[] = '--database=' . $database;
        }
        $arguments[] = '--';
        $arguments[] = $file;

        $process = proc_open(
            $arguments,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new FileScannerUnavailableException('No se pudo iniciar el análisis antivirus.');
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + 30;
        $exitCode = -1;
        $timedOut = false;
        $status = ['running' => true, 'exitcode' => -1];
        do {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                $timedOut = true;
                break;
            }
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            usleep(100000);
        } while (true);

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($timedOut || !in_array($exitCode, [0, 1], true)) {
            throw new FileScannerUnavailableException('El análisis antivirus no pudo completarse.');
        }
        if ($exitCode === 1) {
            throw new InvalidArgumentException('El análisis antivirus ha rechazado el archivo.');
        }
    }
}
