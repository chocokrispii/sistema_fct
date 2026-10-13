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
$requiredTables = ['usuarios', 'coordinadores', 'empresas', 'alumnos', 'especialidades'];
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

$cycleId = (int)$db->query('SELECT id FROM especialidades ORDER BY id LIMIT 1')->fetchColumn();
if ($cycleId < 1) {
    fwrite(STDERR, "Crea al menos un ciclo formativo antes de instalar las cuentas demo.\n");
    exit(2);
}

$definitions = [
    ['role' => 'Administrador', 'email' => 'demo.admin@fct.local'],
    ['role' => 'Coordinador', 'email' => 'demo.coordinador@fct.local'],
    ['role' => 'Observador', 'email' => 'demo.observador@fct.local'],
    ['role' => 'Empresa', 'email' => 'demo.empresa@fct.local'],
    ['role' => 'Alumno', 'email' => 'demo.alumno@fct.local'],
];
$created = [];
$existing = [];
$resetPasswords = getenv('FCT_DEMO_SEED_RESET_PASSWORDS') === '1';
$db->beginTransaction();
try {
    $find = $db->prepare('SELECT id, rol FROM usuarios WHERE email = :email LIMIT 1');
    $insertUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :rol)'
    );
    $updatePassword = $db->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
    foreach ($definitions as $definition) {
        $find->execute(['email' => $definition['email']]);
        $existingUser = $find->fetch(PDO::FETCH_ASSOC);
        $passwordEnvironmentKey = 'FCT_DEMO_PASSWORD_' . strtoupper($definition['role']);
        $configuredPassword = getenv($passwordEnvironmentKey);
        if (
            is_string($configuredPassword)
            && $configuredPassword !== ''
            && (strlen($configuredPassword) < 12 || strlen($configuredPassword) > 72)
        ) {
            throw new RuntimeException("$passwordEnvironmentKey debe contener entre 12 y 72 caracteres.");
        }

        if ($existingUser !== false) {
            if ($existingUser['rol'] !== $definition['role']) {
                throw new RuntimeException('El rol de una cuenta demo existente no coincide con la definición esperada.');
            }
            if ($resetPasswords && is_string($configuredPassword) && $configuredPassword !== '') {
                $updatePassword->execute([
                    'hash' => password_hash($configuredPassword, PASSWORD_DEFAULT),
                    'id' => $existingUser['id'],
                ]);
                $created[] = ['email' => $definition['email'], 'role' => $definition['role'], 'password' => null];
            } else {
                $existing[] = $definition['email'];
            }
            continue;
        }

        $password = is_string($configuredPassword) && $configuredPassword !== ''
            ? $configuredPassword
            : 'Fct-' . bin2hex(random_bytes(12)) . '!';
        $insertUser->execute([
            'email' => $definition['email'],
            'hash' => password_hash($password, PASSWORD_DEFAULT),
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
                'ciclo' => $cycleId,
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
        $created[] = [
            'email' => $definition['email'],
            'role' => $definition['role'],
            'password' => is_string($configuredPassword) && $configuredPassword !== '' ? null : $password,
        ];
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

if ($created !== []) {
    echo "Cuentas demo creadas o contraseñas actualizadas. Las contraseñas proporcionadas por entorno no se muestran.\n";
    foreach ($created as $account) {
        echo $account['role'] . "\t" . $account['email'];
        if (is_string($account['password'])) {
            echo "\t" . $account['password'];
        }
        echo "\n";
    }
}
if ($existing !== []) {
    echo "Cuentas ya existentes (no se cambió su contraseña): " . implode(', ', $existing) . "\n";
}
echo "Solo para desarrollo local. Elimina estas cuentas o cambia sus contraseñas antes de cualquier despliegue.\n";
