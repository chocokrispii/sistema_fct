<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';

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
        $error = is_string($_SESSION['alumno_admin_error'] ?? null)
            ? $_SESSION['alumno_admin_error']
            : '';
        unset($_SESSION['alumno_admin_error']);
        $search = $_GET['q'] ?? '';
        $cycleRaw = $_GET['ciclo'] ?? '';
        $statusRaw = $_GET['estado'] ?? '';
        if (!is_string($search) || !is_string($cycleRaw) || !is_string($statusRaw)) {
            $error = 'Los filtros de búsqueda no tienen un formato válido.';
            $search = '';
            $cycleRaw = '';
            $statusRaw = '';
        }
        $search = trim($search);
        if (
            mb_strlen($search) > 100
            || preg_match('/[\p{Cc}]/u', $search) === 1
            || preg_match('//u', $search) !== 1
        ) {
            $error = 'La búsqueda debe tener como máximo 100 caracteres válidos.';
            $search = '';
        }
        $cycleId = null;
        if ($cycleRaw !== '') {
            $validatedCycle = filter_var($cycleRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedCycle === false) {
                $error = 'Selecciona un ciclo formativo válido.';
            } else {
                $cycleId = (int)$validatedCycle;
            }
        }
        $active = null;
        if ($statusRaw !== '') {
            if (!in_array($statusRaw, ['0', '1'], true)) {
                $error = 'Selecciona un estado válido.';
            } else {
                $active = $statusRaw === '1';
            }
        }

        View::render('admin/alumnos', [
            'error' => $error,
            'actualizado' => isset($_GET['actualizado']),
            'search' => $search,
            'cycleId' => $cycleId,
            'status' => $statusRaw,
            'especialidades' => $this->especialidadModel->obtenerTodas(false),
            'alumnos' => $this->alumnoModel->obtenerTodosParaCentro($search, $cycleId, $active),
        ]);
    }

    public function alta(): void
    {
        $error = '';
        $values = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['tipo_documento', 'dni', 'nombre', 'apellidos', 'email', 'especialidad_id', 'curso', 'observaciones_administrativas'] as $field) {
                $value = $_POST[$field] ?? '';
                if (!is_string($value)) {
                    $error = 'Los datos del alumno no tienen un formato válido.';
                    break;
                }
                $values[$field] = trim($value);
            }

            if ($error === '') {
                $documentType = $values['tipo_documento'];
                $dni = $documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                    ? strtoupper($values['dni'])
                    : SpanishDataValidator::normalizeDniNie($values['dni']);
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
                    !SpanishDataValidator::isStudentDocumentType($documentType)
                    || ($documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                        ? !SpanishDataValidator::isPassport($dni)
                        : !SpanishDataValidator::isDniNie($dni))
                    || !SpanishDataValidator::isPersonName($nombre, 50)
                    || !SpanishDataValidator::isPersonName($apellidos, 100)
                    || !SpanishDataValidator::isEmail($email)
                    || $cycleId === false
                    || !in_array($course, ['1', '2'], true)
                    || mb_strlen($observations) > 2000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $observations) === 1
                ) {
                    $error = 'Revisa el tipo y número de documento, nombre, apellidos, correo, ciclo, curso y observaciones.';
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
                            $observations,
                            $documentType
                        );
                        WebhookPublisher::publish($this->db, 'alumno.alta', ['alumno_id' => $studentId]);
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
                        if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
                            throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible para el alumno.');
                        }
                        $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($activationToken);
                        (new AccountActivationMailer())->send($email, $nombre . ' ' . $apellidos, $activationUrl);
                        $this->db->commit();

                        $_SESSION['alumno_creado_email'] = $email;
                        header('Location: index.php?action=alta-alumno&tab=altas&creado=1');
                        exit;
                    } catch (Throwable $exception) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        if ($exception instanceof PDOException) {
                            error_log('Error de base de datos al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = 'No se pudo crear la cuenta. Comprueba si el correo o el documento de identidad ya están registrados.';
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
        View::render('admin/alta-alumno', [
            'error' => $error,
            'values' => $values,
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'creado' => isset($_GET['creado']) && is_string($sentTo),
            'sentTo' => is_string($sentTo) ? $sentTo : '',
        ]);
    }

    public function actualizar(): void
    {
        $studentId = filter_var($_POST['alumno_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fields = ['tipo_documento', 'dni', 'nombre', 'apellidos', 'email', 'especialidad_id', 'curso', 'observaciones_administrativas'];
        $values = [];
        if ($studentId === false) {
            $this->redirectWithError('Selecciona un expediente de alumno válido.');
        }
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->redirectWithError('Los datos del alumno no tienen un formato válido.');
            }
            $values[$field] = trim($value);
        }

        $documentType = $values['tipo_documento'];
        $dni = $documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
            ? strtoupper($values['dni'])
            : SpanishDataValidator::normalizeDniNie($values['dni']);
        $cycleId = filter_var($values['especialidad_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (
            !SpanishDataValidator::isStudentDocumentType($documentType)
            || ($documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                ? !SpanishDataValidator::isPassport($dni)
                : !SpanishDataValidator::isDniNie($dni))
            || !SpanishDataValidator::isPersonName($values['nombre'], 50)
            || !SpanishDataValidator::isPersonName($values['apellidos'], 100)
            || !SpanishDataValidator::isEmail($values['email'])
            || $cycleId === false
            || !in_array($values['curso'], ['1', '2'], true)
            || mb_strlen($values['observaciones_administrativas']) > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['observaciones_administrativas']) === 1
        ) {
            $this->redirectWithError('Revisa el tipo y número de documento, nombre, apellidos, correo, ciclo, curso y observaciones.');
        }
        if (!$this->especialidadModel->existe((int)$cycleId, false)) {
            $this->redirectWithError('Selecciona un ciclo formativo existente.');
        }

        try {
            $this->db->beginTransaction();
            $userId = $this->alumnoModel->obtenerUsuarioIdParaCentro((int)$studentId, true);
            if ($userId === null) {
                throw new DomainException('No se encontró el expediente del alumno.');
            }
            $this->usuarioModel->assertIdentificadorFiscalDisponible($dni, $userId);
            $this->alumnoModel->actualizarDesdePersonal(
                (int)$studentId,
                $values['nombre'],
                $values['apellidos'],
                $dni,
                $documentType,
                $values['email'],
                (int)$cycleId,
                (int)$values['curso'],
                $values['observaciones_administrativas']
            );
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'alumno.actualizado_por_personal',
                'alumnos',
                (int)$studentId,
                'ciclo_id=' . (int)$cycleId . '; curso=' . (int)$values['curso']
            );
            $this->db->commit();
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->redirectWithError($exception->getMessage());
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al actualizar alumno desde el centro: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo actualizar el expediente. Comprueba que el correo o documento no pertenezcan a otra cuenta.');
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error inesperado al actualizar alumno desde el centro: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo actualizar el expediente.');
        }

        header('Location: index.php?action=alumnos-centro&tab=principal&actualizado=1');
        exit;
    }

    private function redirectWithError(string $message): never
    {
        $_SESSION['alumno_admin_error'] = $message;
        header('Location: index.php?action=alumnos-centro&tab=principal');
        exit;
    }
}
