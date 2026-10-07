<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';

class AlumnoController
{
    private Usuario $usuarioModel;
    private Alumno $alumnoModel;

    public function __construct()
    {
        $this->usuarioModel = new Usuario();
        $this->alumnoModel = new Alumno();
    }

    public function registrar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo '
            <h2>Registro de alumno</h2>
            <form method="POST" action="index.php?action=registro-alumno">
                <input type="text" name="nombre" placeholder="Nombre" required>
                <input type="text" name="apellidos" placeholder="Apellidos" required>
                <input type="text" name="dni" placeholder="DNI/NIE" required>
                <input type="email" name="email" placeholder="Correo" required>
                <input type="password" name="password" placeholder="Contraseña" required>
                <input type="text" name="nss" placeholder="Nº Seguridad Social" required>
                <input type="text" name="telefono" placeholder="Teléfono" required>
                <input type="text" name="direccion" placeholder="Dirección" required>
                <label>Fecha de nacimiento:</label>
                <input type="date" name="fecha_nacimiento" required>
                <label>Identificador del ciclo formativo:</label>
                <input type="number" name="especialidad_id" min="1" required>

                <h3>Datos del tutor (obligatorios si el alumno es menor de 18 años)</h3>
                <input type="text" name="tutor_nombre" placeholder="Nombre completo del tutor">
                <input type="text" name="tutor_dni" placeholder="DNI/NIE del tutor">
                <input type="text" name="tutor_nss" placeholder="Nº Seguridad Social del tutor">
                <input type="email" name="tutor_correo" placeholder="Correo del tutor">
                <input type="text" name="tutor_direccion" placeholder="Dirección del tutor">
                <input type="text" name="tutor_telefono" placeholder="Teléfono del tutor">

                <button type="submit">Registrar alumno</button>
            </form>';
            return;
        }

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $nombre = trim($_POST['nombre'] ?? '');
        $apellidos = trim($_POST['apellidos'] ?? '');
        $dni = trim($_POST['dni'] ?? '');
        $nss = trim($_POST['nss'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $fechaNacimiento = trim($_POST['fecha_nacimiento'] ?? '');
        $especialidadId = filter_var(
            $_POST['especialidad_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        $fechaNacimientoObj = DateTimeImmutable::createFromFormat('!Y-m-d', $fechaNacimiento);
        $fechaErrores = DateTimeImmutable::getLastErrors();
        if (
            !$fechaNacimientoObj
            || ($fechaErrores !== false && ($fechaErrores['warning_count'] > 0 || $fechaErrores['error_count'] > 0))
        ) {
            echo '<p style="color:red;">La fecha de nacimiento no es válida.</p>';
            return;
        }

        $hoy = new DateTimeImmutable('today');
        if ($fechaNacimientoObj > $hoy) {
            echo '<p style="color:red;">La fecha de nacimiento no puede ser futura.</p>';
            return;
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || $password === ''
            || $nombre === ''
            || $apellidos === ''
            || $dni === ''
            || $nss === ''
            || $telefono === ''
            || $direccion === ''
            || $especialidadId === false
        ) {
            echo '<p style="color:red;">Completa correctamente todos los campos obligatorios.</p>';
            return;
        }

        $edad = $fechaNacimientoObj->diff($hoy)->y;
        $tutor = [
            'nombre' => trim($_POST['tutor_nombre'] ?? ''),
            'dni' => trim($_POST['tutor_dni'] ?? ''),
            'nss' => trim($_POST['tutor_nss'] ?? ''),
            'correo' => trim($_POST['tutor_correo'] ?? ''),
            'direccion' => trim($_POST['tutor_direccion'] ?? ''),
            'telefono' => trim($_POST['tutor_telefono'] ?? ''),
        ];

        if ($edad < 16) {
            echo '<p style="color:red;">El alumno debe tener al menos 16 años.</p>';
            return;
        }

        if (
            $edad < 18
            && (
                $tutor['nombre'] === ''
                || $tutor['dni'] === ''
                || $tutor['nss'] === ''
                || !filter_var($tutor['correo'], FILTER_VALIDATE_EMAIL)
                || $tutor['direccion'] === ''
                || $tutor['telefono'] === ''
            )
        ) {
            echo '<p style="color:red;">Para menores de 18 años, completa todos los datos del tutor legal.</p>';
            return;
        }

        $db = getPDOConnection();
        try {
            $db->beginTransaction();

            $usuarioId = $this->usuarioModel->crear($email, $password, 'Alumno');
            if ($usuarioId < 1) {
                throw new RuntimeException('No se pudo crear el usuario del alumno.');
            }

            $alumnoId = $this->alumnoModel->crear(
                $usuarioId,
                $nombre,
                $apellidos,
                $fechaNacimientoObj->format('Y-m-d'),
                $dni,
                $nss,
                $email,
                $telefono,
                $direccion,
                $especialidadId
            );

            if ($edad < 18 && !$this->alumnoModel->registrarTutor(
                $alumnoId,
                $tutor['nombre'],
                $tutor['dni'],
                $tutor['nss'],
                $tutor['direccion'],
                $tutor['telefono'],
                $tutor['correo']
            )) {
                throw new RuntimeException('No se pudieron guardar los datos del tutor legal.');
            }

            $db->commit();
            echo '<p style="color:green;">Alumno registrado correctamente.</p>';
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log('Error al registrar alumno: ' . $e->getMessage());
            echo '<p style="color:red;">No se pudo completar el registro del alumno.</p>';
        }
    }
}
