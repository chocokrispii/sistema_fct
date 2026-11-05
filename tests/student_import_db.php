<?php

declare(strict_types=1);

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (
    !is_string($testDatabase)
    || !preg_match('/^fct_test_[a-zA-Z0-9_]+$/', $testDatabase)
    || getenv('FCT_DB_NAME') !== $testDatabase
) {
    fwrite(STDERR, "Define FCT_TEST_DB_NAME y FCT_DB_NAME con la misma base fct_test_*.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/StudentImporter.php';

function assertStudentImportDb(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function studentImportDni(int $number): string
{
    return str_pad((string)$number, 8, '0', STR_PAD_LEFT)
        . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
}

$db = getPDOConnection();
$admin = (int)$db->query("SELECT id FROM usuarios WHERE rol = 'Administrador' LIMIT 1")->fetchColumn();
$cycle = $db->query("SELECT id, nombre_especialidad FROM especialidades WHERE activo = 1 ORDER BY id LIMIT 1")
    ->fetch(PDO::FETCH_ASSOC);
if ($admin < 1 || !is_array($cycle)) {
    throw new RuntimeException('Las pruebas requieren un administrador y un ciclo activo.');
}

$domain = '@student-import-test.example';
$cleanup = static function () use ($db, $domain): void {
    $users = "SELECT id FROM usuarios WHERE email LIKE '%" . $domain . "'";
    $students = "SELECT id FROM alumnos WHERE usuario_id IN ($users)";
    $db->exec("DELETE FROM student_activation_tokens WHERE usuario_id IN ($users)");
    $db->exec("DELETE FROM auditoria WHERE entidad = 'alumnos' AND entidad_id IN ($students)");
    $db->exec("DELETE FROM audit_logs WHERE entidad_afectada = 'alumnos' AND id_registro_modificado IN ($students)");
    $db->exec("DELETE FROM alumnos WHERE usuario_id IN ($users)");
    $db->exec("DELETE FROM usuarios WHERE email LIKE '%" . $domain . "'");
};
$cleanup();

try {
    $seed = random_int(10000000, 89999999);
    $valid = [
        [
            'linea' => 2,
            'email' => "uno$seed$domain",
            'dni' => studentImportDni($seed),
            'nombre' => 'Ana',
            'apellidos' => 'Pérez',
            'ciclo' => $cycle['nombre_especialidad'],
            'curso' => '1',
        ],
        [
            'linea' => 3,
            'email' => "dos$seed$domain",
            'dni' => studentImportDni($seed + 1),
            'nombre' => 'Luis',
            'apellidos' => 'Gil',
            'ciclo' => $cycle['nombre_especialidad'],
            'curso' => '2',
        ],
    ];
    $rows = [
        $valid[0],
        [...$valid[1], 'email' => $valid[0]['email']],
        [...$valid[1], 'linea' => 4, 'email' => "tres$seed$domain", 'dni' => $valid[0]['dni']],
        [...$valid[1], 'linea' => 5, 'email' => "cuatro$seed$domain", 'dni' => studentImportDni($seed + 2), 'curso' => '3'],
    ];
    $sent = [];
    $importer = new StudentImporter($db, static function (string $email, string $name, string $url) use (&$sent): void {
        $sent[] = [$email, $name, $url];
    });
    $preview = $importer->preview($rows, [$cycle]);
    assertStudentImportDb($preview['total'] === 4 && count($preview['valid_rows']) === 1, 'La previsualización debe separar datos válidos y erróneos.');
    assertStudentImportDb(
        count($preview['errors']) === 3
            && str_contains($preview['errors'][0]['motivo'], 'Correo duplicado')
            && str_contains($preview['errors'][1]['motivo'], 'DNI/NIE duplicado')
            && str_contains($preview['errors'][2]['motivo'], 'curso debe ser 1 o 2'),
        'Debe explicar los duplicados y los campos inválidos por fila.'
    );

    putenv('FCT_APP_URL=https://fct.example.test');
    $result = $importer->import(
        $preview['valid_rows'],
        $admin,
        'alumnos.xlsx',
        str_repeat('a', 64),
        $preview['total'],
        count($preview['errors'])
    );
    assertStudentImportDb($result['insertados'] === 1 && count($result['resultados']) === 1, 'Debe importar la fila válida sin detenerse por errores de las demás.');
    assertStudentImportDb($result['resultados'][0]['estado'] === 'Importado', 'El informe debe identificar la fila creada.');
    assertStudentImportDb(count($sent) === 1 && str_starts_with($sent[0][2], 'https://fct.example.test/'), 'Debe enviar enlace seguro de activación por alumno.');

    $user = $db->prepare('SELECT id, activo, rol FROM usuarios WHERE email = :email');
    $user->execute(['email' => $valid[0]['email']]);
    $account = $user->fetch(PDO::FETCH_ASSOC);
    assertStudentImportDb(
        is_array($account) && (int)$account['activo'] === 0 && $account['rol'] === 'Alumno',
        'La cuenta importada debe quedar inactiva hasta su activación.'
    );
    $student = $db->prepare('SELECT dni, especialidad_id, curso FROM alumnos WHERE usuario_id = :usuario');
    $student->execute(['usuario' => (int)$account['id']]);
    $studentData = $student->fetch(PDO::FETCH_ASSOC);
    assertStudentImportDb(
        is_array($studentData)
            && $studentData['dni'] === $valid[0]['dni']
            && (int)$studentData['especialidad_id'] === (int)$cycle['id']
            && (int)$studentData['curso'] === 1,
        'El expediente debe guardar documento, ciclo y curso del archivo.'
    );
    $visibleInPrincipal = (new Alumno($db))->obtenerTodosParaCentro($valid[0]['email']);
    $exportedStudents = (new Alumno($db))->obtenerTodosParaExportacion();
    assertStudentImportDb(
        count($visibleInPrincipal) === 1
            && (int)$visibleInPrincipal[0]['usuario_id'] === (int)$account['id']
            && in_array($valid[0]['email'], array_column($exportedStudents, 'correo'), true),
        'Cada alta importada debe aparecer en el listado principal y en la siguiente descarga de alumnado.'
    );
    $token = $db->prepare('SELECT token_hash FROM student_activation_tokens WHERE usuario_id = :usuario');
    $token->execute(['usuario' => (int)$account['id']]);
    assertStudentImportDb(
        preg_match('/\A[a-f0-9]{64}\z/', (string)$token->fetchColumn()) === 1,
        'La importación debe guardar solo el hash del token de activación.'
    );
    $previousTokenHash = (string)$db->query(
        'SELECT token_hash FROM student_activation_tokens WHERE usuario_id = ' . (int)$account['id']
        . ' AND used_at IS NULL ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    $pendingPreview = $importer->preview([$valid[0]], [$cycle]);
    assertStudentImportDb(
        $pendingPreview['valid_rows'] === []
            && count($pendingPreview['activation_rows']) === 1
            && $pendingPreview['errors'] === [],
        'Una cuenta recién importada y pendiente debe poder identificarse para el reenvío de activación.'
    );
    $resend = $importer->resendActivation($pendingPreview['activation_rows'], $admin);
    assertStudentImportDb(
        $resend['enviados'] === 1
            && $resend['resultados'][0]['estado'] === 'Correo de activación enviado'
            && count($sent) === 2,
        'El reenvío debe enviar un nuevo correo sin volver a crear la cuenta.'
    );
    $activeTokens = $db->prepare(
        'SELECT token_hash FROM student_activation_tokens
         WHERE usuario_id = :usuario AND used_at IS NULL ORDER BY id DESC LIMIT 1'
    );
    $activeTokens->execute(['usuario' => (int)$account['id']]);
    $resentTokenHash = (string)$activeTokens->fetchColumn();
    assertStudentImportDb(
        $resentTokenHash !== ''
            && $resentTokenHash !== $previousTokenHash
            && preg_match('/\A[a-f0-9]{64}\z/', $resentTokenHash) === 1,
        'El reenvío debe invalidar el enlace anterior y guardar un nuevo token solo como hash.'
    );
    $mismatchedRow = $valid[0];
    $mismatchedRow['dni'] = studentImportDni($seed + 100);
    $staleWorkbookPreview = $importer->preview([$mismatchedRow], [$cycle]);
    assertStudentImportDb(
        $staleWorkbookPreview['valid_rows'] === []
            && count($staleWorkbookPreview['activation_rows']) === 1
            && $staleWorkbookPreview['errors'] === [],
        'Un archivo antiguo debe reconocer por correo las cuentas pendientes y ofrecer el reenvío sin importar de nuevo.'
    );
    $resentDespiteWorkbookMismatch = $importer->resendActivation($staleWorkbookPreview['activation_rows'], $admin);
    assertStudentImportDb(
        $resentDespiteWorkbookMismatch['enviados'] === 1
            && $resentDespiteWorkbookMismatch['resultados'][0]['estado'] === 'Correo de activación enviado'
            && count($sent) === 3,
        'Un archivo antiguo puede reenviar al correo de una cuenta pendiente sin reemplazar sus datos.'
    );
    $audit = $db->query(
        "SELECT detalle FROM audit_logs WHERE evento = 'alumnos.importacion_masiva' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    assertStudentImportDb(
        is_string($audit)
            && str_contains($audit, 'filas=4')
            && str_contains($audit, 'exitosas=1')
            && str_contains($audit, 'fallidas=3')
            && str_contains($audit, 'sha256='),
        'La auditoría de importación debe incluir el total real y filas fallidas del archivo.'
    );

    $duplicate = $importer->import($preview['valid_rows'], $admin, 'alumnos.xlsx', str_repeat('b', 64));
    assertStudentImportDb(
        $duplicate['insertados'] === 0
            && $duplicate['resultados'][0]['estado'] === 'Error'
            && str_contains($duplicate['resultados'][0]['motivo'], 'correo ya está registrado'),
        'La confirmación debe volver a detectar duplicados actuales en la base de datos.'
    );
} finally {
    $cleanup();
}

echo "student_import_db: OK\n";
