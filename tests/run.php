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
require_once __DIR__ . '/../app/Core/Webhook.php';
require_once __DIR__ . '/../app/Core/ApiToken.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/AlumnoController.php';
require_once __DIR__ . '/../app/Controllers/CalificacionController.php';
require_once __DIR__ . '/../app/Controllers/DisponibilidadController.php';
require_once __DIR__ . '/../app/Controllers/InicioController.php';
require_once __DIR__ . '/../app/Controllers/OfertaController.php';
require_once __DIR__ . '/../app/Controllers/UsuarioAdminController.php';
require_once __DIR__ . '/../app/Models/Oferta.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Alumno.php';
require_once __DIR__ . '/../app/Models/Disponibilidad.php';
require_once __DIR__ . '/../app/Models/Calificacion.php';
require_once __DIR__ . '/../app/Models/Convenio.php';
require_once __DIR__ . '/../app/Models/RecuperacionCuenta.php';
require_once __DIR__ . '/../app/Models/Usuario.php';
require_once __DIR__ . '/../app/Models/Especialidad.php';
require_once __DIR__ . '/../app/Core/SpanishDataValidator.php';
require_once __DIR__ . '/../app/Controllers/ConvenioController.php';
require_once __DIR__ . '/../app/Controllers/ConsultaController.php';
require_once __DIR__ . '/../app/Models/Empresa.php';
require_once __DIR__ . '/../app/Controllers/WebhookCompanyActivityController.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/file_security.php';

$db = getPDOConnection();
$actualDatabase = $db->query('SELECT DATABASE()')->fetchColumn();
assertTrue($actualDatabase === $testDatabase, 'La conexión no apunta a la base de prueba.');
require_once __DIR__ . '/../scripts/migrate.php';
$parsedSql = splitSqlStatements(
    "INSERT INTO sample (value) VALUES ('one;two');\nSELECT `semi;colon` FROM sample;"
);
assertTrue(
    count($parsedSql) === 2
        && str_contains($parsedSql[0], 'one;two')
        && str_contains($parsedSql[1], '`semi;colon`'),
    'El gestor debe separar sentencias sin dividir los puntos y coma dentro de literales o identificadores.'
);
$migrationFiles = glob(__DIR__ . '/../database/migrations/[0-9][0-9][0-9]_*.sql');
assertTrue(is_array($migrationFiles), 'No se pudieron enumerar las migraciones.');
foreach ($migrationFiles as $migrationFile) {
    $parsedMigration = splitSqlStatements((string)file_get_contents($migrationFile));
    assertTrue($parsedMigration !== [], 'No se pudo analizar la migración ' . basename($migrationFile) . '.');
}
$appliedMigrations = $db->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($migrationFiles as $migrationFile) {
    $migrationVersion = basename($migrationFile);
    $migrationChecksum = migrationChecksum($migrationFile);
    assertTrue(
        is_string($migrationChecksum)
            && isset($appliedMigrations[$migrationVersion])
            && hash_equals($appliedMigrations[$migrationVersion], $migrationChecksum),
        "La migración $migrationVersion no está registrada o su checksum no coincide."
    );
}
$baseTableCollations = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME IN (
           'alumnos', 'coordinadores', 'direcciones_telefonos', 'empresas',
           'empresa_especialidades_plazas', 'especialidades', 'tutores_legales', 'usuarios'
       )
       AND TABLE_COLLATION <> 'utf8mb4_unicode_ci'"
)->fetchColumn();
assertTrue(
    (int)$baseTableCollations === 0,
    'Las tablas base deben usar utf8mb4_unicode_ci; ejecuta la migración 009.'
);
$offerConstraints = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_TYPE = 'CHECK'
       AND CONSTRAINT_NAME IN ('chk_oferta_plazas_positivas', 'chk_oferta_fechas')"
)->fetchColumn();
assertTrue(
    (int)$offerConstraints === 2,
    'Faltan restricciones CHECK de las ofertas; ejecuta la migración 010.'
);
$cycles = (new Especialidad($db))->obtenerTodas();
$dam = array_values(array_filter(
    $cycles,
    static fn(array $cycle): bool => $cycle['nombre_especialidad'] === 'Desarrollo de Aplicaciones Multiplataforma (DAM)'
))[0] ?? null;
assertTrue($dam !== null, 'DAM debe estar disponible como ciclo formativo.');
$specialtyValidator = new Especialidad($db);
assertTrue($specialtyValidator->existe((int)$dam['id']), 'La validación debe aceptar el ciclo DAM existente.');
assertTrue(!$specialtyValidator->existe(PHP_INT_MAX), 'La validación debe rechazar ciclos que no existan.');

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
$demoDnis = $db->query(
    "SELECT dni FROM alumnos WHERE correo IN ('lucas@correo.com', 'sofia@correo.com')"
)->fetchAll(PDO::FETCH_COLUMN);
assertTrue(count($demoDnis) === 2, 'Faltan expedientes de alumno de demostración.');
foreach ($demoDnis as $demoDni) {
    assertTrue(
        is_string($demoDni) && SpanishDataValidator::isDniNie($demoDni),
        'El DNI/NIE de un alumno de demostración no tiene una letra de control válida.'
    );
}

$required = [
    'persona_contacto' => ['table' => 'empresas', 'column' => 'persona_contacto'],
    'fecha_nacimiento' => ['table' => 'alumnos', 'column' => 'fecha_nacimiento'],
    'turno' => ['table' => 'empresa_especialidades_plazas', 'column' => 'turno'],
    'especialidad_id' => ['table' => 'convenios', 'column' => 'especialidad_id'],
    'dia_semana' => ['table' => 'alumno_disponibilidad_semanal', 'column' => 'dia_semana'],
    'es_indiferente' => ['table' => 'alumno_disponibilidad', 'column' => 'es_indiferente'],
    'nota' => ['table' => 'calificaciones_fct', 'column' => 'nota'],
    'codigo_postal' => ['table' => 'alumnos', 'column' => 'codigo_postal'],
    'horario_oferta' => ['table' => 'empresa_oferta_horarios_semanales', 'column' => 'hora_inicio'],
    'jornada_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'jornada'],
    'fecha_firma_convenio_empresa' => ['table' => 'empresas', 'column' => 'fecha_firma_convenio'],
    'segunda_hora_inicio_oferta' => ['table' => 'empresa_oferta_horarios_semanales', 'column' => 'hora_inicio_2'],
    'segunda_hora_fin_oferta' => ['table' => 'empresa_oferta_horarios_semanales', 'column' => 'hora_fin_2'],
    'titulo_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'titulo'],
    'descripcion_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'descripcion'],
    'modalidad_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'modalidad'],
    'ubicacion_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'ubicacion'],
    'requisitos_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'requisitos'],
    'tecnologias_oferta' => ['table' => 'empresa_especialidades_plazas', 'column' => 'tecnologias'],
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
$jornadaDefault = $db->query(
    "SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'jornada'"
)->fetchColumn();
$nullableSecondIntervalColumns = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_oferta_horarios_semanales'
       AND COLUMN_NAME IN ('hora_inicio_2', 'hora_fin_2')
       AND IS_NULLABLE = 'YES'"
)->fetchColumn();
assertTrue(
    in_array($jornadaDefault, ['normal', "'normal'"], true)
        && (int)$nullableSecondIntervalColumns === 2,
    'La migración debe asignar jornada normal a ofertas existentes y mantener nullable el segundo intervalo.'
);
$webhookTables = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('webhook_inbox', 'webhook_outbox')"
)->fetchColumn();
assertTrue((int)$webhookTables === 2, 'Faltan tablas de entrada/salida webhook; ejecuta la migración 007.');
$webhookResponseColumns = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND ((TABLE_NAME = 'webhook_inbox' AND COLUMN_NAME IN ('response_status', 'response_body'))
         OR (TABLE_NAME = 'webhook_outbox' AND COLUMN_NAME = 'response_body'))"
)->fetchColumn();
assertTrue((int)$webhookResponseColumns === 3, 'Faltan columnas para conservar las respuestas webhook; ejecuta la migración 009.');

$fixture = bin2hex(random_bytes(5));
$testYear = (int)(new DateTimeImmutable())->format('Y') + 1;
$availabilityStart = sprintf('%04d-03-01', $testYear);
$availabilityEditStart = sprintf('%04d-04-01', $testYear);
$availabilityEnd = sprintf('%04d-06-30', $testYear);
$agreementSigned = sprintf('%04d-01-01', $testYear - 1);
$agreementStart = sprintf('%04d-01-01', $testYear);
$agreementEnd = sprintf('%04d-12-31', $testYear);
$offerDateObject = new DateTimeImmutable($availabilityStart);
while ($offerDateObject->format('N') !== '1') {
    $offerDateObject = $offerDateObject->modify('+1 day');
}
$offerDate = $offerDateObject->modify('+14 days')->format('Y-m-d');
$minorBirthDate = (new DateTimeImmutable('today'))->modify('-17 years')->format('Y-m-d');
$underageBirthDate = (new DateTimeImmutable('today'))->modify('-15 years')->format('Y-m-d');
$dniLetters = 'TRWAGMYFPDXBNJZSQVHLCKE';
$firstDniNumber = 10000000 + hexdec(substr($fixture, 0, 6));
$firstStudentDni = sprintf('%08d%s', $firstDniNumber, $dniLetters[$firstDniNumber % 23]);
$secondDniNumber = $firstDniNumber + 1;
$secondStudentDni = sprintf('%08d%s', $secondDniNumber, $dniLetters[$secondDniNumber % 23]);
$emails = [
    "company-$fixture@example.test",
    "student-a-$fixture@example.test",
    "student-b-$fixture@example.test",
    "coordinator-$fixture@example.test",
    "admin-$fixture@example.test",
    "deletable-$fixture@example.test",
    "audited-$fixture@example.test",
];
$dnis = ["A$fixture", "B$fixture", "C$fixture"];
$cifs = ["T$fixture"];
$createdUsers = [];
$createdCompany = null;
$createdStudents = [];
$createdOffer = null;
$createdWebhookOffer = null;
$createdExpiredOffer = null;
$createdPaginationOffers = [];
$createdAssignmentId = null;
$createdCycleId = null;
$webhookEventId = null;
$availabilityWebhookEventId = null;

