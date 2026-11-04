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
require_once __DIR__ . '/../app/Core/ApiToken.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/AlumnoController.php';
require_once __DIR__ . '/../app/Controllers/CalificacionController.php';
require_once __DIR__ . '/../app/Controllers/DisponibilidadController.php';
require_once __DIR__ . '/../app/Controllers/UsuarioAdminController.php';
require_once __DIR__ . '/../app/Controllers/ActivacionController.php';
require_once __DIR__ . '/../app/Models/SolicitudReenvioActivacion.php';
require_once __DIR__ . '/../app/Models/Oferta.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Alumno.php';
require_once __DIR__ . '/../app/Models/Disponibilidad.php';
require_once __DIR__ . '/../app/Models/Calificacion.php';
require_once __DIR__ . '/../app/Models/Convenio.php';
require_once __DIR__ . '/../app/Models/Empresa.php';
require_once __DIR__ . '/../app/Models/Usuario.php';
require_once __DIR__ . '/../app/Models/Especialidad.php';
require_once __DIR__ . '/../app/Core/SpanishDataValidator.php';
require_once __DIR__ . '/../app/Controllers/ConvenioController.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/file_security.php';
require_once __DIR__ . '/branding.php';

$db = getPDOConnection();
$actualDatabase = $db->query('SELECT DATABASE()')->fetchColumn();
assertTrue($actualDatabase === $testDatabase, 'La conexión no apunta a la base de prueba.');
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

