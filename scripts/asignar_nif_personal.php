<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este asistente solo puede ejecutarse desde PHP CLI.\n");
    exit(2);
}
if (!in_array(getenv('APP_ENV'), ['development', 'local'], true)) {
    fwrite(STDERR, "Este asistente solo está disponible en un entorno local de desarrollo.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Models/Usuario.php';

echo "Correo de la cuenta de personal: ";
$email = trim((string)fgets(STDIN));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Introduce un correo válido.\n");
    exit(2);
}

$db = getPDOConnection();
$lookup = $db->prepare(
    "SELECT id, rol FROM usuarios
     WHERE email = :email AND rol IN ('Administrador', 'Coordinador', 'Observador')
     LIMIT 1"
);
$lookup->execute(['email' => $email]);
$user = $lookup->fetch(PDO::FETCH_ASSOC);
if (!is_array($user)) {
    fwrite(STDERR, "No existe una cuenta de personal con ese correo.\n");
    exit(1);
}

echo "NIF/DNI/NIE de la cuenta: ";
$nif = trim((string)fgets(STDIN));
try {
    (new Usuario($db))->asignarNifPersonal((int)$user['id'], $nif);
} catch (InvalidArgumentException|DomainException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
} catch (Throwable $exception) {
    error_log('Error al asignar NIF local a una cuenta de personal: ' . $exception->getMessage());
    fwrite(STDERR, "No se pudo actualizar el identificador fiscal. No se realizaron cambios.\n");
    exit(1);
}

echo "NIF actualizado para la cuenta " . $user['rol'] . ". Ya puede acceder con su NIF y contraseña.\n";