try {
    $webhookEventId = 'test-' . bin2hex(random_bytes(12));
    $webhookBody = json_encode([
        'event_id' => $webhookEventId,
        'event' => 'prueba.integracion',
        'version' => 1,
        'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
        'data' => (object)['registro_id' => 123],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $webhookTimestamp = (string)time();
    $webhookSecret = bin2hex(random_bytes(32));
    $webhookSignature = 'sha256=' . hash_hmac(
        'sha256',
        $webhookTimestamp . '.' . $webhookBody,
        $webhookSecret
    );
    assertTrue(
        Webhook::receive(
            $db,
            $webhookBody,
            $webhookEventId,
            $webhookTimestamp,
            $webhookSignature,
            $webhookSecret
        ),
        'Un webhook firmado nuevo debe registrarse.'
    );
    Webhook::recordInboundResponse(
        $db,
        $webhookEventId,
        202,
        json_encode(['received' => true, 'duplicate' => false, 'event_id' => $webhookEventId], JSON_THROW_ON_ERROR)
    );
    $storedResponse = $db->prepare(
        'SELECT response_status, response_body FROM webhook_inbox WHERE event_id = :event_id'
    );
    $storedResponse->execute(['event_id' => $webhookEventId]);
    $storedResponseRow = $storedResponse->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($storedResponseRow)
            && (int)$storedResponseRow['response_status'] === 202
            && is_string($storedResponseRow['response_body'])
            && json_decode($storedResponseRow['response_body'], true, 16, JSON_THROW_ON_ERROR)['duplicate'] === false,
        'La respuesta HTTP inicial del webhook entrante debe conservarse.'
    );
    assertTrue(
        !Webhook::receive(
            $db,
            $webhookBody,
            $webhookEventId,
            $webhookTimestamp,
            $webhookSignature,
            $webhookSecret
        ),
        'Un webhook ya recibido debe detectarse como duplicado.'
    );
    Webhook::recordInboundResponse(
        $db,
        $webhookEventId,
        200,
        json_encode(['received' => true, 'duplicate' => true, 'event_id' => $webhookEventId], JSON_THROW_ON_ERROR)
    );
    $storedResponse->execute(['event_id' => $webhookEventId]);
    $storedDuplicateResponse = $storedResponse->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($storedDuplicateResponse)
            && (int)$storedDuplicateResponse['response_status'] === 200
            && is_string($storedDuplicateResponse['response_body'])
            && json_decode($storedDuplicateResponse['response_body'], true, 16, JSON_THROW_ON_ERROR)['duplicate'] === true,
        'La respuesta de un webhook duplicado debe conservarse como respuesta más reciente.'
    );
    $webhookSignatureRejected = false;
    try {
        Webhook::receive(
            $db,
            $webhookBody,
            'another-' . $webhookEventId,
            $webhookTimestamp,
            'sha256=' . str_repeat('0', 64),
            $webhookSecret
        );
    } catch (WebhookRequestException $exception) {
        $webhookSignatureRejected = $exception->httpStatus === 401;
    }
    assertTrue($webhookSignatureRejected, 'Un webhook con firma HMAC incorrecta debe rechazarse.');
    $conflictingPayload = json_decode($webhookBody, false, 16, JSON_THROW_ON_ERROR);
    $conflictingPayload->data->registro_id = 124;
    $conflictingBody = json_encode(
        $conflictingPayload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    $conflictingSignature = 'sha256=' . hash_hmac(
        'sha256',
        $webhookTimestamp . '.' . $conflictingBody,
        $webhookSecret
    );
    $webhookConflictRejected = false;
    try {
        Webhook::receive(
            $db,
            $conflictingBody,
            $webhookEventId,
            $webhookTimestamp,
            $conflictingSignature,
            $webhookSecret
        );
    } catch (WebhookRequestException $exception) {
        $webhookConflictRejected = $exception->httpStatus === 409;
    }
    assertTrue($webhookConflictRejected, 'Un event_id reutilizado con distinto contenido debe rechazarse.');

    putenv('FCT_WEBHOOK_OUTBOUND_URL=https://receiver.example.test/webhooks');
    $db->beginTransaction();
    try {
        Webhook::enqueue($db, 'prueba.transaccional', ['registro_id' => 123]);
        $queued = $db->query(
            "SELECT event_type, payload FROM webhook_outbox
             WHERE event_type = 'prueba.transaccional'
             ORDER BY created_at DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        assertTrue(
            $queued !== false
                && $queued['event_type'] === 'prueba.transaccional'
                && json_decode($queued['payload'], true, 16, JSON_THROW_ON_ERROR)['data']['registro_id'] === 123,
            'El evento saliente debe guardarse como JSON en la transacción local.'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        putenv('FCT_WEBHOOK_OUTBOUND_URL');
    }

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
    $addUser->execute(['email' => $emails[4], 'hash' => $roleHash, 'role' => 'Administrador']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[5], 'hash' => $roleHash, 'role' => 'Observador']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[6], 'hash' => $roleHash, 'role' => 'Observador']);
    $createdUsers[] = (int)$db->lastInsertId();

    $recoveryIndex = $db->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = 'recuperaciones_cuenta'
           AND INDEX_NAME = 'uq_recuperacion_token_hash' AND NON_UNIQUE = 0"
    );
    $recoveryIndex->execute(['schema' => $testDatabase]);
    assertTrue((int)$recoveryIndex->fetchColumn() === 1, 'Falta el índice único para tokens de recuperación.');

    $recoveryNow = new DateTimeImmutable('2026-10-16 12:00:00');
    $oldRecovery = $db->prepare(
        "INSERT INTO recuperaciones_cuenta (usuario_id, estado, creada)
         VALUES (:usuario, 'Solicitada', :creada)"
    );
    $oldRecovery->execute([
        'usuario' => $createdUsers[1],
        'creada' => $recoveryNow->modify('-' . (RecuperacionCuenta::RETENCION_DIAS + 1) . ' days')->format('Y-m-d H:i:s'),
    ]);
    $recoveryModel = new RecuperacionCuenta($db);
    $recoveryModel->solicitar($emails[1], $recoveryNow);
    $recoveryModel->solicitar($emails[0], $recoveryNow);
    $recoveryModel->solicitar($emails[0], $recoveryNow);
    $recoveryCount = $db->prepare(
        "SELECT COUNT(*) FROM recuperaciones_cuenta
         WHERE usuario_id = :usuario AND estado = 'Solicitada'"
    );
    $recoveryCount->execute(['usuario' => $createdUsers[1]]);
    assertTrue(
        (int)$recoveryCount->fetchColumn() === 1,
        'La limpieza debe eliminar solicitudes antiguas sin bloquear una nueva solicitud.'
    );
    $recoveryCount->execute(['usuario' => $createdUsers[0]]);
    assertTrue(
        (int)$recoveryCount->fetchColumn() === 1,
        'Las solicitudes repetidas de recuperación deben conservar una sola petición pendiente.'
    );
    $recoveryId = (int)$db->query(
        "SELECT id FROM recuperaciones_cuenta
         WHERE usuario_id = " . $createdUsers[0] . " AND estado = 'Solicitada'"
    )->fetchColumn();
    $recoveryToken = $recoveryModel->emitirEnlace($recoveryId, $recoveryNow);
    assertTrue(
        is_string($recoveryToken) && strlen($recoveryToken) === 64
            && $recoveryModel->vigente($recoveryToken, $recoveryNow),
        'El enlace emitido debe ser aleatorio, de un solo uso y vigente.'
    );
    $legacyToken = bin2hex(random_bytes(32));
    $legacyRecovery = $db->prepare(
        "INSERT INTO recuperaciones_cuenta
            (usuario_id, estado, token_hash, expira, creada)
         VALUES (:usuario, 'EnlaceEmitido', :hash, :expira, :creada)"
    );
    $legacyRecovery->execute([
        'usuario' => $createdUsers[0],
        'hash' => hash('sha256', $legacyToken),
        'expira' => $recoveryNow->modify('+30 minutes')->format('Y-m-d H:i:s'),
        'creada' => $recoveryNow->format('Y-m-d H:i:s'),
    ]);
    assertTrue(
        $recoveryModel->consumir($recoveryToken, 'ResetPassword123!', $recoveryNow) === $emails[0]
            && !$recoveryModel->vigente($legacyToken, $recoveryNow)
            && password_verify(
                'ResetPassword123!',
                (string)$db->query(
                    'SELECT password_hash FROM usuarios WHERE id = ' . $createdUsers[0]
                )->fetchColumn()
            ),
        'Restablecer una cuenta debe cambiar la contraseña e invalidar todos sus enlaces anteriores.'
    );

    $coordinatorProfile = $db->prepare(
        'INSERT INTO coordinadores (usuario_id, nombre, apellidos) VALUES (:usuario, :nombre, :apellidos)'
    );
    $coordinatorProfile->execute([
        'usuario' => $createdUsers[3],
        'nombre' => 'Coordinadora',
        'apellidos' => "Álvarez O'Connor",
    ]);
    $coordinator = array_values(array_filter(
        (new Usuario($db))->obtenerTodos(),
        static fn(array $user): bool => (int)$user['id'] === $createdUsers[3]
    ))[0] ?? null;
    assertTrue(
        $coordinator !== null && $coordinator['nombre_completo'] === "Coordinadora Álvarez O'Connor",
        'La lista de usuarios debe recuperar y unir el nombre completo del coordinador.'
    );

    $_SESSION = ['usuario_id' => $createdUsers[3], 'rol' => 'Observador'];
    $_GET = [];
    http_response_code(200);
    $observerDenied = false;
    try {
        (new ConvenioController($db))->descargar();
    } catch (RuntimeException) {
        $observerDenied = http_response_code() === 403;
    }
    assertTrue($observerDenied, 'Un observador no debe descargar convenios aunque invoque el controlador directamente.');
    $deniedDownload = $db->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :usuario AND evento = 'convenio.descarga.denegada'
           AND detalle = 'motivo=rol_no_autorizado'"
    );
    $deniedDownload->execute(['usuario' => $createdUsers[3]]);
    assertTrue((int)$deniedDownload->fetchColumn() === 1, 'La denegación directa a un observador debe quedar auditada.');
    $_SESSION = [];
    http_response_code(200);

    $cycleId = (int)$db->query('SELECT id FROM especialidades ORDER BY id LIMIT 1')->fetchColumn();
    if ($cycleId === 0) {
        $cycle = $db->prepare('INSERT INTO especialidades (nombre_especialidad) VALUES (:nombre)');
        $cycle->execute(['nombre' => "Ciclo de prueba $fixture"]);
        $cycleId = (int)$db->lastInsertId();
        $createdCycleId = $cycleId;
    }

    $companyInsert = $db->prepare(
        "INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto, estado)
         VALUES (:usuario, :nombre, :cif, :contacto, 'Aprobada')"
    );
    $companyInsert->execute([
        'usuario' => $createdUsers[0],
        'nombre' => "Test Company $fixture",
        'cif' => $cifs[0],
        'contacto' => 'Tutor de prueba',
    ]);
    $createdCompany = (int)$db->lastInsertId();
    $listedCompanies = (new Empresa($db))->obtenerTodasParaCentro();
    $listedTestCompany = array_values(array_filter(
        $listedCompanies,
        static fn(array $company): bool => (int)$company['id'] === $createdCompany
    ))[0] ?? null;
    assertTrue(
        $listedTestCompany !== null
            && $listedTestCompany['nombre_empresa'] === "Test Company $fixture"
            && $listedTestCompany['cif_empresa'] === $cifs[0]
            && $listedTestCompany['email'] === $emails[0]
            && (int)$listedTestCompany['activo'] === 1,
        'El directorio del centro debe listar los perfiles de empresa con su cuenta y estado.'
    );

    $invalidOfferInsert = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, :plazas, :inicio, :fin, 'M', 'Pendiente')"
    );
    $invalidOfferRejected = [];
    foreach ([
        ['plazas' => 0, 'inicio' => '2027-03-15', 'fin' => '2027-03-15'],
        ['plazas' => 1, 'inicio' => '2027-03-16', 'fin' => '2027-03-15'],
    ] as $invalidOffer) {
        try {
            $invalidOfferInsert->execute([
                'empresa' => $createdCompany,
                'ciclo' => $cycleId,
                'plazas' => $invalidOffer['plazas'],
                'inicio' => $invalidOffer['inicio'],
                'fin' => $invalidOffer['fin'],
            ]);
            $invalidOfferRejected[] = false;
        } catch (PDOException) {
            $invalidOfferRejected[] = true;
        }
    }
    assertTrue(
        $invalidOfferRejected === [true, true],
        'La base debe rechazar ofertas sin plazas o con fechas incoherentes.'
    );

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
    $studentProfile = (new Alumno($db))->obtenerPorUsuario($createdUsers[1]);
    assertTrue(
        $studentProfile !== null
            && $studentProfile['apellidos'] === "Prueba $fixture 1"
            && $studentProfile['nombre_especialidad'] !== null,
        'El portal debe recuperar el expediente y ciclo del alumno autenticado.'
    );
    $studentModel = new Alumno($db);
    $studentModel->actualizarPerfil(
        $createdUsers[1],
        $emails[1],
        'Ángela',
        "O'Connor",
        '2005-01-01',
        $firstStudentDni,
        '12345-67890',
        '612345678',
        'Calle O’Donnell, 1',
        '28013'
    );
    $updatedProfile = $studentModel->obtenerPorUsuario($createdUsers[1]);
    assertTrue(
        $updatedProfile !== null
            && $updatedProfile['nombre'] === 'Ángela'
            && $updatedProfile['apellidos'] === "O'Connor"
            && $updatedProfile['dni'] === $firstStudentDni
            && $updatedProfile['codigo_postal'] === '28013'
            && $updatedProfile['telefono'] === '612345678',
        'La edición del perfil debe guardar los datos personales, postales y de contacto.'
    );
    $duplicateEmailRejected = false;
    try {
        $studentModel->actualizarPerfil(
            $createdUsers[1],
            $emails[2],
            'Cambio parcial',
            "O'Connor",
            '2005-01-01',
            $firstStudentDni,
            '12345-67890',
            '612345678',
            'Calle O’Donnell, 1',
            '28013'
        );
    } catch (PDOException) {
        $duplicateEmailRejected = true;
    }
    assertTrue(
        $duplicateEmailRejected
            && $studentModel->obtenerPorUsuario($createdUsers[1])['nombre'] === 'Ángela',
        'La colisión del correo debe revertir la actualización completa del perfil.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $profileHtml = ob_get_clean();
    assertTrue(
        is_string($profileHtml)
            && str_contains($profileHtml, 'Ángela')
            && str_contains($profileHtml, 'value="28013"')
            && preg_match(
                '/<input\b(?=[^>]*\bid="profile-dni")(?=[^>]*\bmaxlength="20")[^>]*>/',
                $profileHtml
            ) === 1
            && str_contains($profileHtml, 'data-vue-island="StudentProfile"'),
        'El formulario de perfil debe cargar los datos que se pueden editar.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'email' => $emails[1],
        'nombre' => 'Ángela',
        'apellidos' => "O'Connor",
        'fecha_nacimiento' => '2005-01-01',
        'dni' => '12345678A',
        'nss' => '12345-67890',
        'telefono' => '+34 612 345 678',
        'direccion' => 'Calle O’Donnell, 1',
        'codigo_postal' => '28013',
    ];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $invalidProfileHtml = ob_get_clean();
    assertTrue(
        is_string($invalidProfileHtml)
            && str_contains($invalidProfileHtml, 'aria-invalid="true"')
            && str_contains($invalidProfileHtml, 'con letra de control'),
        'Los datos inválidos deben marcar el campo y mostrar feedback específico sin guardar.'
    );
    assertTrue(
        $studentModel->obtenerPorUsuario($createdUsers[1])['dni'] === $firstStudentDni,
        'Un DNI/NIE inválido no debe modificar el expediente.'
    );
    $_POST = [
        'email' => $emails[1],
        'nombre' => 'Ángela',
        'apellidos' => "O'Connor",
        'fecha_nacimiento' => '2005-01-01',
        'dni' => $firstStudentDni,
        'nss' => '12345-67890',
        'telefono' => '------------612345678',
        'direccion' => 'Calle O’Donnell, 1',
        'codigo_postal' => '28013',
    ];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $longStudentPhoneHtml = (string)ob_get_clean();
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="profile-phone")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $longStudentPhoneHtml
        ) === 1
            && str_contains($longStudentPhoneHtml, 'Introduce un teléfono español válido de hasta 20 caracteres.')
            && $studentModel->obtenerPorUsuario($createdUsers[1])['telefono'] === '612345678',
        'El perfil debe rechazar teléfonos normalizados válidos que superen su maxlength y no guardarlos.'
    );
    $_POST['telefono'] = '612345678';
    $_POST['dni'] = implode('--', str_split($firstStudentDni));
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $longStudentDniHtml = (string)ob_get_clean();
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="profile-dni")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $longStudentDniHtml
        ) === 1
            && str_contains($longStudentDniHtml, 'DNI/NIE válido de hasta 20 caracteres')
            && $studentModel->obtenerPorUsuario($createdUsers[1])['dni'] === $firstStudentDni,
        'El perfil debe rechazar DNI/NIE largos aunque la normalización produzca un documento válido.'
    );
    $_POST = [
        'email' => $emails[1],
        'nombre' => 'Ángela',
        'apellidos' => "O'Connor",
        'fecha_nacimiento' => '2005-01-01',
        'dni' => $firstStudentDni,
        'nss' => "NSS-$fixture-2",
        'telefono' => '612345678',
        'direccion' => 'Calle O’Donnell, 1',
        'codigo_postal' => '28013',
    ];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $duplicateNssHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($duplicateNssHtml, 'correo, DNI/NIE o número de Seguridad Social')
            && $studentModel->obtenerPorUsuario($createdUsers[1])['numero_seguridad_social'] === '12345-67890',
        'Una colisión de NSS debe explicar el motivo y dejar intacto el perfil.'
    );
    $db->prepare('UPDATE alumnos SET fecha_nacimiento = :nacimiento WHERE id = :id')
        ->execute(['nacimiento' => $minorBirthDate, 'id' => $createdStudents[1]]);
    $legacyGuardian = $db->prepare(
        'INSERT INTO tutores_legales
            (alumno_id, nombre_completo, direccion, telefono, dni, numero_seguridad_social, correo)
         VALUES (:alumno, :nombre, :direccion, :telefono, NULL, NULL, NULL)'
    );
    $legacyGuardian->execute([
        'alumno' => $createdStudents[1],
        'nombre' => 'Tutor legado',
        'direccion' => 'Calle Antigua 1',
        'telefono' => '600123456',
    ]);
    $_SESSION = ['usuario_id' => $createdUsers[2], 'rol' => 'Alumno'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $legacyProfileHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($legacyProfileHtml, 'name="tutor_dni"')
            && str_contains($legacyProfileHtml, 'value="Tutor legado"')
            && str_contains($legacyProfileHtml, 'name="tutor_correo"'),
        'El formulario debe permitir completar los datos de un tutor legado incompleto.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'email' => $emails[2],
        'nombre' => 'Alumno',
        'apellidos' => 'Apellidos Legado',
        'fecha_nacimiento' => $minorBirthDate,
        'dni' => $secondStudentDni,
        'nss' => "NSS-$fixture-2",
        'telefono' => '612345679',
        'direccion' => 'Calle Nueva 2',
        'codigo_postal' => '28013',
    ];
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $incompleteGuardianHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($incompleteGuardianHtml, 'Completa los datos del tutor legal'),
        'Un tutor incompleto debe producir un error corregible desde el propio formulario. '
            . trim(preg_replace('/\s+/', ' ', strip_tags($incompleteGuardianHtml)) ?? '')
    );
    assertTrue(
        str_contains($incompleteGuardianHtml, 'name="tutor_dni"'),
        'El formulario debe conservar acceso a la corrección de los datos del tutor legal.'
    );
    $_POST['tutor_nombre'] = 'Tutor legado';
    $_POST['tutor_dni'] = '12345678A';
    $_POST['tutor_nss'] = '12345-67890';
    $_POST['tutor_direccion'] = 'Calle Tutor 2';
    $_POST['tutor_telefono'] = '612345670';
    $_POST['tutor_correo'] = 'tutor@example.test';
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $invalidGuardianHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($invalidGuardianHtml, 'id="profile-guardian-dni"')
            && str_contains($invalidGuardianHtml, 'aria-invalid="true"')
            && str_contains($invalidGuardianHtml, 'DNI/NIE válido de hasta 20 caracteres')
            && str_contains($invalidGuardianHtml, 'value="12345678A"'),
        'Un DNI/NIE de tutor inválido debe señalar el campo y conservar el valor para corregirlo.'
    );
    $_POST['tutor_dni'] = implode('--', str_split('12345678Z'));
    $_POST['tutor_telefono'] = '612345670';
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $longGuardianDniHtml = (string)ob_get_clean();
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="profile-guardian-dni")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $longGuardianDniHtml
        ) === 1
            && str_contains($longGuardianDniHtml, 'DNI/NIE válido de hasta 20 caracteres'),
        'El perfil del menor debe limitar la longitud original del DNI/NIE de tutor.'
    );
    $_POST['tutor_dni'] = '12345678Z';
    $_POST['tutor_telefono'] = '------------612345670';
    ob_start();
    (new AlumnoController($db))->editarPerfil();
    $longGuardianPhoneHtml = (string)ob_get_clean();
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="profile-guardian-phone")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $longGuardianPhoneHtml
        ) === 1
            && str_contains($longGuardianPhoneHtml, 'Introduce un teléfono español válido de hasta 20 caracteres.'),
        'El perfil del menor debe rechazar los teléfonos largos de tutor tras normalización.'
    );
    $studentModel->actualizarPerfil(
        $createdUsers[2],
        $emails[2],
        'Alumno',
        'Apellidos Legado',
        $minorBirthDate,
        $secondStudentDni,
        "NSS-$fixture-2",
        '612345679',
        'Calle Nueva 2',
        '28013',
        [
            'nombre' => 'Tutor legado',
            'dni' => '12345678Z',
            'nss' => '12345-67890',
            'direccion' => 'Calle Tutor 2',
            'telefono' => '612345670',
            'correo' => 'tutor@example.test',
        ]
    );
    $guardianCheck = $db->prepare(
        'SELECT nombre_completo, dni, numero_seguridad_social, direccion, telefono, correo
         FROM tutores_legales WHERE alumno_id = :alumno_id'
    );
    $guardianCheck->execute(['alumno_id' => $createdStudents[1]]);
    $updatedGuardian = $guardianCheck->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        $updatedGuardian !== false
            && $updatedGuardian['dni'] === '12345678Z'
            && $updatedGuardian['numero_seguridad_social'] === '12345-67890'
            && $updatedGuardian['correo'] === 'tutor@example.test',
        'Guardar el perfil del menor debe completar el tutor legado dentro de la misma operación.'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SESSION = [];
    $_POST = [];
    ob_start();
    (new AlumnoController($db))->registrar();
    $registrationHtml = (string)ob_get_clean();
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="student-dni")(?=[^>]*\bmaxlength="20")[^>]*>/',
            $registrationHtml
        ) === 1
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-guardian-dni")(?=[^>]*\bmaxlength="20")[^>]*>/',
                $registrationHtml
            ) === 1,
        'El registro debe permitir los separadores admitidos en DNI/NIE del alumno y del tutor.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = [
        'email' => "new-student-$fixture@example.test",
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Nuevo Alumno',
        'apellidos' => 'Prueba de Registro',
        'dni' => sprintf(
            '%08d%s',
            $secondDniNumber + 1,
            $dniLetters[($secondDniNumber + 1) % 23]
        ),
        'nss' => "REG-$fixture-1",
        'telefono' => '612345671',
        'direccion' => 'Calle de prueba 1',
        'codigo_postal' => '28013',
        'fecha_nacimiento' => $minorBirthDate,
        'especialidad_id' => (string)$cycleId,
        'tutor_nombre' => 'Tutor de Prueba',
        'tutor_dni' => '12345678A',
        'tutor_nss' => 'NSS!',
        'tutor_correo' => 'tutor-registro@example.test',
        'tutor_direccion' => 'Calle Tutor 1',
        'tutor_telefono' => '612345672',
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $invalidGuardianRegistrationHtml = (string)ob_get_clean();
    $invalidGuardianAccountCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $invalidGuardianAccountCount->execute(['email' => $_POST['email']]);
    assertTrue(
        str_contains($invalidGuardianRegistrationHtml, 'Comprueba los datos del tutor indicados.')
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-guardian-nss")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $invalidGuardianRegistrationHtml
            ) === 1
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-guardian-dni")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $invalidGuardianRegistrationHtml
            ) === 1
            && str_contains($invalidGuardianRegistrationHtml, 'Introduce un número de Seguridad Social válido')
            && str_contains($invalidGuardianRegistrationHtml, 'Introduce un DNI/NIE con una letra de control válida.')
            && !str_contains($invalidGuardianRegistrationHtml, 'value="NSS!"')
            && !str_contains($invalidGuardianRegistrationHtml, 'value="12345678A"')
            && !str_contains($invalidGuardianRegistrationHtml, 'value="ValidStudentPassword123"')
            && (int)$invalidGuardianAccountCount->fetchColumn() === 0,
        'Los errores de DNI/NIE y NSS del tutor deben señalar ambos campos e impedir crear la cuenta.'
    );
    $_POST = [
        'email' => "invalid-student-$fixture@example.test",
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Nuevo Alumno',
        'apellidos' => 'Prueba de Validación',
        'dni' => sprintf(
            '%08d%s',
            $secondDniNumber + 3,
            $dniLetters[($secondDniNumber + 3) % 23]
        ),
        'nss' => "REG-$fixture-3",
        'telefono' => '------------612345678',
        'direccion' => 'Calle de prueba 3',
        'codigo_postal' => '99999',
        'fecha_nacimiento' => '2099-01-01',
        'especialidad_id' => (string)$cycleId,
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $invalidStudentRegistrationHtml = (string)ob_get_clean();
    $invalidStudentAccountCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $invalidStudentAccountCount->execute(['email' => $_POST['email']]);
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="student-phone")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $invalidStudentRegistrationHtml
        ) === 1
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-postcode")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $invalidStudentRegistrationHtml
            ) === 1
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-birth-date")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $invalidStudentRegistrationHtml
            ) === 1
            && str_contains($invalidStudentRegistrationHtml, 'Introduce un teléfono español válido de hasta 20 caracteres.')
            && str_contains($invalidStudentRegistrationHtml, 'código postal español válido')
            && str_contains($invalidStudentRegistrationHtml, 'La fecha de nacimiento no puede ser futura.')
            && (int)$invalidStudentAccountCount->fetchColumn() === 0,
        'El registro debe señalar todos los campos inválidos y no crear la cuenta.'
    );
    $malformedEmail = "malformed-email-$fixture@example.test";
    $_POST = [
        'email' => ['malformed'],
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Nuevo Alumno',
        'apellidos' => 'Entrada no válida',
        'dni' => sprintf(
            '%08d%s',
            $secondDniNumber + 4,
            $dniLetters[($secondDniNumber + 4) % 23]
        ),
        'nss' => "REG-$fixture-5",
        'telefono' => '612345675',
        'direccion' => 'Calle de prueba 5',
        'codigo_postal' => '28013',
        'fecha_nacimiento' => '2000-01-01',
        'especialidad_id' => (string)$cycleId,
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $malformedEmailHtml = (string)ob_get_clean();
    $malformedEmailCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $malformedEmailCount->execute(['email' => $malformedEmail]);
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="student-email")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $malformedEmailHtml
        ) === 1
            && str_contains($malformedEmailHtml, 'Este campo debe contener un único valor de texto.')
            && (int)$malformedEmailCount->fetchColumn() === 0,
        'Un campo enviado con tipo incorrecto debe señalarse y detener el registro.'
    );
    $underageEmail = "underage-student-$fixture@example.test";
    $_POST = [
        'email' => $underageEmail,
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Alumno Menor',
        'apellidos' => 'Edad Insuficiente',
        'dni' => sprintf(
            '%08d%s',
            $secondDniNumber + 5,
            $dniLetters[($secondDniNumber + 5) % 23]
        ),
        'nss' => "REG-$fixture-6",
        'telefono' => '612345676',
        'direccion' => 'Calle de prueba 6',
        'codigo_postal' => '28013',
        'fecha_nacimiento' => $underageBirthDate,
        'especialidad_id' => (string)$cycleId,
        'tutor_nss' => 'NSS!',
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $underageRegistrationHtml = (string)ob_get_clean();
    $underageAccountCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $underageAccountCount->execute(['email' => $underageEmail]);
    assertTrue(
        str_contains($underageRegistrationHtml, 'El alumno debe tener al menos 16 años.')
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-birth-date")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $underageRegistrationHtml
            ) === 1
            && (int)$underageAccountCount->fetchColumn() === 0,
        'La edad mínima debe mostrarse junto a la fecha antes de validar los datos del tutor.'
    );
    $_POST = [
        'email' => $emails[1],
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Nuevo Alumno',
        'apellidos' => 'Prueba de Registro',
        'dni' => sprintf(
            '%08d%s',
            $secondDniNumber + 2,
            $dniLetters[($secondDniNumber + 2) % 23]
        ),
        'nss' => "REG-$fixture-2",
        'telefono' => '612345673',
        'direccion' => 'Calle de prueba 2',
        'codigo_postal' => '28013',
        'fecha_nacimiento' => '2000-01-01',
        'especialidad_id' => (string)$cycleId,
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $duplicateRegistrationHtml = (string)ob_get_clean();
    $duplicateRegistrationCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $duplicateRegistrationCount->execute(['email' => $emails[1]]);
    assertTrue(
        str_contains($duplicateRegistrationHtml, 'El correo indicado ya está asociado')
            && preg_match(
                '/<input\b(?=[^>]*\bid="student-email")(?=[^>]*\baria-invalid="true")[^>]*>/',
                $duplicateRegistrationHtml
            ) === 1
            && str_contains($duplicateRegistrationHtml, 'Este correo electrónico ya está registrado.')
            && (int)$duplicateRegistrationCount->fetchColumn() === 1,
        'Un correo duplicado debe señalar el campo, informar del conflicto y evitar crear otra cuenta.'
    );
    $_POST = [
        'email' => "duplicate-dni-$fixture@example.test",
        'password' => 'ValidStudentPassword123',
        'nombre' => 'Nuevo Alumno',
        'apellidos' => 'DNI Duplicado',
        'dni' => $firstStudentDni,
        'nss' => "REG-$fixture-4",
        'telefono' => '612345674',
        'direccion' => 'Calle de prueba 4',
        'codigo_postal' => '28013',
        'fecha_nacimiento' => '2000-01-01',
        'especialidad_id' => (string)$cycleId,
    ];
    ob_start();
    (new AlumnoController($db))->registrar();
    $duplicateDniRegistrationHtml = (string)ob_get_clean();
    $duplicateDniAccountCount = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE email = :email');
    $duplicateDniAccountCount->execute(['email' => $_POST['email']]);
    assertTrue(
        preg_match(
            '/<input\b(?=[^>]*\bid="student-dni")(?=[^>]*\baria-invalid="true")[^>]*>/',
            $duplicateDniRegistrationHtml
        ) === 1
            && str_contains($duplicateDniRegistrationHtml, 'Este DNI/NIE ya está asociado a otro expediente.')
            && (int)$duplicateDniAccountCount->fetchColumn() === 0,
        'Un DNI/NIE duplicado debe señalar el campo y revertir el alta completa.'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $_GET = [];
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    ob_start();
    (new AlumnoController($db))->portal();
    $portalHtml = ob_get_clean();
    assertTrue(
        is_string($portalHtml)
            && str_contains($portalHtml, 'Ángela')
            && str_contains($portalHtml, 'Disponibilidad')
            && str_contains($portalHtml, 'Mis prácticas'),
        'El portal debe mostrar las secciones alimentadas por el expediente del alumno.'
    );

    $availability = $db->prepare(
        "INSERT INTO alumno_disponibilidad
            (alumno_id, fecha_inicio, fecha_fin, turno, es_indiferente)
         VALUES (:alumno, :inicio, :fin, 'M', FALSE)"
    );
    $weeklySchedule = $db->prepare(
        "INSERT INTO alumno_disponibilidad_semanal
            (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
         VALUES (:periodo, :dia_semana, :hora_inicio, :hora_fin)"
    );
    foreach ($createdStudents as $index => $studentId) {
        $availability->execute([
            'alumno' => $studentId,
            'inicio' => $availabilityStart,
            'fin' => $availabilityEnd,
        ]);
        $weeklySchedule->execute([
            'periodo' => (int)$db->lastInsertId(),
            'dia_semana' => 1,
            'hora_inicio' => $index === 0 ? '08:00:00' : '10:00:00',
            'hora_fin' => $index === 0 ? '14:00:00' : '17:00:00',
        ]);
    }
    $availabilityModel = new Disponibilidad($db);
    assertTrue(
        count($availabilityModel->obtenerPorUsuario($createdUsers[1])) === 1,
        'El portal debe cargar la disponibilidad real del alumno autenticado.'
    );
    assertTrue(
        count($availabilityModel->obtenerHorarioSemanalPorUsuario($createdUsers[1])) === 1,
        'El portal debe cargar las franjas semanales persistidas.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $availabilityHtml = ob_get_clean();
    $availabilityChecks = [
        'form' => str_contains($availabilityHtml, 'Disponibilidad semanal'),
        'schedule island' => str_contains($availabilityHtml, 'data-vue-island="AvailabilitySchedule"'),
        'schedule state' => str_contains($availabilityHtml, '&quot;active&quot;:true'),
        'schedule times' => str_contains($availabilityHtml, '&quot;start&quot;:&quot;08:00&quot;')
            && str_contains($availabilityHtml, '08:00'),
        'saved island' => str_contains($availabilityHtml, 'data-vue-island="SavedAvailability"'),
        'saved state' => str_contains($availabilityHtml, 'data-prop-state="'),
        'saved heading' => str_contains($availabilityHtml, 'Horario semanal guardado'),
        'five-minute step' => str_contains($availabilityHtml, 'step="300"'),
        'toggle' => str_contains($availabilityHtml, 'availability-toggle-card__switch'),
        'form inputs' => str_contains($availabilityHtml, 'name="es_indiferente"')
            && str_contains($availabilityHtml, 'name="horarios[1][activo]"'),
    ];
    assertTrue(
        !in_array(false, $availabilityChecks, true),
        'El formulario de disponibilidad debe mostrar los días y franjas guardados. Fallos: '
            . implode(', ', array_keys(array_filter($availabilityChecks, static fn(bool $passed): bool => !$passed)))
    );
    foreach (['09:05', '09:15', '14:45', '17:55'] as $fiveMinuteTime) {
        assertTrue(
            preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $fiveMinuteTime) === 1,
            'La validación horaria debe aceptar incrementos de cinco minutos, como ' . $fiveMinuteTime . '.'
        );
    }
    assertTrue(
        preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', '09:16') !== 1,
        'La validación horaria debe rechazar minutos que no sean múltiplos de cinco.'
    );
    $deleteAvailability = $db->prepare('DELETE FROM alumno_disponibilidad WHERE alumno_id = :alumno_id');
    $deleteAvailability->execute(['alumno_id' => $createdStudents[1]]);
    $_SESSION = ['usuario_id' => $createdUsers[2], 'rol' => 'Alumno'];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $newStudentAvailabilityHtml = ob_get_clean();
    assertTrue(
        is_string($newStudentAvailabilityHtml)
            && str_contains($newStudentAvailabilityHtml, '&quot;esIndiferente&quot;:false')
            && !str_contains($newStudentAvailabilityHtml, 'name="horarios[1][activo]" value="1" aria-label="Lunes" disabled'),
        'Un alumno sin disponibilidad debe recibir horarios editables y no indiferentes por defecto.'
    );
    $availability->execute([
        'alumno' => $createdStudents[1],
        'inicio' => $availabilityStart,
        'fin' => $availabilityEnd,
    ]);
    $weeklySchedule->execute([
        'periodo' => (int)$db->lastInsertId(),
        'dia_semana' => 1,
        'hora_inicio' => '10:00:00',
        'hora_fin' => '17:00:00',
    ]);
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    unset($_SERVER['CONTENT_TYPE']);
    $_POST = [
        'fecha_inicio' => '2027-04-01',
        'fecha_fin' => '2027-06-30',
        'turno' => 'M',
        'es_indiferente' => false,
        'horarios' => [
            '1' => ['activo' => true, 'hora_inicio' => '09:15:00', 'hora_fin' => '17:00'],
        ],
    ];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $savedAvailabilityJson = ob_get_clean();
    $jsonStart = is_string($savedAvailabilityJson) ? strpos($savedAvailabilityJson, '{') : false;
    $savedAvailabilityResponse = $jsonStart === false
        ? null
        : json_decode(substr($savedAvailabilityJson, $jsonStart), true);
    assertTrue(
        is_array($savedAvailabilityResponse)
            && ($savedAvailabilityResponse['success'] ?? false) === true
            && ($savedAvailabilityResponse['data']['savedSchedule']['fecha_inicio'] ?? null) === '2027-04-01'
            && ($savedAvailabilityResponse['data']['savedSchedule']['fecha_fin'] ?? null) === '2027-06-30'
            && ($savedAvailabilityResponse['data']['savedSchedule']['es_indiferente'] ?? null) === false
            && ($savedAvailabilityResponse['data']['savedSchedule']['dias'][0]['dia'] ?? null) === 'Lunes'
            && ($savedAvailabilityResponse['data']['savedSchedule']['dias'][0]['hora_inicio'] ?? null) === '09:15:00',
        'El guardado JSON debe devolver el horario persistido con los datos que consume la isla de lectura.'
    );
    putenv('FCT_WEBHOOK_OUTBOUND_URL=https://receiver.example.test/webhooks');
    try {
        $availabilityModel->reemplazar(
            $createdUsers[1],
            '2027-03-01',
            '2027-06-30',
            'M',
            false,
            [['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '14:00:00']]
        );
    } finally {
        putenv('FCT_WEBHOOK_OUTBOUND_URL');
    }
    $availabilityEvent = $db->query(
        "SELECT event_id, payload FROM webhook_outbox
         WHERE event_type = 'student.availability_updated'
         ORDER BY created_at DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $availabilityPayload = is_array($availabilityEvent)
        ? json_decode($availabilityEvent['payload'], true, 16, JSON_THROW_ON_ERROR)
        : null;
    assertTrue(
        is_array($availabilityEvent)
            && is_array($availabilityPayload)
            && ($availabilityPayload['data']['student_id'] ?? null) === $createdStudents[0]
            && ($availabilityPayload['data']['horarios'][0]['hora_inicio'] ?? null) === '08:00:00',
        'El cambio de disponibilidad debe dejar su evento firmado pendiente en la outbox transaccional.'
    );
    $availabilityWebhookEventId = $availabilityEvent['event_id'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_ACCEPT']);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_POST = [
        'fecha_inicio' => $availabilityEditStart,
        'fecha_fin' => $availabilityEnd,
        'turno' => 'Indiferente',
        'es_indiferente' => '0',
        'horarios' => [
            '1' => ['activo' => '1', 'hora_inicio' => '08:16', 'hora_fin' => '13:50'],
        ],
    ];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $invalidTimeHtml = ob_get_clean();
    assertTrue(
        is_string($invalidTimeHtml) && str_contains($invalidTimeHtml, 'franja horaria válida'),
        'El servidor debe rechazar horas que no respeten intervalos de cinco minutos.'
    );
    assertTrue(
        count($availabilityModel->obtenerPorUsuario($createdUsers[1])) === 1,
        'Una franja horaria inválida no debe sobrescribir la disponibilidad persistida.'
    );
    $futureStart = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
    $futureEnd = (new DateTimeImmutable('tomorrow + 30 days'))->format('Y-m-d');
    $_POST = [
        'fecha_inicio' => $futureStart,
        'fecha_fin' => $futureEnd,
        'turno' => 'Indiferente',
        'horarios' => [
            '1' => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '12:00'],
            '+1' => ['activo' => '1', 'hora_inicio' => '13:00', 'hora_fin' => '17:00'],
        ],
    ];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $duplicateDayHtml = ob_get_clean();
    assertTrue(
        is_string($duplicateDayHtml) && str_contains($duplicateDayHtml, 'cada día de la semana una sola vez'),
        'El servidor debe rechazar claves distintas que representan el mismo día de la semana.'
    );
    assertTrue(
        count($availabilityModel->obtenerHorarioSemanalPorUsuario($createdUsers[1])) === 1,
        'Un día semanal duplicado no debe sobrescribir la disponibilidad persistida.'
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];

    $agreement = $db->prepare(
        "INSERT INTO convenios (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path, estado)
         VALUES (:empresa, :especialidad, :numero, :firma, :inicio, :fin, 'test-placeholder.pdf', 'Pendiente')"
    );
    $agreement->execute([
        'empresa' => $createdCompany,
        'especialidad' => $cycleId,
        'numero' => "AG-$fixture",
        'firma' => $agreementSigned,
        'inicio' => $agreementStart,
        'fin' => $agreementEnd,
    ]);
    $agreementId = (int)$db->lastInsertId();
    assertTrue(
        (new Convenio($db))->resolver($agreementId, 'Aprobado', '', $createdUsers[3]),
        'Un convenio pendiente debe poder aprobarse.'
    );
    $companyModel = new Empresa($db);
    assertTrue(
        $companyModel->tieneConvenioParaFechas(
            $createdCompany,
            $cycleId,
            $offerDate,
            (new DateTimeImmutable($offerDate))->modify('+3 months')->format('Y-m-d')
        ),
        'Un convenio aprobado del mismo ciclo y periodo debe cubrir la oferta.'
    );
    $duplicateAgreementRejected = false;
    try {
        $duplicate = $db->prepare(
            "INSERT INTO convenios
                (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path)
             VALUES (:empresa, :especialidad, :numero, :firma, :inicio, :fin, 'duplicate-placeholder.pdf')"
        );
        $duplicate->execute([
            'empresa' => $createdCompany,
            'especialidad' => $cycleId,
            'numero' => "AG-DUP-$fixture",
            'firma' => $agreementSigned,
            'inicio' => $agreementStart,
            'fin' => $agreementEnd,
        ]);
    } catch (PDOException $e) {
        $duplicateAgreementRejected = $e->getCode() === '23000';
    }
    assertTrue(
        $duplicateAgreementRejected,
        'La base debe impedir más de un convenio para la misma empresa y ciclo.'
    );
    $otherCycleId = (int)$db->query(
        'SELECT id FROM especialidades WHERE id <> ' . $cycleId . ' ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($otherCycleId > 0) {
        assertTrue(
            !$companyModel->tieneConvenioParaFechas(
                $createdCompany,
                $otherCycleId,
                $offerDate,
                (new DateTimeImmutable($offerDate))->modify('+3 months')->format('Y-m-d')
            ),
            'Un convenio de otro ciclo no debe cubrir la oferta.'
        );
    }

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
         VALUES (:empresa, :ciclo, 1, :inicio, :fin, 'M', 'Pendiente')"
    );
    $offer->execute([
        'empresa' => $createdCompany,
        'ciclo' => $cycleId,
        'inicio' => $offerDate,
        'fin' => $offerDate,
    ]);
    $createdWebhookOffer = (int)$db->lastInsertId();

    $previousWebhookDestination = getenv('FCT_WEBHOOK_OUTBOUND_URL');
    putenv('FCT_WEBHOOK_OUTBOUND_URL=https://receiver.example.test/webhooks');
    $db->beginTransaction();
    try {
        Webhook::enqueue($db, 'oferta.revisada', [
            'oferta_id' => $createdWebhookOffer,
            'estado' => 'Aprobada',
        ]);
        $companyTaggedEvent = $db->query(
            "SELECT payload FROM webhook_outbox
             WHERE event_type = 'oferta.revisada'
               AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.oferta_id')) AS UNSIGNED) = $createdWebhookOffer
             ORDER BY created_at DESC, event_id DESC
             LIMIT 1"
        )->fetchColumn();
        assertTrue(
            is_string($companyTaggedEvent)
                && (int)json_decode($companyTaggedEvent, true, 16, JSON_THROW_ON_ERROR)['data']['empresa_id'] === $createdCompany,
            'Los eventos de oferta revisada deben incluir el ID de su empresa para agruparlos.'
        );
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        putenv($previousWebhookDestination === false
            ? 'FCT_WEBHOOK_OUTBOUND_URL'
            : 'FCT_WEBHOOK_OUTBOUND_URL=' . $previousWebhookDestination);
    }
    $matching = new Oferta($db);
    $createdOffer = $matching->crearOferta(
        $createdCompany,
        $cycleId,
        1,
        '2027-03-15',
        '2027-03-15',
        "Oferta de prueba $fixture",
        'Descripción de prueba de la oferta.',
        'Indiferente',
        'Híbrido',
        'Madrid',
        'Trabajo en equipo.',
        'PHP, Vue',
        [[
            'dia_semana' => 1,
            'hora_inicio' => '09:00:00',
            'hora_fin' => '11:00:00',
            'hora_inicio_2' => '12:00:00',
            'hora_fin_2' => '13:00:00',
        ]],
        'partida'
    );
    $savedOffer = array_values(array_filter(
        $matching->obtenerPorEmpresa($createdCompany),
        static fn(array $offer): bool => (int)$offer['id'] === $createdOffer
    ))[0] ?? null;
    assertTrue(
        $savedOffer !== null
            && $savedOffer['titulo'] === "Oferta de prueba $fixture"
            && $savedOffer['descripcion'] === 'Descripción de prueba de la oferta.'
            && $savedOffer['modalidad'] === 'Híbrido'
            && $savedOffer['ubicacion'] === 'Madrid'
            && $savedOffer['requisitos'] === 'Trabajo en equipo.'
            && $savedOffer['tecnologias'] === 'PHP, Vue'
            && $savedOffer['jornada'] === 'partida',
        'La oferta debe persistir sus detalles completos.'
    );
    $savedSchedule = $matching->obtenerHorarioSemanal($createdOffer);
    assertTrue(
        count($savedSchedule) === 1
            && $savedSchedule[0]['hora_inicio_2'] === '12:00:00'
            && $savedSchedule[0]['hora_fin_2'] === '13:00:00',
        'La jornada partida debe persistir su segunda franja semanal.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[3]];
    $publishOffer = new ReflectionMethod(OfertaController::class, 'procesarPublicacion');
    $publishOffer->setAccessible(true);
    $offerDate = '2027-03-15';
    $splitOfferRequest = [
        'especialidad_id' => (string)$cycleId,
        'plazas' => '1',
        'fecha_inicio' => $offerDate,
        'fecha_fin' => (new DateTimeImmutable($offerDate))->modify('+1 day')->format('Y-m-d'),
        'titulo' => "Oferta partida del formulario $fixture",
        'descripcion' => 'Prueba del guardado del formulario PHP.',
        'jornada' => 'partida',
        'modalidad' => 'Presencial',
        'ubicacion' => 'Madrid',
        'horarios' => [
            1 => [
                'activo' => '1',
                'hora_inicio' => '09:00',
                'hora_fin' => '11:00',
                'hora_inicio_2' => '12:00',
                'hora_fin_2' => '13:00',
            ],
        ],
    ];
    $publishError = $publishOffer->invoke(new OfertaController($db), $createdCompany, $splitOfferRequest);
    $overlappingSplitRequest = $splitOfferRequest;
    $overlappingSplitRequest['horarios'][1]['hora_inicio_2'] = '10:55';
    assertTrue(
        $publishOffer->invoke(new OfertaController($db), $createdCompany, $overlappingSplitRequest)
            === 'Cada día seleccionado necesita dos franjas válidas, no solapadas y en orden horario.',
        'La validación debe rechazar franjas partidas solapadas.'
    );
    $missingSplitTimeRequest = $splitOfferRequest;
    unset($missingSplitTimeRequest['horarios'][1]['hora_fin_2']);
    assertTrue(
        $publishOffer->invoke(new OfertaController($db), $createdCompany, $missingSplitTimeRequest)
            === 'Cada día seleccionado necesita dos franjas válidas, no solapadas y en orden horario.',
        'La validación debe exigir las cuatro horas de cada día activo en jornada partida.'
    );
    $formPublishedOffer = $db->prepare(
        'SELECT id, jornada, turno FROM empresa_especialidades_plazas WHERE titulo = :titulo'
    );
    $formPublishedOffer->execute(['titulo' => "Oferta partida del formulario $fixture"]);
    $formOffer = $formPublishedOffer->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        $publishError === null
            && is_array($formOffer)
            && $formOffer['jornada'] === 'partida'
            && $formOffer['turno'] === 'Indiferente'
            && count((new Oferta($db))->obtenerHorarioSemanal((int)$formOffer['id'])) === 1,
        'El formulario PHP debe aceptar jornada y franjas partidas sin requerir la etiqueta de turno antigua.'
    );
    $normalOfferRequest = $splitOfferRequest;
    $normalOfferRequest['fecha_inicio'] = (new DateTimeImmutable($offerDate))->modify('+2 days')->format('Y-m-d');
    $normalOfferRequest['fecha_fin'] = (new DateTimeImmutable($offerDate))->modify('+3 days')->format('Y-m-d');
    $normalOfferRequest['titulo'] = "Oferta normal del formulario $fixture";
    $normalOfferRequest['jornada'] = 'normal';
    $normalOfferRequest['horarios'][1]['hora_inicio_2'] = ['ignored'];
    $normalOfferRequest['horarios'][1]['hora_fin_2'] = ['ignored'];
    assertTrue(
        $publishOffer->invoke(new OfertaController($db), $createdCompany, $normalOfferRequest) === null,
        'La jornada normal debe ignorar con seguridad los campos de segunda franja.'
    );
    $normalOffer = $db->prepare(
        'SELECT p.jornada, oh.hora_inicio_2, oh.hora_fin_2
         FROM empresa_especialidades_plazas p
         INNER JOIN empresa_oferta_horarios_semanales oh ON oh.oferta_id = p.id
         WHERE p.titulo = :titulo'
    );
    $normalOffer->execute(['titulo' => "Oferta normal del formulario $fixture"]);
    $normalOfferSchedule = $normalOffer->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($normalOfferSchedule)
            && $normalOfferSchedule['jornada'] === 'normal'
            && $normalOfferSchedule['hora_inicio_2'] === null
            && $normalOfferSchedule['hora_fin_2'] === null,
        'La jornada normal debe guardarse sin segunda franja incluso si recibe valores ignorables.'
    );
    assertTrue(
        $matching->resolver($createdOffer, 'Aprobada', '', $createdUsers[3]),
        'Una oferta pendiente debe poder aprobarse.'
    );
    $candidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(
        count($candidates) === 1 && (int)$candidates[0]['alumno_id'] === $createdStudents[0],
        'El matching debe basarse en los intervalos (aunque las etiquetas de turno difieran) y exigir cobertura de ambas franjas.'
    );
    $shortSecondInterval = $db->prepare(
        'UPDATE alumno_disponibilidad_semanal ds
         INNER JOIN alumno_disponibilidad d ON d.id = ds.disponibilidad_id
         SET ds.hora_fin = \'12:30:00\'
         WHERE d.alumno_id = :alumno AND ds.dia_semana = 1'
    );
    $shortSecondInterval->execute(['alumno' => $createdStudents[0]]);
    assertTrue(
        $matching->obtenerCandidatos($createdOffer) === [],
        'El matching debe rechazar al candidato si su horario cubre la primera franja, pero no la segunda.'
    );
    $assignments = new Asignacion($db);
    $splitAssignmentRejected = false;
    try {
        $assignments->crear($createdOffer, $createdStudents[0], $createdUsers[3], 'Tutor de empresa');
    } catch (DomainException $exception) {
        $splitAssignmentRejected = str_contains($exception->getMessage(), 'cobertura horaria');
    }
    assertTrue(
        $splitAssignmentRejected,
        'La validación transaccional de asignación también debe exigir cobertura de la segunda franja.'
    );
    $restoreSecondInterval = $db->prepare(
        'UPDATE alumno_disponibilidad_semanal ds
         INNER JOIN alumno_disponibilidad d ON d.id = ds.disponibilidad_id
         SET ds.hora_fin = \'14:00:00\'
         WHERE d.alumno_id = :alumno AND ds.dia_semana = 1'
    );
    $restoreSecondInterval->execute(['alumno' => $createdStudents[0]]);
    $offersWithCounts = $matching->obtenerVigentesConAsignaciones();
    assertTrue(
        count($offersWithCounts) === 1
            && (int)$offersWithCounts[0]['asignadas'] === 0,
        'La lista de ofertas debe cargar el número de plazas cubiertas en una consulta agrupada.'
    );
    $bulkCandidates = $matching->obtenerCandidatosVigentes();
    assertTrue(
        count($bulkCandidates[$createdOffer] ?? []) === 1
            && (int)$bulkCandidates[$createdOffer][0]['alumno_id'] === $createdStudents[0],
        'La consulta agrupada debe devolver los candidatos indexados por oferta.'
    );
    $studentCandidates = $matching->obtenerCandidatosVigentes($createdUsers[1]);
    assertTrue(
        count($studentCandidates[$createdOffer] ?? []) === 1
            && (int)$studentCandidates[$createdOffer][0]['alumno_id'] === $createdStudents[0],
        'La consulta agrupada debe poder limitar los candidatos compatibles a un alumno.'
    );
    assertTrue(
        $matching->obtenerIdsOfertasVigentesCompatibles($createdUsers[1]) === [$createdOffer],
        'El dashboard debe poder obtener solo los IDs de ofertas compatibles con el alumno.'
    );
    $paginatedCandidates = $matching->obtenerCandidatosPaginados(
        [$createdOffer],
        [$createdOffer => 1],
        1
    );
    assertTrue(
        count($paginatedCandidates[$createdOffer]['candidatos'] ?? []) === 1
            && (int)$paginatedCandidates[$createdOffer]['candidatos'][0]['alumno_id'] === $createdStudents[0]
            && !$paginatedCandidates[$createdOffer]['haySiguiente'],
        'El matching paginado debe conservar el candidato compatible y el estado de navegación.'
    );
    assertTrue(
        ($matching->contarAsignacionesPorOferta([$createdOffer])[$createdOffer] ?? null) === 0,
        'El conteo agrupado debe informar cero plazas ocupadas antes de asignar.'
    );

    $assignments = new Asignacion($db);
    $expiredOffer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, 1,
            DATE_SUB(CURDATE(), INTERVAL 2 DAY),
            DATE_SUB(CURDATE(), INTERVAL 1 DAY),
            'Indiferente', 'Aprobada')"
    );
    $expiredOffer->execute(['empresa' => $createdCompany, 'ciclo' => $cycleId]);
    $createdExpiredOffer = (int)$db->lastInsertId();
    $expiredAvailability = $db->prepare(
        "INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
         VALUES (:alumno_id, DATE_SUB(CURDATE(), INTERVAL 2 DAY),
            DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'Indiferente')"
    );
    $expiredAvailability->execute(['alumno_id' => $createdStudents[1]]);
    $expiredAvailabilityId = (int)$db->lastInsertId();
    $expiredWeeklyAvailability = $db->prepare(
        "INSERT INTO alumno_disponibilidad_semanal
            (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
         VALUES (:disponibilidad_id,
            WEEKDAY(DATE_SUB(CURDATE(), INTERVAL 2 DAY)) + 1, '08:00:00', '14:00:00')"
    );
    $expiredWeeklyAvailability->execute(['disponibilidad_id' => $expiredAvailabilityId]);
    $expiredAssignmentRejected = false;
    try {
        $assignments->crear(
            $createdExpiredOffer,
            $createdStudents[1],
            $createdUsers[3],
            'Tutor de empresa'
        );
    } catch (RuntimeException $e) {
        $expiredAssignmentRejected = $e->getMessage()
            === 'La oferta no existe, no está aprobada o ya no está vigente.';
    }
    assertTrue(
        $expiredAssignmentRejected,
        'Una oferta vencida debe rechazarse aunque el alumno conserve disponibilidad compatible.'
    );
    $partialCoverageRejected = false;
    try {
        $assignments->crear($createdOffer, $createdStudents[1], $createdUsers[3], 'Tutor de empresa');
    } catch (DomainException $exception) {
        $partialCoverageRejected = str_contains($exception->getMessage(), 'cobertura horaria');
    }
    assertTrue($partialCoverageRejected, 'La asignación forzada debe rechazar franjas que solo se solapan parcialmente.');
    $setIndifferent = $db->prepare(
        'UPDATE alumno_disponibilidad SET es_indiferente = TRUE WHERE alumno_id = :alumno'
    );
    $setIndifferent->execute(['alumno' => $createdStudents[0]]);
    $deleteWeeklySchedule = $db->prepare(
        'DELETE ds FROM alumno_disponibilidad_semanal ds
         INNER JOIN alumno_disponibilidad d ON d.id = ds.disponibilidad_id
         WHERE d.alumno_id = :alumno'
    );
    $deleteWeeklySchedule->execute(['alumno' => $createdStudents[0]]);
    $indifferentCandidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(
        count($indifferentCandidates) === 1
            && (int)$indifferentCandidates[0]['alumno_id'] === $createdStudents[0],
        'La disponibilidad indiferente debe coincidir aunque no tenga franjas diarias guardadas.'
    );
    $assignments->crear($createdOffer, $createdStudents[0], $createdUsers[3], 'Tutor de empresa');
    $assignedCompany = $assignments->obtenerEmpresaAsignada($createdUsers[1]);
    assertTrue(
        $assignedCompany !== null
            && $assignedCompany['nombre_empresa'] !== ''
            && !array_key_exists('correo', $assignedCompany),
        'El endpoint de empresa asignada debe devolver solo datos de interés sin datos de contacto.'
    );
    $observerAssignments = $assignments->listar(false, 0, true);
    assertTrue(
        count($observerAssignments) === 1
            && $observerAssignments[0]['alumno_nombre'] === 'Alumno anonimizado'
            && $observerAssignments[0]['alumno_correo'] === null
            && $observerAssignments[0]['tutor_empresa'] === null
            && $observerAssignments[0]['alumno_usuario_id'] === null,
        'El listado de auditoría debe eliminar la identidad del alumnado y del tutor.'
    );
    $assignmentId = (int)$db->query(
        'SELECT id FROM asignaciones_fct WHERE oferta_id = ' . $createdOffer . ' AND alumno_id = ' . $createdStudents[0]
    )->fetchColumn();
    $createdAssignmentId = $assignmentId;
    $assignmentPage = $assignments->obtenerPagina(false, 0, 1);
    assertTrue(
        $assignmentPage['total'] === 1
            && count($assignmentPage['items']) === 1
            && (int)$assignmentPage['items'][0]['id'] === $assignmentId,
        'El listado paginado de asignaciones debe devolver el total y la fila correspondiente.'
    );
    $availabilityModel->reemplazar(
        $createdUsers[2],
        $availabilityStart,
        $availabilityEnd,
        'M',
        false,
        [['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '14:00:00']]
    );
    $remainingCandidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(
        count($remainingCandidates) === 1
            && (int)$remainingCandidates[0]['alumno_id'] === $createdStudents[1],
        'El motor individual debe excluir al alumno asignado y conservar al candidato compatible.'
    );
    assertTrue(
        (int)$matching->obtenerVigentesConAsignaciones()[0]['asignadas'] === 1
            && !isset($matching->obtenerCandidatosVigentes()[$createdOffer])
            && !isset($matching->obtenerCandidatosVigentes($createdUsers[1])[$createdOffer]),
        'El matching agrupado debe reflejar plazas ocupadas y excluir ofertas sin capacidad.'
    );
    assertTrue(
        ($matching->obtenerCandidatosPaginados(
            [$createdOffer],
            [$createdOffer => 1]
        )[$createdOffer]['candidatos'] ?? []) === [],
        'La consulta paginada debe ocultar candidatos cuando la oferta ya no tiene plazas.'
    );
    $paginationOffer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, 1, :inicio, :fin, 'M', 'Aprobada')"
    );
    for ($index = 0; $index < 51; $index++) {
        $paginationOffer->execute([
            'empresa' => $createdCompany,
            'ciclo' => $cycleId,
            'inicio' => $offerDate,
            'fin' => $offerDate,
        ]);
        $createdPaginationOffers[] = (int)$db->lastInsertId();
    }
    $listedOfferIds = array_map(
        'intval',
        array_column($matching->obtenerTodas(true), 'id')
    );
    $fixtureOfferIds = array_merge([$createdOffer], $createdPaginationOffers);
    $listedFixtureOfferPositions = array_map(
        static fn(int $offerId): int|false => array_search($offerId, $listedOfferIds, true),
        $fixtureOfferIds
    );
    $sortedFixtureOfferPositions = $listedFixtureOfferPositions;
    sort($sortedFixtureOfferPositions);
    assertTrue(
        !in_array(false, $listedFixtureOfferPositions, true)
            && $listedFixtureOfferPositions === $sortedFixtureOfferPositions,
        'Las ofertas con la misma fecha de inicio deben conservar un orden predecible.'
    );
    $offerPage = $matching->obtenerPaginaTodas(true, 1, 10);
    assertTrue(
        count($offerPage['items']) === 10
            && $offerPage['total'] === count($listedOfferIds)
            && $offerPage['totalPaginas'] === (int)ceil(count($listedOfferIds) / 10),
        'La paginación de ofertas debe limitar filas y calcular el total desde la base de datos.'
    );
    $_GET = [
        'especialidad_id' => (string)$cycleId,
        'estado' => 'Aprobada',
        'desde' => $offerDate,
        'hasta' => $offerDate,
        'pagina' => '1',
    ];
    ob_start();
    (new ConsultaController($db))->buscar();
    $consultationPageOne = (string)ob_get_clean();
    assertTrue(
        str_contains($consultationPageOne, 'Mostrando 1-50 de')
            && substr_count($consultationPageOne, '<tr>') === 51
            && str_contains($consultationPageOne, 'estado=Aprobada'),
        'La primera página debe limitar resultados y conservar los filtros en la navegación.'
    );
    $_GET['pagina'] = '2';
    ob_start();
    (new ConsultaController($db))->buscar();
    $consultationPageTwo = (string)ob_get_clean();
    assertTrue(
        str_contains($consultationPageTwo, 'Mostrando 51-')
            && str_contains($consultationPageTwo, 'Página 2 de')
            && str_contains($consultationPageTwo, 'name="desde" value="' . $offerDate . '"'),
        'La segunda página debe mostrar resultados restantes y mantener filtros seleccionados.'
    );
    $_GET['pagina'] = '0';
    ob_start();
    (new ConsultaController($db))->buscar();
    $invalidConsultationPage = (string)ob_get_clean();
    assertTrue(
        str_contains($invalidConsultationPage, 'Selecciona una página válida.')
            && str_contains($invalidConsultationPage, 'No se encontraron resultados.'),
        'La consulta debe rechazar números de página fuera del rango.'
    );
    $_GET = [];
    $availabilityModel->reemplazar(
        $createdUsers[1],
        $availabilityEditStart,
        $availabilityEnd,
        'T',
        false,
        [['dia_semana' => 2, 'hora_inicio' => '15:15:00', 'hora_fin' => '19:00:00']]
    );
    $updatedAvailability = $availabilityModel->obtenerPorUsuario($createdUsers[1]);
    $updatedWeekly = $availabilityModel->obtenerHorarioSemanalPorUsuario($createdUsers[1]);
    assertTrue(
        count($updatedAvailability) === 1
            && $updatedAvailability[0]['fecha_inicio'] === $availabilityEditStart
            && $updatedAvailability[0]['turno'] === 'T'
            && (int)$updatedAvailability[0]['es_indiferente'] === 0
            && count($updatedWeekly) === 1
            && (int)$updatedWeekly[0]['dia_semana'] === 2
            && $updatedWeekly[0]['hora_inicio'] === '15:15:00',
        'La edición de disponibilidad debe persistir el periodo, turno y horario semanal.'
    );

    $capacityRejected = false;
    try {
        $assignments->crear($createdOffer, $createdStudents[1], $createdUsers[3], 'Tutor de empresa');
    } catch (RuntimeException $e) {
        $capacityRejected = str_contains($e->getMessage(), 'plazas');
    }
    assertTrue($capacityRejected, 'El sistema debe impedir superar el cupo de plazas.');

    $gradeModel = new Calificacion($db);
    assertTrue(
        $gradeModel->obtenerNotaMediaPorUsuario($createdUsers[1]) === null,
        'El promedio del alumno debe permanecer vacío mientras no tenga calificaciones.'
    );
    $gradeBeforeCloseRejected = false;
    try {
        $gradeModel->guardar((int)$createdAssignmentId, $createdUsers[3], 8.5, 'Prueba');
    } catch (DomainException $exception) {
        $gradeBeforeCloseRejected = str_contains($exception->getMessage(), 'cerradas');
    }
    assertTrue($gradeBeforeCloseRejected, 'No se debe calificar una práctica que siga activa.');

    assertTrue($assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación activa debe poder cerrarse.');
    assertTrue(!$assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación cerrada no debe cerrarse dos veces.');
    assertTrue(
        $assignments->listarActivasPorUsuario($createdUsers[1]) === [],
        'El dashboard no debe recuperar del historial las asignaciones ya cerradas.'
    );
    $precisionRejected = false;
    try {
        $gradeModel->guardar($assignmentId, $createdUsers[3], 8.501, 'Precisión excesiva');
    } catch (InvalidArgumentException) {
        $precisionRejected = true;
    }
    assertTrue($precisionRejected, 'El modelo debe rechazar notas con más de dos decimales.');
    $gradeModel->guardar($assignmentId, $createdUsers[3], 8.5, 'Buen desempeño.');
    assertTrue(
        $gradeModel->obtenerNotaMediaPorUsuario($createdUsers[1]) === 8.5,
        'El promedio del dashboard debe incluir la calificación final del alumno.'
    );
    $ownGrades = $gradeModel->listar(true, $createdUsers[1]);
    assertTrue(
        count($ownGrades) === 1
            && (float)$ownGrades[0]['nota'] === 8.5
            && $ownGrades[0]['observaciones'] === 'Buen desempeño.',
        'El alumno debe poder consultar su calificación final persistida.'
    );
    $gradeModel->guardar($assignmentId, $createdUsers[3], 9.25, 'Nota revisada.');
    assertTrue(
        count($gradeModel->listar()) === 1
            && (float)$gradeModel->listar()[0]['nota'] === 9.25
            && $gradeModel->obtenerNotaMediaPorUsuario($createdUsers[1]) === 9.25,
        'La edición de una nota debe actualizar la misma calificación, sin duplicarla.'
    );
    $observerGrades = $gradeModel->listar(false, 0, true);
    assertTrue(
        count($observerGrades) === 1
            && $observerGrades[0]['alumno_nombre'] === 'Alumno anonimizado'
            && $observerGrades[0]['alumno_correo'] === null,
        'Las calificaciones consultadas por Observador deben omitir la identidad del alumno.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[3], 'rol' => 'Coordinador'];
    ob_start();
    (new CalificacionController($db))->listar();
    $staffGradesHtml = ob_get_clean();
    assertTrue(
        is_string($staffGradesHtml)
            && str_contains($staffGradesHtml, 'Actualizar nota')
            && str_contains($staffGradesHtml, 'Nota revisada.'),
        'El personal autorizado debe poder consultar y actualizar la nota de una práctica cerrada.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    ob_start();
    (new CalificacionController($db))->misCalificaciones();
    $studentGradesHtml = ob_get_clean();
    assertTrue(
        is_string($studentGradesHtml)
            && str_contains($studentGradesHtml, 'Mis calificaciones de FCT')
            && str_contains($studentGradesHtml, '9,25 / 10')
            && str_contains($studentGradesHtml, 'Nota revisada.'),
        'La vista del alumno debe presentar solo sus notas y observaciones persistidas.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[3], 'rol' => 'Observador'];
    ob_start();
    (new CalificacionController($db))->listar();
    $observerGradesHtml = ob_get_clean();
    assertTrue(
        is_string($observerGradesHtml)
            && str_contains($observerGradesHtml, 'Calificaciones de prácticas')
            && !str_contains($observerGradesHtml, 'Guardar nota')
            && !str_contains($observerGradesHtml, 'Actualizar nota'),
        'La vista Observador debe permitir consulta de notas sin formularios de escritura.'
    );

    $adminId = (int)$createdUsers[4];
    $adminViewHtml = '';
    $_SESSION = ['usuario_id' => $adminId, 'rol' => 'Administrador'];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $adminViewHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($adminViewHtml, 'Coordinadora Álvarez O&#039;Connor')
            && str_contains($adminViewHtml, 'data-delete-user=')
            && (
                FrontendAssets::isEnabled()
                    ? str_contains($adminViewHtml, 'data-vue-island="UserDeleteDialog"')
                        && str_contains($adminViewHtml, 'data-prop-csrf=')
                        && !str_contains($adminViewHtml, 'assets/js/admin-users.js')
                    : str_contains($adminViewHtml, 'Estás seguro?')
                        && str_contains($adminViewHtml, 'No, volver.')
                        && str_contains($adminViewHtml, 'Sí, eliminar')
                        && str_contains($adminViewHtml, 'assets/js/admin-users.js')
            )
            && (
                FrontendAssets::isEnabled()
                    || (
                        str_contains($adminViewHtml, 'Buscar por correo o nombre')
                        && str_contains($adminViewHtml, 'Filtrar cuentas')
                        && str_contains($adminViewHtml, 'Cuenta actual')
                    )
            )
            && !str_contains(
                $adminViewHtml,
                'aria-label="Desactivar cuenta de ' . htmlspecialchars($emails[4], ENT_QUOTES, 'UTF-8') . '"'
            ),
        'La administración debe mostrar los usuarios y habilitar el diálogo Vue o su fallback PHP.'
    );
    $_GET = ['cuenta_creada' => '1'];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $createdAccountHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($createdAccountHtml, 'Cuenta creada correctamente.')
            && str_contains($createdAccountHtml, 'role="status"'),
        'La administración debe confirmar de forma accesible la creación correcta de una cuenta.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'email' => 'correo-invalido',
        'password' => 'NeverEchoThisPassword123',
        'nombre' => "Nombre O'Connor",
        'apellidos' => 'Apellidos de prueba',
        'rol' => 'Observador',
    ];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $accountFormHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($accountFormHtml, 'value="correo-invalido"')
            && str_contains($accountFormHtml, 'value="Nombre O&#039;Connor"')
            && str_contains($accountFormHtml, 'value="Apellidos de prueba"')
            && str_contains($accountFormHtml, 'value="Observador" selected')
            && !str_contains($accountFormHtml, 'NeverEchoThisPassword123'),
        'Un error al crear una cuenta debe conservar los campos no sensibles y limpiar la contraseña.'
    );
    $_POST = [
        'email' => 'coordinador-valido@example.test',
        'password' => 'ValidInitialPassword123',
        'nombre' => '<script>alert(1)</script>',
        'apellidos' => 'Apellidos válidos',
        'rol' => 'Coordinador',
    ];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $missingCoordinatorNameHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($missingCoordinatorNameHtml, 'Los coordinadores necesitan nombres y apellidos válidos')
            && !str_contains($missingCoordinatorNameHtml, '<script>alert(1)</script>')
            && str_contains($missingCoordinatorNameHtml, '&lt;script&gt;alert(1)&lt;/script&gt;')
            && !str_contains($missingCoordinatorNameHtml, 'Comprueba si el correo ya está registrado'),
        'El nombre del coordinador debe validarse y mostrarse escapado antes de intentar crear la cuenta.'
    );
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['estado_actualizado' => '1'];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $updatedAccountStatusHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($updatedAccountStatusHtml, 'Estado de la cuenta actualizado.')
            && str_contains($updatedAccountStatusHtml, 'role="status"'),
        'La administración debe confirmar de forma accesible la actualización del estado de una cuenta.'
    );
    $filteredStaff = (new Usuario($db))->obtenerTodos($emails[3], 'Coordinador', true);
    assertTrue(
        count($filteredStaff) === 1
            && $filteredStaff[0]['email'] === $emails[3]
            && $filteredStaff[0]['rol'] === 'Coordinador',
        'Los filtros de texto, rol y estado deben combinarse sin devolver cuentas ajenas.'
    );
    $_GET = ['buscar' => $emails[3], 'rol' => 'Coordinador', 'activo' => '1'];
    ob_start();
    (new UsuarioAdminController($db))->gestionar();
    $filteredAdminView = (string)ob_get_clean();
    assertTrue(
        str_contains($filteredAdminView, htmlspecialchars($emails[3], ENT_QUOTES, 'UTF-8'))
            && !str_contains($filteredAdminView, htmlspecialchars($emails[4], ENT_QUOTES, 'UTF-8'))
            && str_contains($filteredAdminView, 'value="Coordinador" selected')
            && str_contains($filteredAdminView, 'value="1" selected')
            && str_contains($filteredAdminView, 'name="buscar" value="' . htmlspecialchars($emails[3], ENT_QUOTES, 'UTF-8') . '"')
            && str_contains($filteredAdminView, 'name="rol" value="Coordinador"')
            && str_contains($filteredAdminView, 'name="activo_filtro" value="1"'),
        'La página administrativa debe aplicar filtros y conservarlos en los formularios de acción.'
    );
    $_GET = [];

    $historicalStudentDeleteBlocked = false;
    try {
        (new Usuario($db))->eliminarPermanentemente($createdUsers[1], $adminId);
    } catch (DomainException $exception) {
        $historicalStudentDeleteBlocked = str_contains($exception->getMessage(), 'prácticas registradas');
    }
    assertTrue(
        $historicalStudentDeleteBlocked,
        'No se debe borrar un alumno con una práctica que debe conservarse en el historial.'
    );
    $assignerDeleteBlocked = false;
    try {
        (new Usuario($db))->eliminarPermanentemente($createdUsers[3], $adminId);
    } catch (DomainException $exception) {
        $assignerDeleteBlocked = str_contains($exception->getMessage(), 'ha asignado prácticas');
    }
    assertTrue(
        $assignerDeleteBlocked,
        'No se debe borrar la cuenta coordinadora que figura como autora de una asignación.'
    );

    $nonAdminDeleteBlocked = false;
    try {
        (new Usuario($db))->eliminarPermanentemente($createdUsers[5], $createdUsers[3]);
    } catch (DomainException $exception) {
        $nonAdminDeleteBlocked = str_contains($exception->getMessage(), 'Solo un administrador');
    }
    assertTrue($nonAdminDeleteBlocked, 'Solo un administrador activo debe poder realizar una eliminación permanente.');

    (new Auditoria($db))->registrar($createdUsers[6], 'prueba.auditada', 'usuarios', $createdUsers[6], 'fixture de retención');
    $auditedUserDeleteBlocked = false;
    try {
        (new Usuario($db))->eliminarPermanentemente($createdUsers[6], $adminId);
    } catch (DomainException $exception) {
        $auditedUserDeleteBlocked = str_contains($exception->getMessage(), 'operaciones auditadas');
    }
    assertTrue($auditedUserDeleteBlocked, 'No se deben borrar las cuentas que poseen operaciones en la auditoría.');

    (new Usuario($db))->eliminarPermanentemente($createdUsers[5], $adminId);
    $deletedObserver = $db->prepare('SELECT COUNT(*) FROM usuarios WHERE id = :id');
    $deletedObserver->execute(['id' => $createdUsers[5]]);
    assertTrue((int)$deletedObserver->fetchColumn() === 0, 'Una cuenta sin historial debe poder eliminarse permanentemente.');
    $deletionAudit = $db->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :actor AND evento = 'usuario.eliminado' AND entidad_id = :entidad"
    );
    $deletionAudit->execute(['actor' => $adminId, 'entidad' => $createdUsers[5]]);
    assertTrue((int)$deletionAudit->fetchColumn() === 1, 'La eliminación permanente debe quedar registrada en auditoría.');

    $eventStart = (new DateTimeImmutable('today'))->modify('+3 days');
    $eventEnd = $eventStart->modify('+7 days');
    $updateOfferDates = $db->prepare(
        'UPDATE empresa_especialidades_plazas SET fecha_inicio = :inicio, fecha_fin = :fin WHERE id = :id'
    );
    $updateOfferDates->execute([
        'inicio' => $eventStart->format('Y-m-d'),
        'fin' => $eventEnd->format('Y-m-d'),
        'id' => $createdOffer,
    ]);
    $db->prepare('UPDATE empresa_especialidades_plazas SET plazas_cuantas = 2 WHERE id = :id')
        ->execute(['id' => $createdOffer]);
    $updateAvailabilityDates = $db->prepare(
        'UPDATE alumno_disponibilidad SET fecha_inicio = :inicio, fecha_fin = :fin WHERE alumno_id = :alumno'
    );
    $updateAvailabilityDates->execute([
        'inicio' => $eventStart->format('Y-m-d'),
        'fin' => $eventEnd->format('Y-m-d'),
        'alumno' => $createdStudents[0],
    ]);
    $addAvailabilityPeriod = $db->prepare(
        'INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
         VALUES (:alumno, :inicio, :fin, :turno)'
    );
    $addAvailabilityPeriod->execute([
        'alumno' => $createdStudents[0],
        'inicio' => $eventEnd->format('Y-m-d'),
        'fin' => $eventEnd->modify('+5 days')->format('Y-m-d'),
        'turno' => 'M',
    ]);
    $activateAssignment = $db->prepare("UPDATE asignaciones_fct SET estado = 'Asignada' WHERE id = :id");
    $activateAssignment->execute(['id' => $createdAssignmentId]);
    $activeAssignmentsForDashboard = $assignments->listarActivasPorUsuario($createdUsers[1]);
    assertTrue(
        count($activeAssignmentsForDashboard) === 1
            && array_keys($activeAssignmentsForDashboard[0]) === ['fecha_inicio', 'fecha_fin', 'nombre_empresa'],
        'El dashboard debe recuperar solo las asignaciones activas y los campos que muestra.'
    );
    $databaseEvents = $assignments->obtenerProximosEventos($createdUsers[1]);
    assertTrue(
        count($databaseEvents) === 5
            && $databaseEvents[0]['fecha'] <= $databaseEvents[1]['fecha']
            && !str_starts_with($databaseEvents[0]['titulo'], 'Fin de'),
        'La consulta de próximos eventos debe incluir cinco elementos en orden cronológico estable.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno', 'nombre' => 'Alumno de prueba'];
    ob_start();
    (new InicioController())->mostrar();
    $dashboardHtml = (string)ob_get_clean();
    preg_match(
        '~<strong>(\d+)</strong>\s*<span class="fct-stat__label">Ofertas compatibles</span>~',
        $dashboardHtml,
        $assignedStudentOfferMetric
    );
    preg_match(
        '~<strong>(\d+)</strong>\s*<span class="fct-stat__label">Empresas compatibles</span>~',
        $dashboardHtml,
        $assignedStudentCompanyMetric
    );
    assertTrue(
        isset($assignedStudentOfferMetric[1], $assignedStudentCompanyMetric[1])
            && (int)$assignedStudentOfferMetric[1] === 0
            && (int)$assignedStudentCompanyMetric[1] === 0
            && str_contains($dashboardHtml, 'Ya tienes una práctica asignada. Cuando finalice, podrás consultar nuevas ofertas compatibles.'),
        'El dashboard no debe ofrecer nuevas prácticas a un alumno que ya tiene una asignación activa.'
    );
    $_SESSION = ['usuario_id' => $createdUsers[1], 'rol' => 'Alumno'];
    ob_start();
    (new OfertaController($db))->listar();
    $assignedStudentOfferListingHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($assignedStudentOfferListingHtml, 'Ya tienes una práctica asignada')
            && str_contains($assignedStudentOfferListingHtml, 'Cuando finalice, podrás consultar nuevas ofertas compatibles.'),
        'El listado debe explicar que las nuevas ofertas no están disponibles mientras haya una práctica activa.'
    );

    $availabilityModel->reemplazar(
        $createdUsers[2],
        $eventStart->modify('-2 days')->format('Y-m-d'),
        $eventEnd->modify('+5 days')->format('Y-m-d'),
        'M',
        false,
        [['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '14:00:00']]
    );
    $upcomingAvailability = $availabilityModel->obtenerVigentesPorUsuario(
        $createdUsers[2],
        (new DateTimeImmutable('today'))->format('Y-m-d')
    );
    assertTrue(
        count($upcomingAvailability) === 1
            && array_keys($upcomingAvailability[0]) === ['fecha_inicio', 'fecha_fin'],
        'El dashboard debe recuperar solo fechas de disponibilidad vigente o futura.'
    );
    $compatibleCandidates = (new Oferta($db))->obtenerCandidatosVigentes($createdUsers[2]);
    assertTrue(
        isset($compatibleCandidates[$createdOffer]),
        'El alumno compatible debe aparecer para la oferta con plazas libres.'
    );
    $compatibleOfferIds = array_fill_keys(array_map('intval', array_keys($compatibleCandidates)), true);
    $compatibleOffers = array_values(array_filter(
        (new Oferta($db))->obtenerVigentesConAsignaciones(),
        static fn(array $offer): bool => isset($compatibleOfferIds[(int)$offer['id']])
    ));
    $dashboardOfferCount = count($compatibleOffers);
    $dashboardCompanyCount = count(array_unique(array_column($compatibleOffers, 'empresa_id')));
    $_SESSION = ['usuario_id' => $createdUsers[2], 'rol' => 'Alumno', 'nombre' => 'Segundo alumno'];
    ob_start();
    (new InicioController())->mostrar();
    $compatibleDashboardHtml = (string)ob_get_clean();
    ob_start();
    (new OfertaController($db))->listar();
    $compatibleOfferListingHtml = (string)ob_get_clean();
    preg_match(
        '~<strong>(\d+)</strong>\s*<span class="fct-stat__label">Ofertas compatibles</span>~',
        $compatibleDashboardHtml,
        $dashboardOfferMetric
    );
    preg_match(
        '~<strong>(\d+)</strong>\s*<span class="fct-stat__label">Empresas compatibles</span>~',
        $compatibleDashboardHtml,
        $dashboardCompanyMetric
    );
    assertTrue(
        str_contains(
            $compatibleDashboardHtml,
            'Del ' . $eventStart->format('d/m/Y') . ' al ' . $eventEnd->format('d/m/Y') . ' - Jornada partida'
        ),
        'La tarjeta debe mostrar las fechas y la jornada partida de la oferta.'
    );
    $compatibleListingChecks = [
        'heading' => str_contains($compatibleOfferListingHtml, 'Ofertas compatibles con tu perfil'),
        'accessible wrapper' => str_contains($compatibleOfferListingHtml, '<div class="assignments-table-wrap" role="region" aria-label="Listado de ofertas" tabindex="0">'),
        'caption' => str_contains($compatibleOfferListingHtml, '<caption>Ofertas compatibles con tu perfil</caption>'),
        'company header' => str_contains($compatibleOfferListingHtml, '<th scope="col">Empresa</th>'),
        'vacancies header' => str_contains($compatibleOfferListingHtml, '<th scope="col">Vacantes disponibles</th>'),
        'one available vacancy' => str_contains($compatibleOfferListingHtml, '<td>1</td>'),
        'split jornada' => str_contains($compatibleOfferListingHtml, '<td>Jornada partida</td>'),
        'start date' => str_contains($compatibleOfferListingHtml, '<td>' . $eventStart->format('Y-m-d') . ' –'),
        'end date' => str_contains($compatibleOfferListingHtml, $eventEnd->format('Y-m-d') . '</td>'),
        'company name' => str_contains($compatibleOfferListingHtml, "Test Company $fixture"),
        'no legacy turn label' => !str_contains($compatibleOfferListingHtml, '<td>M</td>'),
    ];
    assertTrue(
        !in_array(false, $compatibleListingChecks, true),
        'El listado abierto desde el dashboard debe mantener el filtro de compatibilidad y mostrar el cupo libre. Fallos: '
            . implode(', ', array_keys(array_filter($compatibleListingChecks, static fn(bool $passed): bool => !$passed)))
            . ' Contenido: ' . trim(preg_replace('/\s+/', ' ', strip_tags($compatibleOfferListingHtml)))
    );
    $_SESSION = [];
    ob_start();
    (new OfertaController($db))->listar();
    $publicOfferListingHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($publicOfferListingHtml, 'Ofertas de prácticas')
            && !str_contains($publicOfferListingHtml, 'Ofertas compatibles con tu perfil'),
        'El listado público debe conservar la visualización general de ofertas aprobadas.'
    );
    $availabilityStartPosition = strpos($dashboardHtml, 'Inicio de disponibilidad');
    $practiceStartPosition = strpos($dashboardHtml, 'Inicio de prácticas');
    $availabilityEndPosition = strpos($dashboardHtml, 'Fin de disponibilidad');
    $practiceEndPosition = strpos($dashboardHtml, 'Fin de prácticas');
    $eventListStart = strpos($dashboardHtml, '<ol class="fct-events__list">');
    $eventListEnd = $eventListStart === false ? false : strpos($dashboardHtml, '</ol>', $eventListStart);
    $eventsHtml = $eventListStart === false || $eventListEnd === false
        ? ''
        : substr($dashboardHtml, $eventListStart, $eventListEnd - $eventListStart);
    $lastAvailabilityStartPosition = strrpos($eventsHtml, 'Inicio de disponibilidad');
    $sameDayPracticeEndPosition = strpos($eventsHtml, 'Fin de prácticas');
    assertTrue(
        isset($dashboardOfferMetric[1], $dashboardCompanyMetric[1])
            && (int)$dashboardOfferMetric[1] === (int)$dashboardOfferCount
            && (int)$dashboardCompanyMetric[1] === (int)$dashboardCompanyCount
            && str_contains($compatibleDashboardHtml, '<small class="fct-offer__capacity">1 plaza disponible</small>')
            && str_contains($dashboardHtml, 'href="index.php?action=mis-asignaciones">Ver asignaciones')
            && str_contains($dashboardHtml, 'href="index.php?action=disponibilidad">Gestionar disponibilidad')
            && $availabilityStartPosition !== false
            && $practiceStartPosition !== false
            && $availabilityEndPosition !== false
            && $practiceEndPosition !== false
            && $availabilityStartPosition < $practiceStartPosition
            && $availabilityEndPosition < $practiceEndPosition
            && substr_count($eventsHtml, '<li>') === 5
            && $lastAvailabilityStartPosition !== false
            && $sameDayPracticeEndPosition !== false
            && $lastAvailabilityStartPosition < $sameDayPracticeEndPosition
            && str_contains($dashboardHtml, $eventStart->format('Y'))
            && str_contains($dashboardHtml, $eventEnd->format('Y')),
        'El dashboard debe contar solo plazas vacantes y ordenar inicios antes que finales en fechas iguales.'
    );

    $pastAvailability = $db->prepare(
        'UPDATE alumno_disponibilidad
         SET fecha_inicio = :inicio, fecha_fin = :fin
         WHERE alumno_id = :alumno'
    );
    $pastAvailability->execute([
        'inicio' => (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
        'fin' => (new DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d'),
        'alumno' => $createdStudents[1],
    ]);
    $_SESSION = ['usuario_id' => $createdUsers[2], 'rol' => 'Alumno', 'nombre' => 'Segundo alumno'];
    ob_start();
    (new InicioController())->mostrar();
    $expiredAvailabilityDashboard = (string)ob_get_clean();
    assertTrue(
        str_contains($expiredAvailabilityDashboard, '<p class="fct-empty" role="status">Registra una disponibilidad vigente')
            && str_contains($expiredAvailabilityDashboard, 'href="index.php?action=disponibilidad">Registrar disponibilidad')
            && str_contains($expiredAvailabilityDashboard, 'Gestionar disponibilidad')
            && str_contains($expiredAvailabilityDashboard, 'No tienes eventos próximos.'),
        'Un periodo vencido debe ofrecer registrar disponibilidad y no aparecer como evento próximo.'
    );
    ob_start();
    (new OfertaController($db))->listar();
    $expiredAvailabilityOfferListingHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($expiredAvailabilityOfferListingHtml, '<p role="status">Necesitas una disponibilidad vigente')
            && str_contains(
                $expiredAvailabilityOfferListingHtml,
                'href="index.php?action=disponibilidad">Registrar disponibilidad'
            ),
        'El listado de ofertas debe permitir actualizar disponibilidad cuando todos los periodos vencieron.'
    );

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

    $_POST = [];
    $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;
    CsrfMiddleware::verify();
    unset($_SERVER['HTTP_X_CSRF_TOKEN']);

    $sessionRoute = (require __DIR__ . '/../routes/web.php')['session'] ?? null;
    assertTrue(
        is_array($sessionRoute)
            && $sessionRoute['method'] === 'GET'
            && $sessionRoute['controller'] === AuthController::class
            && $sessionRoute['action'] === 'session',
        'El estado de sesión debe publicarse por una ruta GET del controlador de autenticación.'
    );

    echo "OK: migraciones, perfiles, recuperación, validación española, convenios, ofertas, matching, paginación, disponibilidad, dashboard, calificaciones, usuarios, webhooks, estado de sesión y CSRF.\n";
} finally {
    foreach ($createdPaginationOffers as $paginationOfferId) {
        $stmt = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :offer');
        $stmt->execute(['offer' => $paginationOfferId]);
    }
    putenv('FCT_WEBHOOK_OUTBOUND_URL');
    if ($webhookEventId !== null) {
        $stmt = $db->prepare('DELETE FROM webhook_inbox WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $webhookEventId]);
    }
    if ($availabilityWebhookEventId !== null) {
        $stmt = $db->prepare('DELETE FROM webhook_outbox WHERE event_id = :event_id');
        $stmt->execute(['event_id' => $availabilityWebhookEventId]);
    }
    if ($createdWebhookOffer !== null) {
        $stmt = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :offer');
        $stmt->execute(['offer' => $createdWebhookOffer]);
    }
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
    if ($createdExpiredOffer !== null) {
        $stmt = $db->prepare('DELETE FROM asignaciones_fct WHERE oferta_id = :offer');
        $stmt->execute(['offer' => $createdExpiredOffer]);
        $stmt = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :offer');
        $stmt->execute(['offer' => $createdExpiredOffer]);
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