$required = [
    'identificador_fiscal_personal' => ['table' => 'usuarios', 'column' => 'identificador_fiscal'],
    'nombre_personal' => ['table' => 'usuarios', 'column' => 'nombre'],
    'apellidos_personal' => ['table' => 'usuarios', 'column' => 'apellidos'],
    'persona_contacto' => ['table' => 'empresas', 'column' => 'persona_contacto'],
    'fecha_nacimiento' => ['table' => 'alumnos', 'column' => 'fecha_nacimiento'],
    'turno' => ['table' => 'empresa_especialidades_plazas', 'column' => 'turno'],
    'especialidad_id' => ['table' => 'convenios', 'column' => 'especialidad_id'],
    'curso_alumno' => ['table' => 'alumnos', 'column' => 'curso'],
    'tipo_documento_alumno' => ['table' => 'alumnos', 'column' => 'tipo_documento'],
    'observaciones_administrativas_alumno' => ['table' => 'alumnos', 'column' => 'observaciones_administrativas'],
    'estado_ciclo' => ['table' => 'especialidades', 'column' => 'activo'],
    'dia_semana' => ['table' => 'alumno_disponibilidad_semanal', 'column' => 'dia_semana'],
    'es_indiferente' => ['table' => 'alumno_disponibilidad', 'column' => 'es_indiferente'],
    'nota' => ['table' => 'calificaciones_fct', 'column' => 'nota'],
    'codigo_postal' => ['table' => 'alumnos', 'column' => 'codigo_postal'],
    'horario_oferta' => ['table' => 'empresa_oferta_horarios_semanales', 'column' => 'hora_inicio'],
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
$activationTable = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :table'
);
$activationTable->execute(['schema' => $testDatabase, 'table' => 'student_activation_tokens']);
assertTrue((int)$activationTable->fetchColumn() === 1, 'Falta la tabla de enlaces de activación de alumnado.');
$activationTable->execute(['schema' => $testDatabase, 'table' => 'staff_activation_tokens']);
assertTrue((int)$activationTable->fetchColumn() === 1, 'Falta la tabla de enlaces de activación del personal.');
$activationTable->execute(['schema' => $testDatabase, 'table' => 'company_activation_tokens']);
assertTrue((int)$activationTable->fetchColumn() === 1, 'Falta la tabla de enlaces de activación de empresas.');
$activationTable->execute(['schema' => $testDatabase, 'table' => 'activation_resend_requests']);
assertTrue((int)$activationTable->fetchColumn() === 1, 'Falta la tabla de solicitudes de reenvío de activación.');
$activationTable->execute(['schema' => $testDatabase, 'table' => 'audit_logs']);
assertTrue((int)$activationTable->fetchColumn() === 1, 'Falta la tabla de trazabilidad administrativa.');
$auditColumns = $db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'
       AND COLUMN_NAME IN (
           'id_usuario_admin', 'nombre_completo', 'accion', 'entidad_afectada',
           'id_registro_modificado', 'ip_address', 'timestamp'
       )"
)->fetchColumn();
assertTrue((int)$auditColumns === 7, 'La tabla audit_logs debe incluir todos los datos de trazabilidad requeridos.');
$fixture = bin2hex(random_bytes(5));
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
$createdStaffUsers = [];
$createdCompany = null;
$createdStudents = [];
$createdOffer = null;
$createdAgreementId = null;
$createdAssignmentId = null;
$createdCycleId = null;
$createdAdminStudentUserId = null;
$createdAdminStudentProfileId = null;
$activationAuditRequestId = null;

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
    $addUser->execute(['email' => $emails[4], 'hash' => $roleHash, 'role' => 'Administrador']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[5], 'hash' => $roleHash, 'role' => 'Observador']);
    $createdUsers[] = (int)$db->lastInsertId();
    $addUser->execute(['email' => $emails[6], 'hash' => $roleHash, 'role' => 'Observador']);
    $createdUsers[] = (int)$db->lastInsertId();
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
    $archiveRoute = (require __DIR__ . '/../routes/web.php')['archivo-convenios'] ?? null;
    assertTrue(
        is_array($archiveRoute)
            && $archiveRoute['method'] === 'GET'
            && $archiveRoute['roles'] === ['Administrador']
            && $archiveRoute['controller'] === ConvenioController::class
            && $archiveRoute['action'] === 'archivo',
        'El archivo de convenios debe estar reservado a administradores.'
    );
    $archiveDenied = false;
    try {
        (new ConvenioController($db))->archivo();
    } catch (RuntimeException) {
        $archiveDenied = http_response_code() === 403;
    }
    assertTrue($archiveDenied, 'Un observador no debe acceder al archivo de convenios.');
    $_SESSION = [];
    http_response_code(200);

    $specialtyModel = new Especialidad($db);
    $createdCycleId = $specialtyModel->crear("Ciclo de prueba $fixture");
    $cycleId = $createdCycleId;
    assertTrue(
        $specialtyModel->existe($cycleId)
            && in_array(
                $cycleId,
                array_map(static fn(array $cycle): int => (int)$cycle['id'], $specialtyModel->obtenerTodas()),
                true
            ),
        'Un ciclo nuevo debe estar disponible de inmediato para formularios y procesos de la aplicación.'
    );
    $duplicateCycleRejected = false;
    try {
        $specialtyModel->crear("Ciclo de prueba $fixture");
    } catch (DomainException) {
        $duplicateCycleRejected = true;
    }
    assertTrue($duplicateCycleRejected, 'No se deben crear ciclos duplicados.');
    assertTrue($specialtyModel->retirar($cycleId), 'El administrador debe poder retirar un ciclo.');
    assertTrue(
        !$specialtyModel->existe($cycleId)
            && !in_array(
                $cycleId,
                array_map(static fn(array $cycle): int => (int)$cycle['id'], $specialtyModel->obtenerTodas()),
                true
            ),
        'Un ciclo retirado no debe ofrecerse en formularios ni aceptarse en nuevas operaciones.'
    );
    $retiredCycle = array_values(array_filter(
        $specialtyModel->obtenerTodas(false),
        static fn(array $cycle): bool => (int)$cycle['id'] === $cycleId
    ))[0] ?? null;
    assertTrue(
        $retiredCycle !== null && (int)$retiredCycle['activo'] === 0,
        'Retirar un ciclo debe conservarlo en el catálogo administrativo.'
    );
    assertTrue($specialtyModel->reactivar($cycleId), 'El administrador debe poder reactivar un ciclo.');
    assertTrue($specialtyModel->existe($cycleId), 'Un ciclo reactivado debe volver a estar disponible.');

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
    $userModel = new Usuario($db);
    $dniLetterMap = 'TRWAGMYFPDXBNJZSQVHLCKE';
    $staffDniNumber = hexdec(substr($fixture, 0, 7)) % 100000000;
    $staffNif = str_pad((string)$staffDniNumber, 8, '0', STR_PAD_LEFT)
        . $dniLetterMap[$staffDniNumber % 23];
    $adminDniNumber = ($staffDniNumber + 1) % 100000000;
    $adminNif = str_pad((string)$adminDniNumber, 8, '0', STR_PAD_LEFT)
        . $dniLetterMap[$adminDniNumber % 23];
    $observerDniNumber = ($staffDniNumber + 2) % 100000000;
    $observerNif = str_pad((string)$observerDniNumber, 8, '0', STR_PAD_LEFT)
        . $dniLetterMap[$observerDniNumber % 23];
    $userModel->asignarNifPersonal($createdUsers[3], $staffNif);
    $userModel->asignarNifPersonal($createdUsers[4], $adminNif);
    $userModel->asignarNifPersonal($createdUsers[5], $observerNif);
    $lookups = [
        'student' => $userModel->obtenerPorIdentificador(strtolower($dnis[1])),
        'company' => $userModel->obtenerPorIdentificador(strtolower($cifs[0])),
        'coordinator' => $userModel->obtenerPorIdentificador($staffNif),
        'admin' => $userModel->obtenerPorIdentificador($adminNif),
        'observer' => $userModel->obtenerPorIdentificador($observerNif),
        'email' => $userModel->obtenerPorIdentificador($emails[4]),
    ];
    assertTrue(
        (int)($lookups['student']['id'] ?? 0) === $createdUsers[1]
            && (int)($lookups['company']['id'] ?? 0) === $createdUsers[0]
            && (int)($lookups['coordinator']['id'] ?? 0) === $createdUsers[3]
            && (int)($lookups['admin']['id'] ?? 0) === $createdUsers[4]
            && (int)($lookups['observer']['id'] ?? 0) === $createdUsers[5]
            && $lookups['email'] === null,
        'El inicio de sesión debe resolver NIF del personal, DNI/NIE del alumnado y NIF de personas jurídicas, pero no correo. '
            . json_encode(array_map(
                static fn(?array $user): ?int => $user === null ? null : (int)$user['id'],
                $lookups
            ), JSON_THROW_ON_ERROR)
    );
    assertTrue(
        SpanishDataValidator::isDniNieFormat('12345678A')
            && !SpanishDataValidator::isDniNieFormat('1234')
            && SpanishDataValidator::isNifPersonaJuridica('B12345674')
            && !SpanishDataValidator::isNifPersonaJuridica('B12345678')
            && SpanishDataValidator::isPassport('AB123456')
            && !SpanishDataValidator::isPassport('ABC-123'),
        'La validación debe comprobar documentos personales, NIF de persona jurídica y pasaportes.'
    );
    $companyNifNumber = str_pad((string)(hexdec(substr($fixture, 2, 6)) % 10000000), 7, '0', STR_PAD_LEFT);
    $companyNifSum = 0;
    for ($index = 0; $index < 7; $index++) {
        $digit = (int)$companyNifNumber[$index];
        if ($index % 2 === 0) {
            $doubled = $digit * 2;
            $companyNifSum += intdiv($doubled, 10) + ($doubled % 10);
        } else {
            $companyNifSum += $digit;
        }
    }
    $updatedNifPersonaJuridica = 'B' . $companyNifNumber . (string)((10 - ($companyNifSum % 10)) % 10);
    $userModel->actualizarNifPersonaJuridica($createdUsers[0], $updatedNifPersonaJuridica);
    assertTrue(
        (int)($userModel->obtenerPorIdentificador($updatedNifPersonaJuridica)['id'] ?? 0) === $createdUsers[0]
            && $userModel->obtenerPorIdentificador($cifs[0]) === null,
        'El administrador debe poder corregir el NIF de persona jurídica y la autenticación debe usar el valor actualizado.'
    );
    $duplicateStaffNifRejected = false;
    try {
        $userModel->asignarNifPersonal($createdUsers[6], $staffNif);
    } catch (DomainException $exception) {
        $duplicateStaffNifRejected = str_contains($exception->getMessage(), 'ya está asociado');
    }
    assertTrue($duplicateStaffNifRejected, 'No se debe asignar el mismo identificador fiscal a dos cuentas.');
    $studentProfile = (new Alumno($db))->obtenerPorUsuario($createdUsers[1]);
    assertTrue(
        $studentProfile !== null
            && $studentProfile['apellidos'] === "Prueba $fixture 1"
            && $studentProfile['nombre_especialidad'] !== null,
        'El portal debe recuperar el expediente y ciclo del alumno autenticado.'
    );
    $studentModel = new Alumno($db);
    $createdAdminStudentUserId = (new Usuario($db))->crear(
        "center-student-$fixture@example.test",
        bin2hex(random_bytes(16)),
        'Alumno'
    );
    assertTrue($createdAdminStudentUserId > 0, 'El centro debe poder crear una cuenta con una contraseña temporal segura.');
    $createdAdminStudentProfileId = $studentModel->crearDesdePersonal(
        $createdAdminStudentUserId,
        'Alumno',
        "Centro $fixture",
        "D$fixture",
        "center-student-$fixture@example.test",
        $cycleId,
        2,
        'Observación de prueba',
        SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
    );
    $createdStudentByCenter = $db->prepare(
        'SELECT curso, observaciones_administrativas, especialidad_id, dni, tipo_documento
         FROM alumnos WHERE id = :id'
    );
    $createdStudentByCenter->execute(['id' => $createdAdminStudentProfileId]);
    $createdStudentByCenterRow = $createdStudentByCenter->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($createdStudentByCenterRow)
            && (int)$createdStudentByCenterRow['curso'] === 2
            && $createdStudentByCenterRow['observaciones_administrativas'] === 'Observación de prueba'
            && (int)$createdStudentByCenterRow['especialidad_id'] === $cycleId
            && $createdStudentByCenterRow['dni'] === 'D' . $fixture
            && $createdStudentByCenterRow['tipo_documento'] === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT,
        'El alta del centro debe guardar ciclo, curso y observaciones administrativas.'
    );
    assertTrue(
        (int)($userModel->obtenerPorIdentificador(strtolower($createdStudentByCenterRow['dni']))['id'] ?? 0)
            === $createdAdminStudentUserId,
        'El acceso debe permitir localizar al alumnado con su pasaporte guardado.'
    );
    $studentModel->actualizarPerfil(
        $createdUsers[1],
        $emails[1],
        'Ángela',
        "O'Connor",
        '2005-01-01',
        '12345678Z',
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
            && $updatedProfile['dni'] === '12345678Z'
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
            '12345678Z',
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
        str_contains($profileHtml, '>NIF</option>')
            && str_contains($profileHtml, '>Pasaporte</option>')
            && str_contains($profileHtml, 'aria-describedby="profile-document-help"')
            && str_contains($profileHtml, 'Opción destinada a casos especiales de alumnado no residente según la normativa de Formación Profesional'),
        'El perfil debe mostrar NIF y Pasaporte con su nota informativa accesible.'
    );
    assertTrue(
        is_string($profileHtml)
            && str_contains($profileHtml, 'Ángela')
            && str_contains($profileHtml, '28013')
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
        'tipo_documento' => SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI,
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
            && str_contains($invalidProfileHtml, 'letra de control válida'),
        'Los datos inválidos deben marcar el campo y mostrar feedback específico sin guardar.'
    );
    assertTrue(
        $studentModel->obtenerPorUsuario($createdUsers[1])['dni'] === '12345678Z',
        'Un DNI/NIE inválido no debe modificar el expediente.'
    );
    $db->prepare('UPDATE alumnos SET fecha_nacimiento = :nacimiento WHERE id = :id')
        ->execute(['nacimiento' => '2010-01-01', 'id' => $createdStudents[1]]);
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
        'fecha_nacimiento' => '2010-01-01',
        'dni' => '87654321X',
        'tipo_documento' => SpanishDataValidator::DOCUMENT_TYPE_NIF_NIE_DNI,
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
    $studentModel->actualizarPerfil(
        $createdUsers[2],
        $emails[2],
        'Alumno',
        'Apellidos Legado',
        '2010-01-01',
        '87654321X',
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
         VALUES (:alumno, '2027-03-01', '2027-06-30', 'M', FALSE)"
    );
    $weeklySchedule = $db->prepare(
        "INSERT INTO alumno_disponibilidad_semanal
            (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
         VALUES (:periodo, :dia_semana, :hora_inicio, :hora_fin)"
    );
    foreach ($createdStudents as $index => $studentId) {
        $availability->execute(['alumno' => $studentId]);
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
    $availability->execute(['alumno' => $createdStudents[1]]);
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
    $availabilityModel->reemplazar(
        $createdUsers[1],
        '2027-03-01',
        '2027-06-30',
        'M',
        false,
        [['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '14:00:00']]
    );
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_SERVER['HTTP_ACCEPT']);
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    $_POST = [
        'fecha_inicio' => '2027-04-01',
        'fecha_fin' => '2027-06-30',
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
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];

    $agreement = $db->prepare(
        "INSERT INTO convenios (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path, estado)
         VALUES (:empresa, :especialidad, :numero, '2026-01-01', '2027-01-01', '2027-12-31', 'test-placeholder.pdf', 'Pendiente')"
    );
    $agreement->execute([
        'empresa' => $createdCompany,
        'especialidad' => $cycleId,
        'numero' => "AG-$fixture",
    ]);
    $agreementId = (int)$db->lastInsertId();
    $createdAgreementId = $agreementId;
    assertTrue(
        (new Convenio($db))->resolver($agreementId, 'Aprobado', '', $createdUsers[3]),
        'Un convenio pendiente debe poder aprobarse.'
    );
    (new Auditoria($db))->registrar(
        $createdUsers[3],
        'convenio.revisado',
        'convenios',
        $agreementId,
        'estado=Aprobado'
    );
    $agreementModel = new Convenio($db);
    $archivedAgreement = array_values(array_filter(
        $agreementModel->listarArchivo(),
        static fn(array $item): bool => (int)$item['id'] === $agreementId
    ))[0] ?? null;
    assertTrue(
        $archivedAgreement !== null
            && $archivedAgreement['estado'] === 'Aprobado'
            && $archivedAgreement['nombre_revisor'] === "Coordinadora Álvarez O'Connor",
        'El archivo debe mostrar el convenio aprobado y su revisor.'
    );
    assertTrue(
        count(array_filter(
            $agreementModel->listarEventosArchivo(),
            static fn(array $event): bool => (int)$event['convenio_id'] === $agreementId
                && $event['evento'] === 'convenio.revisado'
        )) === 1,
        'El archivo debe incluir los eventos de auditoría del convenio.'
    );
    $companyModel = new Empresa($db);
    assertTrue(
        $companyModel->tieneConvenioParaFechas($createdCompany, $cycleId, '2027-03-15', '2027-06-15'),
        'Un convenio aprobado del mismo ciclo y periodo debe cubrir la oferta.'
    );
    $duplicateAgreementRejected = false;
    try {
        $duplicate = $db->prepare(
            "INSERT INTO convenios
                (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path)
             VALUES (:empresa, :especialidad, :numero, '2026-01-01', '2027-01-01', '2027-12-31', 'duplicate-placeholder.pdf')"
        );
        $duplicate->execute([
            'empresa' => $createdCompany,
            'especialidad' => $cycleId,
            'numero' => "AG-DUP-$fixture",
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
            !$companyModel->tieneConvenioParaFechas($createdCompany, $otherCycleId, '2027-03-15', '2027-06-15'),
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

    $matching = new Oferta($db);
    $createdOffer = $matching->crearOferta(
        $createdCompany,
        $cycleId,
        1,
        '2027-03-15',
        '2027-03-15',
        "Oferta de prueba $fixture",
        'Descripción de prueba de la oferta.',
        'P',
        'Híbrido',
        'Madrid',
        'Trabajo en equipo.',
        'PHP, Vue',
        [['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '13:00:00']]
    );
    $savedOffer = array_values(array_filter(
        $matching->obtenerPorEmpresa($createdCompany),
        static fn(array $offer): bool => (int)$offer['id'] === $createdOffer
    ))[0] ?? null;
    assertTrue(
        $savedOffer !== null
            && $savedOffer['titulo'] === "Oferta de prueba $fixture"
            && $savedOffer['descripcion'] === 'Descripción de prueba de la oferta.'
            && $savedOffer['turno'] === 'P'
            && $savedOffer['modalidad'] === 'Híbrido'
            && $savedOffer['ubicacion'] === 'Madrid'
            && $savedOffer['requisitos'] === 'Trabajo en equipo.'
            && $savedOffer['tecnologias'] === 'PHP, Vue',
        'La oferta debe persistir sus detalles completos.'
    );
    assertTrue(
        $matching->resolver($createdOffer, 'Aprobada', '', $createdUsers[3]),
        'Una oferta pendiente debe poder aprobarse.'
    );
    $candidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(
        count($candidates) === 1 && (int)$candidates[0]['alumno_id'] === $createdStudents[0],
        'El motor de compatibilidad debe exigir que la disponibilidad cubra las horas exactas de la oferta.'
    );

    assertTrue($specialtyModel->retirar($cycleId), 'El ciclo de la oferta debe poder retirarse.');
    assertTrue(
        $matching->obtenerCandidatos($createdOffer) === [],
        'Las ofertas de un ciclo retirado no deben generar candidatos nuevos.'
    );
    $assignments = new Asignacion($db);
    $retiredCycleAssignmentRejected = false;
    try {
        $assignments->crear($createdOffer, $createdStudents[0], $createdUsers[3], 'Tutor de empresa');
    } catch (DomainException) {
        $retiredCycleAssignmentRejected = true;
    }
    assertTrue(
        $retiredCycleAssignmentRejected,
        'La asignación manual también debe impedir emparejar ofertas de ciclos retirados.'
    );
    assertTrue($specialtyModel->reactivar($cycleId), 'El ciclo debe poder reactivarse para continuar la prueba.');
    assertTrue(
        count($matching->obtenerCandidatos($createdOffer)) === 1,
        'Al reactivar un ciclo, sus ofertas deben volver a participar en el emparejamiento.'
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
    assertTrue(count($matching->obtenerCandidatos($createdOffer)) === 0, 'Un alumno asignado no debe seguir siendo candidato.');
    $availabilityModel->reemplazar(
        $createdUsers[1],
        '2027-04-01',
        '2027-06-30',
        'T',
        false,
        [['dia_semana' => 2, 'hora_inicio' => '15:15:00', 'hora_fin' => '19:00:00']]
    );
    $updatedAvailability = $availabilityModel->obtenerPorUsuario($createdUsers[1]);
    $updatedWeekly = $availabilityModel->obtenerHorarioSemanalPorUsuario($createdUsers[1]);
    assertTrue(
        count($updatedAvailability) === 1
            && $updatedAvailability[0]['fecha_inicio'] === '2027-04-01'
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
    $gradeBeforeCloseRejected = false;
    try {
        $gradeModel->guardar((int)$createdAssignmentId, $createdUsers[3], 8.5, 'Prueba');
    } catch (DomainException $exception) {
        $gradeBeforeCloseRejected = str_contains($exception->getMessage(), 'cerradas');
    }
    assertTrue($gradeBeforeCloseRejected, 'No se debe calificar una práctica que siga activa.');

    assertTrue($assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación activa debe poder cerrarse.');
    assertTrue(!$assignments->cerrar($assignmentId, $createdUsers[3]), 'Una asignación cerrada no debe cerrarse dos veces.');
    $precisionRejected = false;
    try {
        $gradeModel->guardar($assignmentId, $createdUsers[3], 8.501, 'Precisión excesiva');
    } catch (InvalidArgumentException) {
        $precisionRejected = true;
    }
    assertTrue($precisionRejected, 'El modelo debe rechazar notas con más de dos decimales.');
    $gradeModel->guardar($assignmentId, $createdUsers[3], 8.5, 'Buen desempeño.');
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
            && (float)$gradeModel->listar()[0]['nota'] === 9.25,
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
            && str_contains($adminViewHtml, 'name="identificador_fiscal"')
            && str_contains($adminViewHtml, 'name="nombre"')
            && str_contains($adminViewHtml, 'name="apellidos"')
            && !str_contains($adminViewHtml, 'name="password"')
            && str_contains($adminViewHtml, 'value="Coordinador"')
            && str_contains($adminViewHtml, 'value="Observador"')
            && str_contains($adminViewHtml, 'value="Administrador"')
            && str_contains($adminViewHtml, 'actualizar-identificador-fiscal-usuario')
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
            ),
        'La administración debe mostrar los usuarios y habilitar el diálogo Vue o su fallback PHP.'
    );

    $staffRoles = ['Administrador', 'Coordinador', 'Observador'];
    $staffIdentifierBase = (hexdec(substr($fixture, 0, 7)) + 1000) % 100000000;
    foreach ($staffRoles as $index => $staffRole) {
        $number = ($staffIdentifierBase + $index) % 100000000;
        $staffNif = str_pad((string)$number, 8, '0', STR_PAD_LEFT)
            . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
        $staffEmail = strtolower($staffRole) . "-$fixture@example.test";
        $staffId = $userModel->crearCuentaStaff(
            $staffEmail,
            $staffRole,
            $staffNif,
            'Personal',
            $staffRole . ' Prueba'
        );
        $createdStaffUsers[] = $staffId;
        if ($staffRole === 'Coordinador') {
            $coordinatorProfile = $db->prepare(
                'INSERT INTO coordinadores (usuario_id, nombre, apellidos)
                 VALUES (:usuario, :nombre, :apellidos)'
            );
            $coordinatorProfile->execute([
                'usuario' => $staffId,
                'nombre' => 'Personal',
                'apellidos' => 'Coordinador Prueba',
            ]);
        }

        $activationToken = bin2hex(random_bytes(32));
        $activationInsert = $db->prepare(
            'INSERT INTO staff_activation_tokens (usuario_id, token_hash, expires_at)
             VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
        );
        $activationInsert->execute([
            'usuario_id' => $staffId,
            'token_hash' => hash('sha256', $activationToken),
        ]);
        $staffRow = $db->prepare(
            'SELECT activo, nombre, apellidos FROM usuarios WHERE id = :id'
        );
        $staffRow->execute(['id' => $staffId]);
        $staffAccount = $staffRow->fetch(PDO::FETCH_ASSOC);
        assertTrue(
            is_array($staffAccount)
                && (int)$staffAccount['activo'] === 0
                && $staffAccount['nombre'] === 'Personal'
                && $staffAccount['apellidos'] === $staffRole . ' Prueba',
            "La cuenta nueva de $staffRole debe guardar los datos institucionales y permanecer inactiva."
        );

        $initialToken = $db->prepare(
            'INSERT INTO staff_activation_tokens (usuario_id, token_hash, expires_at)
             VALUES (:usuario_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))'
        );
        $initialToken->execute([
            'usuario_id' => $staffId,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        ]);
        $resendRequests = new SolicitudReenvioActivacion($db);
        $resendRequests->solicitar($staffEmail, $staffNif);
        $resendRequests->solicitar($staffEmail, $staffNif);
        $pendingRequest = $db->prepare(
            "SELECT COUNT(*) FROM activation_resend_requests
             WHERE usuario_id = :usuario_id AND estado = 'Pendiente de Reenvío'"
        );
        $pendingRequest->execute(['usuario_id' => $staffId]);
        assertTrue(
            (int)$pendingRequest->fetchColumn() === 1,
            'El reenvío debe quedar pendiente y las solicitudes duplicadas no deben generar avisos repetidos.'
        );
        $resendRequests->solicitar($staffEmail, '00000000T');
        $pendingRequest->execute(['usuario_id' => $staffId]);
        assertTrue(
            (int)$pendingRequest->fetchColumn() === 1,
            'La solicitud debe exigir que el correo y el identificador correspondan a la misma cuenta.'
        );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = [];
        $_POST = [
            'token' => $activationToken,
            'password' => 'PersonalTest123!',
            'password_confirmation' => 'PersonalTest123!',
        ];
        ob_start();
        (new ActivacionController())->activar();
        $activationHtml = (string)ob_get_clean();
        $activatedAccount = $db->prepare(
            'SELECT activo, password_hash FROM usuarios WHERE id = :id'
        );
        $activatedAccount->execute(['id' => $staffId]);
        $activated = $activatedAccount->fetch(PDO::FETCH_ASSOC);
        assertTrue(
            is_array($activated)
                && (int)$activated['activo'] === 1
                && password_verify('PersonalTest123!', $activated['password_hash'])
                && str_contains($activationHtml, 'Tu cuenta está activada.'),
            "La activación segura debe habilitar la cuenta de $staffRole y guardar la contraseña elegida."
        );
        $usedToken = $db->prepare(
            'SELECT used_at FROM staff_activation_tokens WHERE token_hash = :token_hash'
        );
        $usedToken->execute(['token_hash' => hash('sha256', $activationToken)]);
        assertTrue($usedToken->fetchColumn() !== null, 'El enlace de activación debe quedar consumido.');
    }

    $companyActivationEmail = "activation-company-$fixture@example.test";
    $companyActivationUserId = $userModel->crear(
        $companyActivationEmail,
        bin2hex(random_bytes(32)),
        'Empresa',
        false
    );
    $createdUsers[] = $companyActivationUserId;
    $companyActivationToken = bin2hex(random_bytes(32));
    $companyActivationInsert = $db->prepare(
        'INSERT INTO company_activation_tokens (usuario_id, token_hash, expires_at)
         VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
    );
    $companyActivationInsert->execute([
        'usuario_id' => $companyActivationUserId,
        'token_hash' => hash('sha256', $companyActivationToken),
    ]);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = [
        'token' => $companyActivationToken,
        'password' => 'EmpresaActivation123!',
        'password_confirmation' => 'EmpresaActivation123!',
    ];
    ob_start();
    (new ActivacionController())->activar();
    $companyActivationHtml = (string)ob_get_clean();
    $companyActivationAccount = $db->prepare(
        'SELECT activo, password_hash FROM usuarios WHERE id = :id'
    );
    $companyActivationAccount->execute(['id' => $companyActivationUserId]);
    $companyActivationResult = $companyActivationAccount->fetch(PDO::FETCH_ASSOC);
    $companyUsedToken = $db->prepare(
        'SELECT used_at FROM company_activation_tokens WHERE token_hash = :token_hash'
    );
    $companyUsedToken->execute(['token_hash' => hash('sha256', $companyActivationToken)]);
    assertTrue(
        is_array($companyActivationResult)
            && (int)$companyActivationResult['activo'] === 1
            && password_verify('EmpresaActivation123!', $companyActivationResult['password_hash'])
            && $companyUsedToken->fetchColumn() !== null
            && str_contains($companyActivationHtml, 'Tu cuenta está activada.'),
        'La activación de empresas debe guardar la contraseña definitiva, habilitar la cuenta y consumir el token.'
    );

    $companyRequestNumber = str_pad(
        (string)((hexdec(substr($fixture, 1, 6)) + 12345) % 10000000),
        7,
        '0',
        STR_PAD_LEFT
    );
    $companyRequestSum = 0;
    for ($index = 0; $index < 7; $index++) {
        $digit = (int)$companyRequestNumber[$index];
        if ($index % 2 === 0) {
            $doubled = $digit * 2;
            $companyRequestSum += intdiv($doubled, 10) + ($doubled % 10);
        } else {
            $companyRequestSum += $digit;
        }
    }
    $companyRequestNif = 'B' . $companyRequestNumber
        . (string)((10 - ($companyRequestSum % 10)) % 10);
    $companyRequestEmail = "resend-company-$fixture@example.test";
    $companyRequestUserId = $userModel->crear(
        $companyRequestEmail,
        bin2hex(random_bytes(32)),
        'Empresa',
        false
    );
    $createdUsers[] = $companyRequestUserId;
    $companyRequestProfile = $db->prepare(
        'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
         VALUES (:usuario_id, :nombre, :nif, :contacto)'
    );
    $companyRequestProfile->execute([
        'usuario_id' => $companyRequestUserId,
        'nombre' => "Empresa de reenvío $fixture",
        'nif' => $companyRequestNif,
        'contacto' => '',
    ]);
    $companyRequestToken = bin2hex(random_bytes(32));
    $companyRequestTokenInsert = $db->prepare(
        'INSERT INTO company_activation_tokens (usuario_id, token_hash, expires_at)
         VALUES (:usuario_id, :token_hash, DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1 HOUR))'
    );
    $companyRequestTokenInsert->execute([
        'usuario_id' => $companyRequestUserId,
        'token_hash' => hash('sha256', $companyRequestToken),
    ]);
    $resendRequests = new SolicitudReenvioActivacion($db);
    $resendRequests->solicitar($companyRequestEmail, $companyRequestNif);
    $companyPendingRequest = $db->prepare(
        "SELECT COUNT(*) FROM activation_resend_requests
         WHERE usuario_id = :usuario_id AND estado = 'Pendiente de Reenvío'"
    );
    $companyPendingRequest->execute(['usuario_id' => $companyRequestUserId]);
    $companyListedRequest = array_values(array_filter(
        $resendRequests->pendientes(),
        static fn(array $request): bool => (int)$request['usuario_id'] === $companyRequestUserId
    ))[0] ?? null;
    assertTrue(
        (int)$companyPendingRequest->fetchColumn() === 1
            && is_array($companyListedRequest)
            && $companyListedRequest['identificador_fiscal'] === $companyRequestNif
            && $companyListedRequest['nombre_completo'] === "Empresa de reenvío $fixture",
        'Las empresas deben poder solicitar reenvíos usando su NIF (Persona Jurídica), sin exponer su existencia.'
    );

    // Ticket 2 lifecycle coverage: expired-link UI, opaque mismatches, cooldown, and admin resend.
    $resendNifNumber = ($staffIdentifierBase + 10) % 100000000;
    $resendNif = str_pad((string)$resendNifNumber, 8, '0', STR_PAD_LEFT)
        . 'TRWAGMYFPDXBNJZSQVHLCKE'[$resendNifNumber % 23];
    $resendEmail = "resend-$fixture@example.test";
    $resendUserId = $userModel->crearCuentaStaff(
        $resendEmail,
        'Observador',
        $resendNif,
        'Reenvío',
        'Prueba'
    );
    $createdStaffUsers[] = $resendUserId;

    $expiredPlainToken = bin2hex(random_bytes(32));
    $oldPlainToken = bin2hex(random_bytes(32));
    $insertResendToken = $db->prepare(
        'INSERT INTO staff_activation_tokens (usuario_id, token_hash, expires_at)
         VALUES (:usuario_id, :token_hash, :expires_at)'
    );
    $insertResendToken->execute([
        'usuario_id' => $resendUserId,
        'token_hash' => hash('sha256', $expiredPlainToken),
        'expires_at' => date('Y-m-d H:i:s', time() - 3600),
    ]);
    $insertResendToken->execute([
        'usuario_id' => $resendUserId,
        'token_hash' => hash('sha256', $oldPlainToken),
        'expires_at' => date('Y-m-d H:i:s', time() + 86400),
    ]);

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['token' => $expiredPlainToken];
    ob_start();
    (new ActivacionController())->activar();
    $expiredActivationHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($expiredActivationHtml, 'El enlace ha caducado o no es válido.')
            && str_contains($expiredActivationHtml, 'Solicitar nuevo enlace al Centro')
            && !str_contains($expiredActivationHtml, 'name="token"'),
        'Un token expirado debe mostrar el error genérico y la opción de solicitud, sin formulario de activación.'
    );
    $_GET = ['token' => 'token-invalido'];
    ob_start();
    (new ActivacionController())->activar();
    $invalidActivationHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($invalidActivationHtml, 'El enlace ha caducado o no es válido.')
            && str_contains($invalidActivationHtml, 'name="email"')
            && str_contains($invalidActivationHtml, 'name="identificador_fiscal"'),
        'Un token malformado debe tener el mismo manejo genérico y ofrecer la solicitud con correo y NIF.'
    );
    $_GET = ['solicitud' => '1'];
    ob_start();
    (new ActivacionController())->activar();
    $requestConfirmationHtml = (string)ob_get_clean();
    assertTrue(
        str_contains($requestConfirmationHtml, 'Si los datos corresponden a una cuenta pendiente de activación')
            && !str_contains($requestConfirmationHtml, 'no existe'),
        'La confirmación pública de una solicitud no debe revelar si la cuenta existe.'
    );
    $_GET = [];

    $resendRequests = new SolicitudReenvioActivacion($db);
    $resendRequests->solicitar($resendEmail, $resendNif);
    $resendRequests->solicitar($resendEmail, $resendNif);
    $resendRequests->solicitar('no-existe-' . $fixture . '@example.test', $resendNif);
    $requestCount = $db->prepare(
        'SELECT COUNT(*) FROM activation_resend_requests WHERE usuario_id = :usuario_id'
    );
    $requestCount->execute(['usuario_id' => $resendUserId]);
    assertTrue(
        (int)$requestCount->fetchColumn() === 1,
        'Los datos inexistentes no deben crear solicitudes y los duplicados pendientes deben quedar deduplicados.'
    );

    $markOldRequest = $db->prepare(
        "UPDATE activation_resend_requests
         SET estado = 'Reenviada', resuelta = CURRENT_TIMESTAMP,
             creada = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 1439 MINUTE)
         WHERE usuario_id = :usuario_id"
    );
    $markOldRequest->execute(['usuario_id' => $resendUserId]);
    $resendRequests->solicitar($resendEmail, $resendNif);
    $requestCount->execute(['usuario_id' => $resendUserId]);
    assertTrue(
        (int)$requestCount->fetchColumn() === 1,
        'No debe admitirse una nueva solicitud antes de que transcurran 24 horas.'
    );
    $ageRequest = $db->prepare(
        'UPDATE activation_resend_requests
         SET creada = DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) - INTERVAL 1 SECOND
         WHERE usuario_id = :usuario_id'
    );
    $ageRequest->execute(['usuario_id' => $resendUserId]);
    $resendRequests->solicitar($resendEmail, $resendNif);
    $requestCount->execute(['usuario_id' => $resendUserId]);
    assertTrue(
        (int)$requestCount->fetchColumn() === 2,
        'Debe permitirse una nueva solicitud una vez superado el límite de 24 horas.'
    );
    $pendingRequestQuery = $db->prepare(
        "SELECT id FROM activation_resend_requests
         WHERE usuario_id = :usuario_id AND estado = 'Pendiente de Reenvío'
         ORDER BY id DESC LIMIT 1"
    );
    $pendingRequestQuery->execute(['usuario_id' => $resendUserId]);
    $resendRequestId = (int)$pendingRequestQuery->fetchColumn();
    assertTrue($resendRequestId > 0, 'Debe existir una solicitud pendiente para probar la aprobación.');

    $originalMailEnvironment = [];
    foreach (['FCT_MAIL_TRANSPORT', 'FCT_APP_URL'] as $environmentKey) {
        $originalMailEnvironment[$environmentKey] = getenv($environmentKey);
    }
    putenv('FCT_MAIL_TRANSPORT=invalid-test-transport');
    putenv('FCT_APP_URL=https://test.invalid');
    $mailFailureObserved = false;
    try {
        $resendRequests->aprobar($resendRequestId, $adminId, 'https://test.invalid');
    } catch (Throwable $exception) {
        $mailFailureObserved = true;
    } finally {
        foreach ($originalMailEnvironment as $environmentKey => $environmentValue) {
            putenv($environmentValue === false ? $environmentKey : $environmentKey . '=' . $environmentValue);
        }
    }
    $oldTokenState = $db->prepare(
        'SELECT used_at FROM staff_activation_tokens
         WHERE token_hash = :token_hash'
    );
    $oldTokenState->execute(['token_hash' => hash('sha256', $oldPlainToken)]);
    $oldTokenUsedAt = $oldTokenState->fetchColumn();
    $unusedTokenCount = $db->prepare(
        'SELECT COUNT(*) FROM staff_activation_tokens
         WHERE usuario_id = :usuario_id AND used_at IS NULL'
    );
    $unusedTokenCount->execute(['usuario_id' => $resendUserId]);
    $requestStatus = $db->prepare(
        'SELECT estado FROM activation_resend_requests WHERE id = :id'
    );
    $requestStatus->execute(['id' => $resendRequestId]);
    assertTrue(
        $mailFailureObserved
            && $oldTokenUsedAt === null
            && (int)$unusedTokenCount->fetchColumn() === 2
            && $requestStatus->fetchColumn() === 'Pendiente de Reenvío',
        'Si falla el correo, la aprobación debe revertirse: solicitud pendiente y tokens originales sin invalidar.'
    );

    // Exercise the successful mail path only when the configured sink is explicitly local Mailpit.
    $localMailpitReady = getenv('FCT_MAIL_TRANSPORT') === 'mailpit'
        && getenv('FCT_MAIL_HOST') === 'mailpit'
        && (int)getenv('FCT_MAIL_PORT') === 1025
        && getenv('FCT_MAIL_ENCRYPTION') === 'none'
        && is_string(getenv('FCT_MAIL_FROM'))
        && filter_var(getenv('FCT_MAIL_FROM'), FILTER_VALIDATE_EMAIL) !== false;
    if ($localMailpitReady) {
        $mailpitSocket = @fsockopen('mailpit', 1025, $mailpitErrno, $mailpitError, 1);
        $localMailpitReady = is_resource($mailpitSocket);
        if (is_resource($mailpitSocket)) {
            fclose($mailpitSocket);
        }
    }
    if ($localMailpitReady) {
        $originalAppEnvironment = getenv('APP_ENV');
        putenv('APP_ENV=development');
        try {
            $resendRequests->aprobar($resendRequestId, $adminId, 'http://localhost:8080');
        } finally {
            putenv($originalAppEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $originalAppEnvironment);
        }
        $tokenLifecycle = $db->prepare(
            'SELECT token_hash, used_at, expires_at,
                    expires_at > DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 23 HOUR) AS expires_after_23h,
                    expires_at <= DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR) AS expires_by_24h
             FROM staff_activation_tokens WHERE usuario_id = :usuario_id ORDER BY id'
        );
        $tokenLifecycle->execute(['usuario_id' => $resendUserId]);
        $lifecycleTokens = $tokenLifecycle->fetchAll(PDO::FETCH_ASSOC);
        $requestStatus->execute(['id' => $resendRequestId]);
        assertTrue(
            count($lifecycleTokens) === 3
                && $lifecycleTokens[0]['used_at'] !== null
                && $lifecycleTokens[1]['used_at'] !== null
                && $lifecycleTokens[2]['used_at'] === null
                && $lifecycleTokens[2]['token_hash'] !== hash('sha256', $oldPlainToken)
                && (int)$lifecycleTokens[2]['expires_after_23h'] === 1
                && (int)$lifecycleTokens[2]['expires_by_24h'] === 1
                && $requestStatus->fetchColumn() === 'Reenviada',
            'La aprobación debe consumir los enlaces anteriores y crear un token distinto válido por casi 24 horas.'
        );
        $activationAuditRequestId = $resendRequestId;
    }

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
    $auditLog = $db->prepare(
        "SELECT id_usuario_admin, nombre_completo, accion, entidad_afectada,
                id_registro_modificado, ip_address, evento, `timestamp`
         FROM audit_logs
         WHERE id_usuario_admin = :actor AND evento = 'prueba.auditada'
         ORDER BY id DESC LIMIT 1"
    );
    $auditLog->execute(['actor' => $createdUsers[6]]);
    $auditLogRow = $auditLog->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($auditLogRow)
            && (int)$auditLogRow['id_usuario_admin'] === $createdUsers[6]
            && $auditLogRow['nombre_completo'] !== ''
            && $auditLogRow['accion'] === 'edición'
            && $auditLogRow['entidad_afectada'] === 'usuarios'
            && (int)$auditLogRow['id_registro_modificado'] === $createdUsers[6]
            && $auditLogRow['ip_address'] !== ''
            && $auditLogRow['timestamp'] !== null,
        'Toda acción auditada debe copiar actor, nombre, acción, entidad, registro, IP y fecha en audit_logs.'
    );
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
    $deletionAuditLog = $db->prepare(
        "SELECT nombre_completo, accion, ip_address, `timestamp`
         FROM audit_logs
         WHERE id_usuario_admin = :actor
           AND entidad_afectada = 'usuarios'
           AND id_registro_modificado = :entidad
         ORDER BY id DESC LIMIT 1"
    );
    $deletionAuditLog->execute(['actor' => $adminId, 'entidad' => $createdUsers[5]]);
    $deletionAuditLogRow = $deletionAuditLog->fetch(PDO::FETCH_ASSOC);
    assertTrue(
        is_array($deletionAuditLogRow)
            && $deletionAuditLogRow['nombre_completo'] !== ''
            && $deletionAuditLogRow['accion'] === 'borrado'
            && $deletionAuditLogRow['ip_address'] !== ''
            && $deletionAuditLogRow['timestamp'] !== null,
        'El borrado debe dejar un registro completo aunque la cuenta modificada ya no exista.'
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
    $routes = require __DIR__ . '/../routes/web.php';
    assertTrue(!isset($routes['registro-alumno']), 'No debe existir una ruta pública de alta de alumnos.');
    assertTrue(
        ($routes['alumnos-centro']['roles'] ?? null) === ['Administrador', 'Coordinador']
            && ($routes['alumnos-centro']['method'] ?? null) === ['GET', 'POST'],
        'La creación de alumnos debe quedar limitada al personal autorizado.'
    );
    assertTrue(
        ($routes['activar-cuenta']['roles'] ?? null) === []
            && ($routes['activar-cuenta']['method'] ?? null) === ['GET', 'POST'],
        'La activación debe aceptar enlaces públicos y proteger el cambio de contraseña mediante CSRF.'
    );
    assertTrue(
        ($routes['solicitar-reenvio-activacion']['roles'] ?? null) === []
            && ($routes['solicitar-reenvio-activacion']['method'] ?? null) === 'POST'
            && ($routes['solicitudes-activacion']['roles'] ?? null) === ['Administrador']
            && ($routes['aprobar-reenvio-activacion']['roles'] ?? null) === ['Administrador']
            && ($routes['aprobar-reenvio-activacion']['method'] ?? null) === 'POST',
        'Las solicitudes de reenvío deben ser públicas con respuesta genérica y aprobarse solo por Administrador mediante POST.'
    );

    echo "OK: migraciones, perfiles, validación española, convenios, ofertas, matching, disponibilidad, calificaciones, usuarios y CSRF.\n";
} finally {
    if ($createdAgreementId !== null) {
        $stmt = $db->prepare('DELETE FROM auditoria WHERE entidad = :entidad AND entidad_id = :id');
        $stmt->execute(['entidad' => 'convenios', 'id' => $createdAgreementId]);
        $stmt = $db->prepare(
            'DELETE FROM audit_logs
             WHERE entidad_afectada = :entidad AND id_registro_modificado = :id'
        );
        $stmt->execute(['entidad' => 'convenios', 'id' => $createdAgreementId]);
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
    if ($createdAdminStudentProfileId !== null) {
        $stmt = $db->prepare('DELETE FROM auditoria WHERE entidad = :entidad AND entidad_id = :id');
        $stmt->execute(['entidad' => 'alumnos', 'id' => $createdAdminStudentProfileId]);
        $stmt = $db->prepare('DELETE FROM alumnos WHERE id = :id');
        $stmt->execute(['id' => $createdAdminStudentProfileId]);
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
    if ($activationAuditRequestId !== null) {
        $stmt = $db->prepare('DELETE FROM auditoria WHERE entidad = :entidad AND entidad_id = :id');
        $stmt->execute(['entidad' => 'activation_resend_requests', 'id' => $activationAuditRequestId]);
        $stmt = $db->prepare('DELETE FROM audit_logs WHERE entidad_afectada = :entidad AND id_registro_modificado = :id');
        $stmt->execute(['entidad' => 'activation_resend_requests', 'id' => $activationAuditRequestId]);
    }
    if ($createdStaffUsers !== []) {
        $stmt = $db->prepare('DELETE FROM coordinadores WHERE usuario_id = :id');
        foreach ($createdStaffUsers as $userId) {
            $stmt->execute(['id' => $userId]);
        }
    }
    if ($createdUsers !== [] || $createdStaffUsers !== [] || $createdAdminStudentUserId !== null) {
        $stmt = $db->prepare('DELETE FROM usuarios WHERE id = :id');
        foreach ($createdUsers as $userId) {
            $stmt->execute(['id' => $userId]);
        }
        foreach ($createdStaffUsers as $userId) {
            $stmt->execute(['id' => $userId]);
        }
        if ($createdAdminStudentUserId !== null) {
            $stmt = $db->prepare('DELETE FROM usuarios WHERE id = :id');
            $stmt->execute(['id' => $createdAdminStudentUserId]);
        }
    }
    if ($createdCycleId !== null) {
        $stmt = $db->prepare('DELETE FROM especialidades WHERE id = :id');
        $stmt->execute(['id' => $createdCycleId]);
    }
}
