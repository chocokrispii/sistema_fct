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
        $clamdHost = getenv('FCT_CLAMD_HOST');
        if (is_string($clamdHost) && trim($clamdHost) !== '') {
            self::scanWithClamd($file, trim($clamdHost));
            return;
        }

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

    private static function scanWithClamd(string $file, string $host): void
    {
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/', $host)) {
            throw new FileScannerUnavailableException('El servidor de análisis antivirus no es válido.');
        }
        if (!is_file($file) || !is_readable($file)) {
            throw new FileScannerUnavailableException('No se pudo leer el archivo para analizarlo.');
        }

        $portValue = getenv('FCT_CLAMD_PORT');
        $port = $portValue === false || trim($portValue) === '' ? 3310 : filter_var(
            trim($portValue),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if (!is_int($port)) {
            throw new FileScannerUnavailableException('El puerto del análisis antivirus no es válido.');
        }

        $versionSocket = self::openClamdSocket($host, $port);
        try {
            stream_set_timeout($versionSocket, 5);
            self::writeClamd($versionSocket, "zVERSION\0");
            self::assertFreshSignatures(self::readClamdResponse($versionSocket));
        } finally {
            fclose($versionSocket);
        }

        // Clamd closes the TCP connection after processing each command.
        $socket = self::openClamdSocket($host, $port);
        $input = null;
        try {
            stream_set_timeout($socket, 30);
            $input = fopen($file, 'rb');
            if (!is_resource($input)) {
                throw new FileScannerUnavailableException('No se pudo leer el archivo para analizarlo.');
            }
            self::writeClamd($socket, "zINSTREAM\0");
            while (!feof($input)) {
                $chunk = fread($input, 8192);
                if ($chunk === false) {
                    throw new FileScannerUnavailableException('No se pudo leer el archivo para analizarlo.');
                }
                if ($chunk !== '') {
                    self::writeClamd($socket, pack('N', strlen($chunk)) . $chunk);
                }
            }
            self::writeClamd($socket, pack('N', 0));

            $response = self::readClamdResponse($socket);
            if (str_ends_with($response, 'FOUND')) {
                throw new InvalidArgumentException('El análisis antivirus ha rechazado el archivo.');
            }
            if (!str_ends_with($response, 'OK')) {
                throw new FileScannerUnavailableException('El análisis antivirus no pudo completar el análisis.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            fclose($socket);
        }
    }

    private static function openClamdSocket(string $host, int $port)
    {
        $socket = @stream_socket_client(
            'tcp://' . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            5,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($socket)) {
            throw new FileScannerUnavailableException('No se pudo conectar con el análisis antivirus.');
        }

        return $socket;
    }

    private static function assertFreshSignatures(string $response): void
    {
        if (!preg_match('/\AClamAV \S+\/\d+\/(.+)\z/', $response, $matches)) {
            throw new FileScannerUnavailableException('No se pudo comprobar la fecha de las firmas antivirus.');
        }

        $signatureDate = DateTimeImmutable::createFromFormat(
            '!D M d H:i:s Y',
            $matches[1],
            new DateTimeZone('UTC')
        );
        if (!$signatureDate || $signatureDate->getTimestamp() < time() - 3 * 86400) {
            throw new FileScannerUnavailableException('Las firmas antivirus tienen más de tres días.');
        }
    }

    private static function writeClamd($socket, string $data): void
    {
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = @fwrite($socket, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new FileScannerUnavailableException('Se interrumpió la comunicación con el análisis antivirus.');
            }
            $offset += $written;
        }
    }

    private static function readClamdResponse($socket): string
    {
        $response = '';
        while (!str_contains($response, "\0")) {
            $chunk = fread($socket, 1024);
            $metadata = stream_get_meta_data($socket);
            if ($chunk === false || ($chunk === '' && ($metadata['timed_out'] || feof($socket)))) {
                throw new FileScannerUnavailableException('No se recibió respuesta del análisis antivirus.');
            }
            $response .= $chunk;
            if (strlen($response) > 4096) {
                throw new FileScannerUnavailableException('La respuesta del análisis antivirus no es válida.');
            }
        }

        return substr($response, 0, strpos($response, "\0"));
    }
}
