<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este instalador solo puede ejecutarse desde PHP CLI.\n");
    exit(2);
}
if (getenv('FCT_ENABLE_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Activa FCT_ENABLE_DEMO_SEED=1 para confirmar que se trata de una base local de desarrollo.\n");
    exit(2);
}
$dbHost = getenv('FCT_DB_HOST') ?: 'localhost';
$dbName = getenv('FCT_DB_NAME') ?: 'sistema_fct';
if (
    $dbName !== 'sistema_fct'
    || (!in_array($dbHost, ['localhost', '127.0.0.1'], true)
        && !($dbHost === 'db' && getenv('FCT_DEMO_SEED_ALLOW_DOCKER') === '1'))
) {
    fwrite(STDERR, "Por seguridad, limita las cuentas demo a sistema_fct local o habilita explícitamente el servicio Docker db.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
$db = getPDOConnection();
$requiredTables = [
    'usuarios',
    'coordinadores',
    'empresas',
    'alumnos',
    'especialidades',
    'alumno_disponibilidad',
    'alumno_disponibilidad_semanal',
];
foreach ($requiredTables as $table) {
    $check = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $check->execute(['table' => $table]);
    if ((int)$check->fetchColumn() !== 1) {
        fwrite(STDERR, "Falta la tabla requerida $table; importa el esquema y ejecuta las migraciones primero.\n");
        exit(2);
    }
}

$definitions = [
    ['role' => 'Administrador', 'email' => 'demo.admin@fct.local'],
    ['role' => 'Coordinador', 'email' => 'demo.coordinador@fct.local'],
    ['role' => 'Observador', 'email' => 'demo.observador@fct.local'],
    ['role' => 'Empresa', 'email' => 'demo.empresa@fct.local'],
    ['role' => 'Alumno', 'email' => 'demo.alumno@fct.local'],
];
$accounts = [];
foreach ($definitions as $definition) {
    $accounts[] = [
        ...$definition,
        'password' => 'Fct-' . bin2hex(random_bytes(12)) . '!',
    ];
}

$db->beginTransaction();
try {
    $cycles = [
        'DAW' => 'Desarrollo de Aplicaciones Web (DAW)',
        'DAM' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)',
    ];
    $cycleIds = [];
    $findCycle = $db->prepare(
        'SELECT id FROM especialidades WHERE nombre_especialidad = :nombre LIMIT 1'
    );
    $createCycle = $db->prepare(
        'INSERT INTO especialidades (nombre_especialidad) VALUES (:nombre)'
    );
    foreach ($cycles as $code => $name) {
        $findCycle->execute(['nombre' => $name]);
        $cycleId = $findCycle->fetchColumn();
        if ($cycleId === false) {
            $createCycle->execute(['nombre' => $name]);
            $cycleId = $db->lastInsertId();
        }
        $cycleIds[$code] = (int)$cycleId;
    }

    $find = $db->prepare('SELECT id, rol FROM usuarios WHERE email = :email LIMIT 1');
    $insertUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :rol)'
    );
    $updatePassword = $db->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
    foreach ($accounts as $account) {
        $definition = $account;
        $find->execute(['email' => $definition['email']]);
        $existingUser = $find->fetch(PDO::FETCH_ASSOC);

        if ($existingUser !== false) {
            if ($existingUser['rol'] !== $definition['role']) {
                throw new RuntimeException('El rol de una cuenta demo existente no coincide con la definición esperada.');
            }
            $updatePassword->execute([
                'hash' => password_hash($account['password'], PASSWORD_DEFAULT),
                'id' => $existingUser['id'],
            ]);
            continue;
        }

        $insertUser->execute([
            'email' => $definition['email'],
            'hash' => password_hash($account['password'], PASSWORD_DEFAULT),
            'rol' => $definition['role'],
        ]);
        $userId = (int)$db->lastInsertId();

        if ($definition['role'] === 'Coordinador') {
            $profile = $db->prepare(
                'INSERT INTO coordinadores (usuario_id, nombre, apellidos)
                 VALUES (:usuario, :nombre, :apellidos)'
            );
            $profile->execute(['usuario' => $userId, 'nombre' => 'Cuenta', 'apellidos' => 'Demo Coordinación']);
        } elseif ($definition['role'] === 'Empresa') {
            $profile = $db->prepare(
                'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
                 VALUES (:usuario, :nombre, :cif, :contacto)'
            );
            $profile->execute([
                'usuario' => $userId,
                'nombre' => 'Empresa Demo Local',
                'cif' => 'B' . strtoupper(bin2hex(random_bytes(4))),
                'contacto' => 'Contacto de prueba',
            ]);
        } elseif ($definition['role'] === 'Alumno') {
            $dniNumber = random_int(0, 99999999);
            $dni = str_pad((string)$dniNumber, 8, '0', STR_PAD_LEFT)
                . 'TRWAGMYFPDXBNJZSQVHLCKE'[$dniNumber % 23];
            $profile = $db->prepare(
                'INSERT INTO alumnos
                    (usuario_id, nombre, apellidos, fecha_nacimiento, dni,
                     numero_seguridad_social, codigo_postal, correo, especialidad_id)
                 VALUES (:usuario, :nombre, :apellidos, :nacimiento, :dni, :nss, :codigo_postal, :correo, :ciclo)'
            );
            $profile->execute([
                'usuario' => $userId,
                'nombre' => 'Alumno',
                'apellidos' => 'Demo Local',
                'nacimiento' => '2000-01-01',
                'dni' => $dni,
                'nss' => 'D' . strtoupper(bin2hex(random_bytes(5))),
                'codigo_postal' => '28013',
                'correo' => $definition['email'],
                'ciclo' => $cycleIds['DAW'],
            ]);
            $contact = $db->prepare(
                'INSERT INTO direcciones_telefonos (alumno_id, telefono, direccion_completa)
                 VALUES (:alumno, :telefono, :direccion)'
            );
            $contact->execute([
                'alumno' => (int)$db->lastInsertId(),
                'telefono' => '612345678',
                'direccion' => 'Calle Demo, 1, Madrid',
            ]);
        }
    }

    $studentUser = $db->prepare(
        'SELECT a.id
         FROM usuarios u
         INNER JOIN alumnos a ON a.usuario_id = u.id
         WHERE u.email = :email AND u.rol = :rol'
    );
    $studentUser->execute(['email' => 'demo.alumno@fct.local', 'rol' => 'Alumno']);
    $studentId = $studentUser->fetchColumn();
    if ($studentId === false) {
        throw new RuntimeException('No se encontró el expediente de la cuenta demo del alumno.');
    }

    $availabilityStart = new DateTimeImmutable('today');
    $availabilityEnd = $availabilityStart->modify('+1 year');
    $deleteAvailability = $db->prepare(
        'DELETE FROM alumno_disponibilidad WHERE alumno_id = :alumno_id'
    );
    $deleteAvailability->execute(['alumno_id' => $studentId]);

    $insertAvailability = $db->prepare(
        'INSERT INTO alumno_disponibilidad
            (alumno_id, fecha_inicio, fecha_fin, turno, es_indiferente)
         VALUES (:alumno_id, :fecha_inicio, :fecha_fin, :turno, FALSE)'
    );
    $insertAvailability->execute([
        'alumno_id' => $studentId,
        'fecha_inicio' => $availabilityStart->format('Y-m-d'),
        'fecha_fin' => $availabilityEnd->format('Y-m-d'),
        'turno' => 'Indiferente',
    ]);
    $availabilityId = (int)$db->lastInsertId();

    $insertWeeklyAvailability = $db->prepare(
        'INSERT INTO alumno_disponibilidad_semanal
            (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
         VALUES (:disponibilidad_id, :dia_semana, :hora_inicio, :hora_fin)'
    );
    foreach (range(1, 5) as $weekday) {
        $insertWeeklyAvailability->execute([
            'disponibilidad_id' => $availabilityId,
            'dia_semana' => $weekday,
            'hora_inicio' => '09:00:00',
            'hora_fin' => '17:00:00',
        ]);
    }

    $db->commit();
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "No se pudieron crear las cuentas demo; no se conservaron cambios parciales.\n");
    error_log('Falló la creación de cuentas demo: ' . $exception->getMessage());
    exit(1);
}

echo "Cuentas demo creadas o renovadas. La ejecución ha cambiado las contraseñas anteriores:\n";
foreach ($accounts as $account) {
    echo $account['role'] . "\t" . $account['email'] . "\t" . $account['password'] . "\n";
}
echo "Guarda estas contraseñas ahora: no se almacenan en texto claro y no se volverán a mostrar.\n";
echo "Solo para desarrollo local; nunca ejecutes este comando en producción.\n";
