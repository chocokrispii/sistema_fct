<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function splitSqlStatements(string $sql): array
{
    $statements = [];
    $statement = '';
    $state = 'normal';
    $length = strlen($sql);

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($state === 'line_comment') {
            $statement .= $character;
            if ($character === "\n" || $character === "\r") {
                $state = 'normal';
            }
            continue;
        }
        if ($state === 'block_comment') {
            $statement .= $character;
            if ($character === '*' && $next === '/') {
                $statement .= $next;
                $index++;
                $state = 'normal';
            }
            continue;
        }
        if ($state !== 'normal') {
            $statement .= $character;
            if ($character === '\\' && $state !== '`' && $next !== '') {
                $statement .= $next;
                $index++;
                continue;
            }
            if ($character === $state) {
                if ($next === $state) {
                    $statement .= $next;
                    $index++;
                } else {
                    $state = 'normal';
                }
            }
            continue;
        }

        if ($character === '-' && $next === '-' && ($index + 2 === $length || ord($sql[$index + 2]) <= 32)) {
            $statement .= '--';
            $index++;
            $state = 'line_comment';
        } elseif ($character === '#') {
            $statement .= $character;
            $state = 'line_comment';
        } elseif ($character === '/' && $next === '*') {
            $statement .= '/*';
            $index++;
            $state = 'block_comment';
        } elseif ($character === "'" || $character === '"' || $character === '`') {
            $statement .= $character;
            $state = $character;
        } elseif ($character === ';') {
            if (trim($statement) !== '') {
                $statements[] = trim($statement);
            }
            $statement = '';
        } else {
            $statement .= $character;
        }
    }

    if ($state === 'block_comment' || $state === "'" || $state === '"' || $state === '`') {
        throw new RuntimeException('El archivo SQL termina dentro de un literal o comentario.');
    }
    if (trim($statement) !== '') {
        $statements[] = trim($statement);
    }

    return $statements;
}

function migrationFiles(): array
{
    $files = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]_*.sql');
    if ($files === false || $files === []) {
        throw new RuntimeException('No se encontraron archivos de migración.');
    }
    sort($files, SORT_STRING);
    return $files;
}

function migrationChecksum(string $file): ?string
{
    $contents = file_get_contents($file);
    if (!is_string($contents)) {
        return null;
    }

    return hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
}

function runMigration(PDO $db, string $file): void
{
    $version = basename($file);
    $checksum = migrationChecksum($file);
    if (!is_string($checksum)) {
        throw new RuntimeException("No se pudo calcular el checksum de $version.");
    }

    $previous = $db->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
    $previous->execute(['version' => $version]);
    $appliedChecksum = $previous->fetchColumn();
    if (is_string($appliedChecksum)) {
        if (!hash_equals($appliedChecksum, $checksum)) {
            throw new RuntimeException(
                "La migración aplicada $version fue modificada. Restaura su archivo original y crea otra migración."
            );
        }
        echo "OMITIDA $version (ya aplicada)\n";
        return;
    }

    if ($version === '001_renombrar_nombre_especialidad.sql') {
        $columns = $db->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'especialidades'
               AND COLUMN_NAME IN ('nombre_specialidad', 'nombre_especialidad')"
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($columns === ['nombre_especialidad']) {
            $uniqueIndex = $db->query(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'especialidades'
                   AND COLUMN_NAME = 'nombre_especialidad' AND NON_UNIQUE = 0"
            )->fetchColumn();
            if ((int)$uniqueIndex === 0) {
                $db->exec(
                    'ALTER TABLE especialidades
                     ADD UNIQUE KEY nombre_especialidad (nombre_especialidad)'
                );
                echo "COMPLETADA $version (añadido el índice único que faltaba)\n";
            } else {
                echo "ADOPTADA $version (la columna y su índice único ya existen)\n";
            }
        } elseif ($columns !== ['nombre_specialidad']) {
            throw new RuntimeException(
                'No se puede determinar el estado de 001: falta la columna nombre_specialidad o nombre_especialidad.'
            );
        } else {
            foreach (splitSqlStatements((string)file_get_contents($file)) as $statement) {
                $db->exec($statement);
            }
        }
    } else {
        $contents = file_get_contents($file);
        if (!is_string($contents)) {
            throw new RuntimeException("No se pudo leer la migración $version.");
        }
        foreach (splitSqlStatements($contents) as $statement) {
            $db->exec($statement);
        }
    }

    $record = $db->prepare(
        'INSERT INTO schema_migrations (version, checksum) VALUES (:version, :checksum)'
    );
    $record->execute(['version' => $version, 'checksum' => $checksum]);
    echo "APLICADA $version\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$argument = $argv[1] ?? 'migrate';
if (!in_array($argument, ['migrate', '--status'], true) || isset($argv[2])) {
    fwrite(STDERR, "Uso: php scripts/migrate.php [--status]\n");
    exit(2);
}

try {
    $db = getPDOConnection();
    $ledgerExists = $db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'"
    )->fetchColumn() > 0;

    if ($argument === '--status') {
        if (!$ledgerExists) {
            echo "El registro de migraciones aún no existe.\n";
            exit(0);
        }
        foreach (migrationFiles() as $file) {
            $version = basename($file);
            $stmt = $db->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
            $stmt->execute(['version' => $version]);
            $storedChecksum = $stmt->fetchColumn();
            $currentChecksum = migrationChecksum($file);
            if ($storedChecksum === false) {
                echo "PENDIENTE $version\n";
            } elseif (!is_string($currentChecksum) || !hash_equals((string)$storedChecksum, $currentChecksum)) {
                echo "ERROR $version (checksum distinto)\n";
                exit(1);
            } else {
                echo "APLICADA $version\n";
            }
        }
        exit(0);
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $lockName = 'fct_migrations_' . substr(hash('sha256', DB_NAME), 0, 32);
    $lock = $db->prepare('SELECT GET_LOCK(:name, 30)');
    $lock->execute(['name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudo obtener el bloqueo de migraciones en 30 segundos.');
    }

    try {
        foreach (migrationFiles() as $file) {
            runMigration($db, $file);
        }
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute(['name' => $lockName]);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error de migración: ' . $exception->getMessage() . "\n");
    exit(1);
}
