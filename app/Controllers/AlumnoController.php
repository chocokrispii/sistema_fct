<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';

class AlumnoController
{
    private PDO $db;
    private Usuario $usuarioModel;
    private Alumno $alumnoModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->usuarioModel = new Usuario($this->db);
        $this->alumnoModel = new Alumno($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function registrar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->mostrarFormulario();
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
            $this->mostrarFormulario('La fecha de nacimiento no es válida.');
            return;
        }

        $hoy = new DateTimeImmutable('today');
        if ($fechaNacimientoObj > $hoy) {
            $this->mostrarFormulario('La fecha de nacimiento no puede ser futura.');
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
            $this->mostrarFormulario('Completa correctamente todos los campos obligatorios.');
            return;
        }

        if (!$this->especialidadModel->existe($especialidadId)) {
            $this->mostrarFormulario('La especialidad seleccionada no existe.');
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
            $this->mostrarFormulario('El alumno debe tener al menos 16 años.');
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
            $this->mostrarFormulario('Para menores de 18 años, completa todos los datos del tutor legal.');
            return;
        }

        try {
            $this->db->beginTransaction();

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
            if ($alumnoId < 1) {
                throw new RuntimeException('No se pudo crear el perfil del alumno.');
            }

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

            $this->db->commit();
            echo '<p style="color:green;">Alumno registrado correctamente. <a href="index.php?action=login">Inicia sesión</a>.</p>';
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al registrar alumno: ' . $e->getMessage());
            $this->mostrarFormulario('No se pudo completar el registro del alumno.');
        }
    }

    private function mostrarFormulario(string $error = ''): void
    {
        if ($error !== '') {
            echo '<p style="color:red;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $especialidades = $this->especialidadModel->obtenerTodas();
        ?>
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
            <label>Ciclo formativo:</label>
            <?php require __DIR__ . '/../Views/partials/select_especialidades.php'; ?>

            <h3>Datos del tutor (obligatorios si el alumno es menor de 18 años)</h3>
            <input type="text" name="tutor_nombre" placeholder="Nombre completo del tutor">
            <input type="text" name="tutor_dni" placeholder="DNI/NIE del tutor">
            <input type="text" name="tutor_nss" placeholder="Nº Seguridad Social del tutor">
            <input type="email" name="tutor_correo" placeholder="Correo del tutor">
            <input type="text" name="tutor_direccion" placeholder="Dirección del tutor">
            <input type="text" name="tutor_telefono" placeholder="Teléfono del tutor">

            <button type="submit">Registrar alumno</button>
        </form>
        <?php
    }
}
