<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';

class AlumnoAdminController
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

    public function gestionar(): void
    {
        $error = '';
        $values = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['dni', 'nombre', 'apellidos', 'email', 'especialidad_id', 'curso', 'observaciones_administrativas'] as $field) {
                $value = $_POST[$field] ?? '';
                if (!is_string($value)) {
                    $error = 'Los datos del alumno no tienen un formato válido.';
                    break;
                }
                $values[$field] = trim($value);
            }

            if ($error === '') {
                $dni = SpanishDataValidator::normalizeDniNie($values['dni']);
                $nombre = $values['nombre'];
                $apellidos = $values['apellidos'];
                $email = $values['email'];
                $cycleId = filter_var(
                    $values['especialidad_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                $course = $values['curso'];
                $observations = $values['observaciones_administrativas'];

                if (
                    !SpanishDataValidator::isDniNie($dni)
                    || !SpanishDataValidator::isPersonName($nombre, 50)
                    || !SpanishDataValidator::isPersonName($apellidos, 100)
                    || !SpanishDataValidator::isEmail($email)
                    || $cycleId === false
                    || !in_array($course, ['1', '2'], true)
                    || mb_strlen($observations) > 2000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $observations) === 1
                ) {
                    $error = 'Revisa el NIF/DNI/NIE, nombre, apellidos, correo, ciclo, curso y observaciones.';
                } elseif (!$this->especialidadModel->existe((int)$cycleId)) {
                    $error = 'Selecciona un ciclo formativo activo.';
                } else {
                    $activationToken = bin2hex(random_bytes(32));
                    try {
                        $this->db->beginTransaction();
                        $this->usuarioModel->assertIdentificadorFiscalDisponible($dni);
                        $userId = $this->usuarioModel->crear(
                            $email,
                            bin2hex(random_bytes(32)),
                            'Alumno',
                            false
                        );
                        if ($userId < 1) {
                            throw new RuntimeException('No se pudo crear la cuenta del alumno.');
                        }

                        $studentId = $this->alumnoModel->crearDesdePersonal(
                            $userId,
                            $nombre,
                            $apellidos,
                            $dni,
                            $email,
                            (int)$cycleId,
                            (int)$course,
                            $observations
                        );
                        (new Auditoria($this->db))->registrar(
                            (int)$_SESSION['usuario_id'],
                            'alumno.creado_por_personal',
                            'alumnos',
                            $studentId,
                            'ciclo_id=' . (int)$cycleId . '; curso=' . (int)$course
                        );
                        $tokenInsert = $this->db->prepare(
                            'INSERT INTO student_activation_tokens (usuario_id, token_hash, expires_at)
                             VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
                        );
                        $tokenInsert->execute([
                            'usuario_id' => $userId,
                            'token_hash' => hash('sha256', $activationToken),
                        ]);
                        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
                        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
                            throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible para el alumno.');
                        }
                        $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($activationToken);
                        (new AccountActivationMailer())->send($email, $nombre . ' ' . $apellidos, $activationUrl);
                        $this->db->commit();

                        $_SESSION['alumno_creado_email'] = $email;
                        header('Location: index.php?action=alumnos-centro&creado=1');
                        exit;
                    } catch (Throwable $exception) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        if ($exception instanceof PDOException) {
                            error_log('Error de base de datos al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = 'No se pudo crear la cuenta. Comprueba si el correo o el NIF/DNI/NIE ya están registrados.';
                        } elseif ($exception instanceof DomainException) {
                            $error = $exception->getMessage();
                        } elseif ($exception instanceof RuntimeException) {
                            error_log('Error al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = $exception->getMessage();
                        } else {
                            error_log('Error inesperado al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = 'No se pudo completar el alta del alumno.';
                        }
                    }
                }
            }
        }

        $sentTo = $_SESSION['alumno_creado_email'] ?? null;
        unset($_SESSION['alumno_creado_email']);
        View::render('admin/alumnos', [
            'error' => $error,
            'values' => $values,
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'alumnos' => $this->alumnoModel->obtenerTodosParaCentro(),
            'creado' => isset($_GET['creado']) && is_string($sentTo),
            'sentTo' => is_string($sentTo) ? $sentTo : '',
        ]);
    }
}
