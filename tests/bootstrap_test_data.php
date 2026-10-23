<?php

declare(strict_types=1);

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (
    !is_string($testDatabase)
    || !preg_match('/^fct_test_[a-zA-Z0-9_]+$/', $testDatabase)
    || getenv('FCT_DB_NAME') !== $testDatabase
) {
    fwrite(STDERR, "Fixture bootstrap requires the same disposable fct_test_* database in FCT_DB_NAME and FCT_TEST_DB_NAME.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';

$db = getPDOConnection();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== $testDatabase) {
    fwrite(STDERR, "Fixture bootstrap did not connect to the requested test database.\n");
    exit(2);
}

$cycle = $db->prepare(
    'SELECT id FROM especialidades
     WHERE nombre_especialidad = :nombre
     LIMIT 1'
);
$cycle->execute(['nombre' => 'Desarrollo de Aplicaciones Multiplataforma (DAM)']);
$cycleId = $cycle->fetchColumn();
if ($cycleId === false) {
    fwrite(STDERR, "The DAM migration fixture is missing; run the database migrations first.\n");
    exit(1);
}

$users = [
    ['email' => 'admin@centro.com', 'role' => 'Administrador'],
    ['email' => 'coordinador@centro.com', 'role' => 'Coordinador'],
    ['email' => 'empresa.tech@gmail.com', 'role' => 'Empresa'],
    ['email' => 'alumno.menor@alumno.com', 'role' => 'Alumno'],
    ['email' => 'alumno.mayor@alumno.com', 'role' => 'Alumno'],
];
$studentProfiles = [
    [
        'user_email' => 'alumno.menor@alumno.com',
        'name' => 'Lucas',
        'surname' => 'Pérez Ruiz',
        'birth_date' => '2009-01-01',
        'dni' => '12345678Z',
        'social_security' => 'TEST-SSN-00000001',
        'email' => 'lucas@correo.com',
    ],
    [
        'user_email' => 'alumno.mayor@alumno.com',
        'name' => 'Sofía',
        'surname' => 'García López',
        'birth_date' => '2000-01-01',
        'dni' => '87654321X',
        'social_security' => 'TEST-SSN-00000002',
        'email' => 'sofia@correo.com',
    ],
];

$findUser = $db->prepare('SELECT id, rol FROM usuarios WHERE email = :email LIMIT 1');
$insertUser = $db->prepare(
    'INSERT INTO usuarios (email, password_hash, rol)
     VALUES (:email, :password_hash, :rol)'
);
$resetPassword = $db->prepare(
    'UPDATE usuarios SET password_hash = :password_hash WHERE id = :id'
);
$findStudent = $db->prepare('SELECT id FROM alumnos WHERE correo = :email LIMIT 1');
$insertStudent = $db->prepare(
    'INSERT INTO alumnos
        (usuario_id, nombre, apellidos, fecha_nacimiento, dni, numero_seguridad_social,
         codigo_postal, correo, especialidad_id)
     VALUES
        (:usuario_id, :nombre, :apellidos, :fecha_nacimiento, :dni, :numero_seguridad_social,
         :codigo_postal, :correo, :especialidad_id)'
);
$updateStudentDni = $db->prepare(
    'UPDATE alumnos SET dni = :dni WHERE id = :id'
);

$db->beginTransaction();
try {
    $userIds = [];
    foreach ($users as $user) {
        $findUser->execute(['email' => $user['email']]);
        $existingUser = $findUser->fetch(PDO::FETCH_ASSOC);
        if (is_array($existingUser)) {
            if ($existingUser['rol'] !== $user['role']) {
                throw new RuntimeException('A test account exists with an unexpected role.');
            }
            $userId = (int)$existingUser['id'];
        } else {
            $insertUser->execute([
                'email' => $user['email'],
                'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
                'rol' => $user['role'],
            ]);
            $userId = (int)$db->lastInsertId();
        }

        $resetPassword->execute([
            'password_hash' => password_hash('password123', PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
        $userIds[$user['email']] = $userId;
    }

    foreach ($studentProfiles as $profile) {
        $findStudent->execute(['email' => $profile['email']]);
        $existingStudentId = $findStudent->fetchColumn();
        if ($existingStudentId !== false) {
            $updateStudentDni->execute([
                'dni' => $profile['dni'],
                'id' => (int)$existingStudentId,
            ]);
            continue;
        }

        $insertStudent->execute([
            'usuario_id' => $userIds[$profile['user_email']],
            'nombre' => $profile['name'],
            'apellidos' => $profile['surname'],
            'fecha_nacimiento' => $profile['birth_date'],
            'dni' => $profile['dni'],
            'numero_seguridad_social' => $profile['social_security'],
            'codigo_postal' => '28013',
            'correo' => $profile['email'],
            'especialidad_id' => (int)$cycleId,
        ]);
    }

    $db->commit();
    echo "Disposable integration fixtures ready.\n";
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, 'Could not create disposable integration fixtures: ' . $exception->getMessage() . "\n");
    exit(1);
}
