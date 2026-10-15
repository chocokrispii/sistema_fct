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
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/AlumnoController.php';
require_once __DIR__ . '/../app/Controllers/CalificacionController.php';
require_once __DIR__ . '/../app/Controllers/DisponibilidadController.php';
require_once __DIR__ . '/../app/Controllers/UsuarioAdminController.php';
require_once __DIR__ . '/../app/Models/Oferta.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Alumno.php';
require_once __DIR__ . '/../app/Models/Disponibilidad.php';
require_once __DIR__ . '/../app/Models/Calificacion.php';
require_once __DIR__ . '/../app/Models/Convenio.php';
require_once __DIR__ . '/../app/Models/Usuario.php';
require_once __DIR__ . '/../app/Core/SpanishDataValidator.php';
require_once __DIR__ . '/../app/Controllers/ConvenioController.php';
require_once __DIR__ . '/../app/Controllers/ConsultaController.php';

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
    'nota' => ['table' => 'calificaciones_fct', 'column' => 'nota'],
    'codigo_postal' => ['table' => 'alumnos', 'column' => 'codigo_postal'],
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
$createdPaginationOffers = [];
$createdAssignmentId = null;
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
            && str_contains($profileHtml, 'value="28013"'),
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
            && str_contains($invalidProfileHtml, 'letra de control válida'),
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
    $studentModel->actualizarPerfil(
        $createdUsers[2],
        $emails[2],
        'Alumno',
        'Apellidos Legado',
        '2010-01-01',
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
        "INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
         VALUES (:alumno, '2027-03-01', '2027-06-30', 'Indiferente')"
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
            'dia_semana' => $index + 1,
            'hora_inicio' => $index === 0 ? '08:00:00' : '15:00:00',
            'hora_fin' => $index === 0 ? '14:00:00' : '19:00:00',
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
    assertTrue(
        is_string($availabilityHtml)
            && str_contains($availabilityHtml, 'Disponibilidad semanal')
            && str_contains($availabilityHtml, 'Lunes')
            && str_contains($availabilityHtml, '08:00')
            && str_contains($availabilityHtml, 'step="600"'),
        'El formulario de disponibilidad debe mostrar los días y franjas guardados.'
    );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = [
        'fecha_inicio' => '2027-04-01',
        'fecha_fin' => '2027-06-30',
        'turno' => 'Indiferente',
        'horarios' => [
            '1' => ['activo' => '1', 'hora_inicio' => '08:15', 'hora_fin' => '13:50'],
        ],
    ];
    ob_start();
    (new DisponibilidadController($db))->gestionar();
    $invalidTimeHtml = ob_get_clean();
    assertTrue(
        is_string($invalidTimeHtml) && str_contains($invalidTimeHtml, 'franja horaria válida'),
        'El servidor debe rechazar horas que no respeten intervalos de diez minutos.'
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
         VALUES (:empresa, :especialidad, :numero, '2026-01-01', '2027-01-01', '2027-12-31', 'test-placeholder.pdf', 'Pendiente')"
    );
    $agreement->execute([
        'empresa' => $createdCompany,
        'especialidad' => $cycleId,
        'numero' => "AG-$fixture",
    ]);
    $agreementId = (int)$db->lastInsertId();
    assertTrue(
        (new Convenio($db))->resolver($agreementId, 'Aprobado', '', $createdUsers[3]),
        'Un convenio pendiente debe poder aprobarse.'
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

    $offer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, 1, '2027-03-15', '2027-03-15', 'M', 'Pendiente')"
    );
    $offer->execute(['empresa' => $createdCompany, 'ciclo' => $cycleId]);
    $createdOffer = (int)$db->lastInsertId();

    $matching = new Oferta($db);
    assertTrue(
        $matching->resolver($createdOffer, 'Aprobada', '', $createdUsers[3]),
        'Una oferta pendiente debe poder aprobarse.'
    );
    $candidates = $matching->obtenerCandidatos($createdOffer);
    assertTrue(
        count($candidates) === 1 && (int)$candidates[0]['alumno_id'] === $createdStudents[0],
        'El motor de compatibilidad debe excluir alumnos cuyo horario semanal no coincide con el turno.'
    );
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

    $assignments = new Asignacion($db);
    $assignments->crear($createdOffer, $createdStudents[0], $createdUsers[3], 'Tutor de empresa');
    $assignmentId = (int)$db->query(
        'SELECT id FROM asignaciones_fct WHERE oferta_id = ' . $createdOffer . ' AND alumno_id = ' . $createdStudents[0]
    )->fetchColumn();
    $createdAssignmentId = $assignmentId;
    $availabilityModel->reemplazar(
        $createdUsers[2],
        '2027-03-01',
        '2027-06-30',
        'M',
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
            && !isset($matching->obtenerCandidatosVigentes()[$createdOffer]),
        'El matching agrupado debe reflejar plazas ocupadas y excluir ofertas sin capacidad.'
    );
    $paginationOffer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno, estado)
         VALUES (:empresa, :ciclo, 1, '2027-03-15', '2027-03-15', 'M', 'Aprobada')"
    );
    for ($index = 0; $index < 51; $index++) {
        $paginationOffer->execute(['empresa' => $createdCompany, 'ciclo' => $cycleId]);
        $createdPaginationOffers[] = (int)$db->lastInsertId();
    }
    $_GET = [
        'especialidad_id' => (string)$cycleId,
        'estado' => 'Aprobada',
        'desde' => '2027-03-15',
        'hasta' => '2027-03-15',
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
            && str_contains($consultationPageTwo, 'name="desde" value="2027-03-15"'),
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
        '2027-04-01',
        '2027-06-30',
        'T',
        [['dia_semana' => 2, 'hora_inicio' => '15:00:00', 'hora_fin' => '19:00:00']]
    );
    $updatedAvailability = $availabilityModel->obtenerPorUsuario($createdUsers[1]);
    $updatedWeekly = $availabilityModel->obtenerHorarioSemanalPorUsuario($createdUsers[1]);
    assertTrue(
        count($updatedAvailability) === 1
            && $updatedAvailability[0]['fecha_inicio'] === '2027-04-01'
            && $updatedAvailability[0]['turno'] === 'T'
            && count($updatedWeekly) === 1
            && (int)$updatedWeekly[0]['dia_semana'] === 2,
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
            && str_contains($adminViewHtml, '¿Estás seguro?')
            && str_contains($adminViewHtml, 'No, volver.')
            && str_contains($adminViewHtml, 'Sí, eliminar')
            && str_contains($adminViewHtml, 'Buscar por correo o nombre')
            && str_contains($adminViewHtml, 'Filtrar cuentas')
            && str_contains($adminViewHtml, 'Cuenta actual')
            && !str_contains($adminViewHtml, 'aria-label="Desactivar cuenta de ' . htmlspecialchars($emails[4], ENT_QUOTES, 'UTF-8') . '"'),
        'La administración debe mostrar el nombre, las acciones y los controles de búsqueda de cuentas.'
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

    echo "OK: migraciones, perfiles, validación española, convenios, ofertas, matching, disponibilidad, calificaciones, usuarios y CSRF.\n";
} finally {
    foreach ($createdPaginationOffers as $paginationOfferId) {
        $stmt = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :offer');
        $stmt->execute(['offer' => $paginationOfferId]);
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
