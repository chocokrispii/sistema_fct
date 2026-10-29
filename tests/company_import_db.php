<?php

declare(strict_types=1);

// Integración: requiere una base desechable fct_test_* con el esquema y las migraciones aplicadas.
$testDatabase = getenv('FCT_TEST_DB_NAME');
if (!is_string($testDatabase) || !preg_match('/^fct_test_[a-zA-Z0-9_]+$/', $testDatabase) || getenv('FCT_DB_NAME') !== $testDatabase) {
    fwrite(STDERR, "Define FCT_TEST_DB_NAME y FCT_DB_NAME con la misma base fct_test_*.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/CompanyImporter.php';

function assertImport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getPDOConnection();
putenv('APP_ENV=development');
putenv('FCT_APP_URL=http://localhost:8080');
putenv('FCT_MAIL_TRANSPORT=mailpit');
putenv('FCT_MAIL_HOST=mailpit');
putenv('FCT_MAIL_PORT=1025');
putenv('FCT_MAIL_ENCRYPTION=none');
putenv('FCT_MAIL_FROM=fct@example.test');

$admin = $db->query("SELECT id FROM usuarios WHERE rol = 'Administrador' LIMIT 1")->fetchColumn();
if ($admin === false) {
    $db->exec("INSERT INTO usuarios (email, password_hash, rol, activo) VALUES ('import-admin@example.test', 'x', 'Administrador', 1)");
    $admin = (int)$db->lastInsertId();
}
$admin = (int)$admin;

$cleanup = static function () use ($db): void {
    $emails = "SELECT id FROM usuarios WHERE email LIKE '%@import-test.example'";
    $db->exec("DELETE FROM company_activation_tokens WHERE usuario_id IN ($emails)");
    $db->exec("DELETE FROM direcciones_telefonos WHERE empresa_id IN (SELECT id FROM empresas WHERE usuario_id IN ($emails))");
    $db->exec("DELETE FROM empresas WHERE usuario_id IN ($emails)");
    $db->exec("DELETE FROM usuarios WHERE email LIKE '%@import-test.example'");
};
$cleanup();

try {
    $rows = [
        ['linea' => 2, 'razon_social' => 'ACME S.A.', 'nif' => 'A58818501', 'email' => 'a@import-test.example', 'representante' => 'Ana'],
        ['linea' => 3, 'razon_social' => 'Beta', 'nif' => '12345678Z', 'email' => 'b@import-test.example', 'representante' => 'Luis'],
        ['linea' => 4, 'razon_social' => 'Gamma', 'nif' => 'A58818501', 'email' => 'c@import-test.example', 'representante' => 'Eva'],
        ['linea' => 5, 'razon_social' => 'Delta', 'nif' => 'B12345674', 'email' => 'no-es-email', 'representante' => 'Ivo'],
        ['linea' => 6, 'razon_social' => 'Eps', 'nif' => 'B12345674', 'email' => 'a@import-test.example', 'representante' => 'Pau'],
    ];
    $result = (new CompanyImporter($db))->import($rows, $admin, 'empresas.xlsx', str_repeat('a', 64));

    assertImport($result['total_procesados'] === 5, 'Debe contar 5 filas procesadas.');
    assertImport($result['insertados'] === 1 && $result['fallidos'] === 4, 'Solo la primera fila es válida.');
    $byLine = array_column($result['errores'], 'motivo', 'linea');
    assertImport(isset($byLine[3], $byLine[4], $byLine[5], $byLine[6]), 'Cada fila errónea debe reportarse.');

    $user = $db->query("SELECT id, activo, rol FROM usuarios WHERE email = 'a@import-test.example'")->fetch(PDO::FETCH_ASSOC);
    assertImport(is_array($user) && (int)$user['activo'] === 0 && $user['rol'] === 'Empresa', 'La cuenta debe crearse inactiva.');

    $token = $db->query("SELECT token_hash, expires_at, created_at, TIMESTAMPDIFF(HOUR, CURRENT_TIMESTAMP, expires_at) AS horas FROM company_activation_tokens WHERE usuario_id = " . (int)$user['id'])->fetch(PDO::FETCH_ASSOC);
    assertImport(is_array($token) && preg_match('/\A[a-f0-9]{64}\z/', $token['token_hash']) === 1, 'Token SHA-256 de 256 bits esperado.');
    assertImport((int)$token['horas'] >= 23 && (int)$token['horas'] <= 24, 'El token debe caducar en 24 h.');

    // Segunda importación: ahora el NIF y el correo ya existen en base de datos.
    $again = (new CompanyImporter($db))->import([$rows[0]], $admin, 'empresas.xlsx', str_repeat('b', 64));
    assertImport($again['insertados'] === 0 && $again['fallidos'] === 1, 'Un duplicado en BD debe rechazarse.');

    $audit = $db->query("SELECT detalle FROM audit_logs WHERE evento = 'empresas.importacion_creada' ORDER BY id DESC LIMIT 1")->fetchColumn();
    assertImport(is_string($audit) && str_contains($audit, 'exitosas=0') && str_contains($audit, 'fallidas=1') && str_contains($audit, 'sha256='), 'La auditoría debe registrar resultado y hash.');
} finally {
    $cleanup();
}

echo "company_import_db: OK\n";
