<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Calificacion.php';

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

        $fields = [
            'email', 'password', 'nombre', 'apellidos', 'dni', 'nss', 'telefono', 'direccion',
            'fecha_nacimiento', 'tutor_nombre', 'tutor_dni', 'tutor_nss', 'tutor_correo',
            'tutor_direccion', 'tutor_telefono',
        ];
        $input = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->mostrarFormulario('Los datos del registro deben ser valores de texto válidos.');
                return;
            }
            // La contraseña no se recorta: los espacios cuentan.
            $input[$field] = $field === 'password' ? $value : trim($value);
        }
        $email = $input['email'];
        $password = $input['password'];
        $nombre = $input['nombre'];
        $apellidos = $input['apellidos'];
        $dni = $input['dni'];
        $nss = $input['nss'];
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $fechaNacimiento = $input['fecha_nacimiento'];
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
            || strlen($email) > 100
            || strlen($password) < 12
            || strlen($password) > 72
            || trim($password) === ''
            || $nombre === ''
            || mb_strlen($nombre) > 50
            || $apellidos === ''
            || mb_strlen($apellidos) > 100
            || $dni === ''
            || mb_strlen($dni) > 20
            || $nss === ''
            || mb_strlen($nss) > 20
            || $telefono === ''
            || mb_strlen($telefono) > 20
            || $direccion === ''
            || mb_strlen($direccion) > 255
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
            'nombre' => $input['tutor_nombre'],
            'dni' => $input['tutor_dni'],
            'nss' => $input['tutor_nss'],
            'correo' => $input['tutor_correo'],
            'direccion' => $input['tutor_direccion'],
            'telefono' => $input['tutor_telefono'],
        ];
        if (
            mb_strlen($tutor['nombre']) > 100
            || mb_strlen($tutor['dni']) > 20
            || mb_strlen($tutor['nss']) > 20
            || strlen($tutor['correo']) > 100
            || mb_strlen($tutor['direccion']) > 255
            || mb_strlen($tutor['telefono']) > 20
        ) {
            $this->mostrarFormulario('Uno o varios datos del tutor superan el límite permitido.');
            return;
        }

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
            header('Location: index.php?action=login&registrado=1');
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al registrar alumno: ' . $e->getMessage());
            $this->mostrarFormulario('No se pudo completar el registro del alumno.');
        }
    }

    public function portal(): void
    {
        $usuarioId = (int)$_SESSION['usuario_id'];
        $alumno = $this->alumnoModel->obtenerPorUsuario($usuarioId);

        View::render('alumnos/portal', [
            'alumno' => $alumno,
            'disponibilidades' => $alumno
                ? (new Disponibilidad($this->db))->obtenerPorUsuario($usuarioId)
                : [],
            'horarioSemanal' => $alumno
                ? (new Disponibilidad($this->db))->obtenerHorarioSemanalPorUsuario($usuarioId)
                : [],
            'asignaciones' => $alumno
                ? (new Asignacion($this->db))->listar(true, $usuarioId)
                : [],
            'calificaciones' => $alumno
                ? (new Calificacion($this->db))->listar(true, $usuarioId)
                : [],
        ]);
    }

    private function mostrarFormulario(string $error = ''): void
    {
        $especialidades = $this->especialidadModel->obtenerTodas();
        View::render('alumnos/registro', ['error' => $error, 'especialidades' => $especialidades]);
    }
}
