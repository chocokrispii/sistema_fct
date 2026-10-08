<?php

declare(strict_types=1);

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (!is_string($testDatabase) || !preg_match('/^fct_test_[a-zA-Z0-9_]+$/', $testDatabase)) {
    fwrite(STDERR, "Define FCT_TEST_DB_NAME con una base desechable cuyo nombre empiece por fct_test_.\n");
    exit(2);
}
if (getenv('FCT_DB_NAME') !== $testDatabase) {
    fwrite(STDERR, "FCT_DB_NAME debe apuntar a la misma base de pruebas FCT_TEST_DB_NAME.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Models/Oferta.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Convenio.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getPDOConnection();
$actualDatabase = $db->query('SELECT DATABASE()')->fetchColumn();
assertTrue($actualDatabase === $testDatabase, 'La conexión no apunta a la base de prueba.');

$demoUsers = $db->query(
    "SELECT email, password_hash FROM usuarios
     WHERE email IN (
        'admin@centro.com',
        'coordinador@centro.com',
        'empresa.tech@gmail.com',
        'alumno.menor@alumno.com',
        'alumno.mayor@alumno.com'
     )"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($demoUsers as $demoUser) {
    assertTrue(
        password_verify('password123', $demoUser['password_hash']),
        'La contraseña de demostración no valida para ' . $demoUser['email'] . '.'
    );
}

$required = [
    'persona_contacto' => ['table' => 'empresas', 'column' => 'persona_contacto'],
    'fecha_nacimiento' => ['table' => 'alumnos', 'column' => 'fecha_nacimiento'],
    'turno' => ['table' => 'empresa_especialidades_plazas', 'column' => 'turno'],
];
foreach ($required as $name => $definition) {
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table AND COLUMN_NAME = :column'
    );
    $stmt->execute([
        'schema' => $testDatabase,
        'table' => $definition['table'],
        'column' => $definition['column'],
    ]);
    assertTrue((int)$stmt->fetchColumn() === 1, "Falta columna requerida para las migraciones: $name.");
}

$fixture = bin2hex(random_bytes(5));
$emails = [
    "company-$fixture@example.test",
    "student-a-$fixture@example.test",
    "student-b-$fixture@example.test",
    "coordinator-$fixture@example.test",
];
$dnis = ["A$fixture", "B$fixture", "C$fixture"];
$cifs = ["T$fixture"];
$createdUsers = [];
$createdCompany = null;
$createdStudents = [];
$createdOffer = null;
$createdCycleId = null;

try {
    $roleHash = password_hash('TestPassword123!', PASSWORD_DEFAULT);
    $addUser = $db->prepare('INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :role)');
    $addUser->execute(['email' => $emails[0], 'hash' => $roleHash, 'role' => 'Empresa']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[1], 'hash' => $roleHash, 'role' => 'Alumno']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[2], 'hash' => $roleHash, 'role' => 'Alumno']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[3], 'hash' => $roleHash, 'role' => 'Coordinador']);
    $createdUsers[] = (int)$db->lastInsertId();

    $cycleId = (int)$db->query('SELECT id FROM especialidades ORDER BY id LIMIT 1')->fetchColumn();
    if ($cycleId === 0) {
        $cycle = $db->prepare('INSERT INTO especialidades (nombre_especialidad) VALUES (:nombre)');
        $cycle->execute(['nombre' => "Ciclo de prueba $fixture"]);
        $cycleId = (int)$db->lastInsertId();
        $createdCycleId = $cycleId;
    }

    $companyInsert = $db->prepare(
        'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
         VALUES (:usuario, :nombre, :cif, :contacto)'
    );
    $companyInsert->execute([
        'usuario' => $createdUsers[0],
        'nombre' => "Test Company $fixture",
        'cif' => $cifs[0],
        'contacto' => 'Tutor de prueba',
    ]);
    $createdCompany = (int)$db->lastInsertId();

    $studentInsert = $db->prepare(
        'INSERT INTO alumnos (usuario_id, nombre, apellidos, fecha_nacimiento, dni,
            numero_seguridad_social, correo, especialidad_id)
         VALUES (:usuario, :nombre, :apellidos, :nacimiento, :dni, :nss, :correo, :ciclo)'
    );
    foreach ([1, 2] as $index) {
        $studentInsert->execute([
            'usuario' => $createdUsers[$index],
            'nombre' => 'Alumno',
            'apellidos' => "Prueba $fixture $index",
            'nacimiento' => '2005-01-01',
            'dni' => $dnis[$index],
            'nss' => "NSS-$fixture-$index",
            'correo' => $emails[$index],
            'ciclo' => $cycleId,
        ]);
        $createdStudents[] = (int)$db->lastInsertId();
    }

    $availability = $db->prepare(
        "INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
         VALUES (:alumno, '2027-03-01', '2027-06-30', 'Indiferente')"
    );
    foreach ($createdStudents as $studentId) {
        $availability->execute(['alumno' => $studentId]);
    }

    $agreement = $db->prepare(
        "INSERT INTO convenios (empresa_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path, estado)
         VALUES (:empresa, :numero, '2026-01-01', '2027-01-01', '2027-12-31', 'test-placeholder.pdf', 'Pendiente')"
    );
    $agreement->execute(['empresa' => $createdCompany, 'numero' => "AG-$fixture"]);
    $agreementId = (int)$db->lastInsertId();
    assertTrue(
        (new Convenio($db))->resolver($agreementId, 'Aprobado', '', $createdUsers[3]),
        'Un convenio pendiente debe poder aprobarse.'
    );

    $site = $db->prepare(
        'INSERT INTO empresa_sedes (empresa_id, nombre, direccion) VALUES (:empresa, :nombre, :direccion)'
    );
    $site->execute([
        'empresa' => $createdCompany,
        'nombre' => "Sede $fixture",
        'direccion' => 'Dirección de prueba',
    ]);

    $offer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, 1, '2027-03-15', '2027-06-15', 'M', 'Pendiente')"
    );
    $offer->execute(['empresa' => $createdCompany, 'ciclo' => $cycleId]);
    $createdOffer = (int)$db->lastInsertId();

    $matching = new Oferta($db);
    assertTrue(
        $matching->resolver($createdOffer, 'Aprobada', '', $createdUsers[3]),
        'Una oferta pendiente debe poder aprobarse.'
    );
    $candidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(count($candidates) === 2, 'El motor de compatibilidad debe encontrar los dos candidatos.');

    $assignments = new Asignacion($db);
    $assignments->crear($createdOffer, $createdStudents[0], $createdUsers[3], 'Tutor de empresa');
    assertTrue(count($matching->obtenerCandidatos($createdOffer)) === 1, 'Un alumno asignado no debe seguir siendo candidato.');

    $capacityRejected = false;
    try {
        $assignments->crear($createdOffer, $createdStudents[1], $createdUsers[3], 'Tutor de empresa');
    } catch (RuntimeException $e) {
        $capacityRejected = str_contains($e->getMessage(), 'plazas');
    }
    assertTrue($capacityRejected, 'El sistema debe impedir superar el cupo de plazas.');

    $assignmentId = (int)$db->query(
        'SELECT id FROM asignaciones_fct WHERE oferta_id = ' . $createdOffer . ' AND alumno_id = ' . $createdStudents[0]
    )->fetchColumn();
    assertTrue($assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación activa debe poder cerrarse.');
    assertTrue(!$assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación cerrada no debe cerrarse dos veces.');

    $_SESSION = [];
    $token = CsrfMiddleware::token();
    $_POST['_csrf'] = $token;
    CsrfMiddleware::verify();
    $_POST['_csrf'] = 'invalid';
    $csrfRejected = false;
    try {
        CsrfMiddleware::verify();
    } catch (RuntimeException $e) {
        $csrfRejected = true;
    }
    assertTrue($csrfRejected, 'Un token CSRF incorrecto debe rechazarse.');

    echo "OK: migraciones, revisión de convenios/ofertas, matching, capacidad, cierre de prácticas y CSRF.\n";
} finally {
    if ($createdOffer !== null) {
        $stmt = $db->prepare('DELETE FROM asignaciones_fct WHERE oferta_id = :offer');
        $stmt->execute(['offer' => $createdOffer]);
        $stmt = $db->prepare('DELETE FROM alumno_disponibilidad WHERE alumno_id IN (:first, :second)');
        $stmt->execute(['first' => $createdStudents[0] ?? 0, 'second' => $createdStudents[1] ?? 0]);
        $stmt = $db->prepare('DELETE FROM convenios WHERE empresa_id = :company');
        $stmt->execute(['company' => $createdCompany]);
        $stmt = $db->prepare('DELETE FROM empresa_sedes WHERE empresa_id = :company');
        $stmt->execute(['company' => $createdCompany]);
        $stmt = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :offer');
        $stmt->execute(['offer' => $createdOffer]);
    }
    if ($createdStudents !== []) {
        $stmt = $db->prepare('DELETE FROM alumnos WHERE id = :id');
        foreach ($createdStudents as $studentId) {
            $stmt->execute(['id' => $studentId]);
        }
    }
    if ($createdCompany !== null) {
        $stmt = $db->prepare('DELETE FROM empresas WHERE id = :id');
        $stmt->execute(['id' => $createdCompany]);
    }
    if ($createdUsers !== []) {
        $stmt = $db->prepare('DELETE FROM usuarios WHERE id = :id');
        foreach ($createdUsers as $userId) {
            $stmt->execute(['id' => $userId]);
        }
    }
    if ($createdCycleId !== null) {
        $stmt = $db->prepare('DELETE FROM especialidades WHERE id = :id');
        $stmt->execute(['id' => $createdCycleId]);
    }
}
