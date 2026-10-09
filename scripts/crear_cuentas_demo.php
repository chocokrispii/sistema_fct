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
if (!in_array($dbHost, ['localhost', '127.0.0.1'], true) || $dbName !== 'sistema_fct') {
    fwrite(STDERR, "Por seguridad, las cuentas demo solo se crean en sistema_fct alojada en localhost.\n");
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
$db->beginTransaction();
try {
    $find = $db->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
    $insertUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :rol)'
    );
    foreach ($definitions as $definition) {
        $find->execute(['email' => $definition['email']]);
        if ($find->fetchColumn() !== false) {
            $existing[] = $definition['email'];
            continue;
        }

        $password = 'Fct-' . bin2hex(random_bytes(12)) . '!';
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
            $profile = $db->prepare(
                'INSERT INTO alumnos
                    (usuario_id, nombre, apellidos, fecha_nacimiento, dni,
                     numero_seguridad_social, correo, especialidad_id)
                 VALUES (:usuario, :nombre, :apellidos, :nacimiento, :dni, :nss, :correo, :ciclo)'
            );
            $profile->execute([
                'usuario' => $userId,
                'nombre' => 'Alumno',
                'apellidos' => 'Demo Local',
                'nacimiento' => '2000-01-01',
                'dni' => 'D' . strtoupper(bin2hex(random_bytes(5))),
                'nss' => 'D' . strtoupper(bin2hex(random_bytes(5))),
                'correo' => $definition['email'],
                'ciclo' => $cycleId,
            ]);
        }
        $created[] = ['email' => $definition['email'], 'role' => $definition['role'], 'password' => $password];
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
    echo "Cuentas locales creadas. Guarda estas contraseñas: no se vuelven a mostrar ni se persisten en texto claro.\n";
    foreach ($created as $account) {
        echo $account['role'] . "\t" . $account['email'] . "\t" . $account['password'] . "\n";
    }
}
if ($existing !== []) {
    echo "Cuentas ya existentes (no se cambió su contraseña): " . implode(', ', $existing) . "\n";
}
echo "Solo para localhost/desarrollo. Elimina estas cuentas o cambia sus contraseñas antes de cualquier despliegue.\n";
