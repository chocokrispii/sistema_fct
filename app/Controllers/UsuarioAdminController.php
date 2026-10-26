<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';

class UsuarioAdminController
{
    private PDO $db;
    private Usuario $model;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->model = new Usuario($this->db);
    }

    public function gestionar(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $emailRaw = $_POST['email'] ?? '';
            $role = $_POST['rol'] ?? '';
            $nifRaw = $_POST['identificador_fiscal'] ?? '';
            $nombreRaw = $_POST['nombre'] ?? '';
            $apellidosRaw = $_POST['apellidos'] ?? '';
            if (
                !is_string($emailRaw)
                || !is_string($role)
                || !is_string($nifRaw)
                || !is_string($nombreRaw)
                || !is_string($apellidosRaw)
            ) {
                throw new InvalidArgumentException('Los datos de la cuenta no tienen un formato válido.');
            }
            $email = trim($emailRaw);
            $nif = SpanishDataValidator::normalizeDniNie($nifRaw);
            $nombre = trim($nombreRaw);
            $apellidos = trim($apellidosRaw);
            if (
                !SpanishDataValidator::isEmail($email)
                || !SpanishDataValidator::isDniNie($nif)
                || !in_array($role, ['Administrador', 'Coordinador', 'Observador'], true)
                || !SpanishDataValidator::isPersonName($nombre, 50)
                || !SpanishDataValidator::isPersonName($apellidos, 100)
            ) {
                $error = 'Indica un correo, NIF/DNI/NIE, nombre, apellidos y rol válidos.';
            } else {
                $activationToken = bin2hex(random_bytes(32));
                try {
                    $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
                    if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
                        throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible para el personal.');
                    }
                    $this->db->beginTransaction();
                    $id = $this->model->crearCuentaStaff($email, $role, $nif, $nombre, $apellidos);
                    if ($role === 'Coordinador') {
                        $profile = $this->db->prepare(
                            'INSERT INTO coordinadores (usuario_id, nombre, apellidos)
                             VALUES (:usuario, :nombre, :apellidos)'
                        );
                        $profile->execute(['usuario' => $id, 'nombre' => $nombre, 'apellidos' => $apellidos]);
                    }
                    $activationInsert = $this->db->prepare(
                        'INSERT INTO staff_activation_tokens (usuario_id, token_hash, expires_at)
                         VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
                    );
                    $activationInsert->execute([
                        'usuario_id' => $id,
                        'token_hash' => hash('sha256', $activationToken),
                    ]);
                    $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($activationToken);
                    (new AccountActivationMailer())->send($email, $nombre . ' ' . $apellidos, $activationUrl);
                    $this->audit('usuario.creado', $id, 'rol=' . $role);
                    $this->db->commit();
                    $_SESSION['usuario_creado_email'] = $email;
                    header('Location: index.php?action=usuarios&creado=1');
                    exit;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    error_log('Error al crear cuenta de personal: ' . $e->getMessage());
                    if ($e instanceof InvalidArgumentException || $e instanceof DomainException) {
                        $error = $e->getMessage();
                    } elseif ($e instanceof PDOException) {
                        $error = 'No se pudo crear la cuenta. Comprueba si el correo y el identificador fiscal no están ya registrados.';
                    } elseif ($e instanceof RuntimeException) {
                        $error = 'No se pudo completar el alta o enviar el enlace de activación. Comprueba la configuración del correo e inténtalo de nuevo.';
                    } else {
                        $error = 'No se pudo crear la cuenta. Comprueba si el correo y el identificador fiscal no están ya registrados.';
                    }
                }
            }
        }

        $sentTo = $_SESSION['usuario_creado_email'] ?? null;
        unset($_SESSION['usuario_creado_email']);
        View::render('admin/usuarios', [
            'usuarios' => $this->model->obtenerTodos(),
            'error' => $error !== '' ? $error : (is_string($_GET['error'] ?? null) ? $_GET['error'] : ''),
            'eliminado' => isset($_GET['eliminado']),
            'creado' => isset($_GET['creado']) && is_string($sentTo),
            'sentTo' => is_string($sentTo) ? $sentTo : '',
        ]);
    }

    public function actualizarIdentificadorFiscal(): void
    {
        $id = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $identifier = $_POST['identificador_fiscal'] ?? null;
        if ($id === false || !is_string($identifier)) {
            $this->redirectWithError('Los datos del identificador fiscal no tienen un formato válido.');
        }

        try {
            $this->db->beginTransaction();
            $roleQuery = $this->db->prepare('SELECT rol FROM usuarios WHERE id = :id FOR UPDATE');
            $roleQuery->execute(['id' => $id]);
            $role = $roleQuery->fetchColumn();
            if (in_array($role, ['Administrador', 'Coordinador', 'Observador'], true)) {
                $this->model->asignarNifPersonal((int)$id, $identifier);
            } elseif ($role === 'Empresa') {
                $this->model->actualizarCifEmpresa((int)$id, $identifier);
            } else {
                throw new DomainException('Solo se pueden actualizar identificadores fiscales de personal o empresas.');
            }
            $this->audit('usuario.identificador_fiscal_actualizado', (int)$id, 'identificador fiscal actualizado');
            $this->db->commit();
        } catch (InvalidArgumentException|DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->redirectWithError($exception->getMessage());
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al actualizar identificador fiscal: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo actualizar el identificador. Comprueba que sea válido y no esté asociado a otra cuenta.');
        }

        header('Location: index.php?action=usuarios');
        exit;
    }

    public function eliminar(): void
    {
        $id = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $this->redirectWithError('Selecciona una cuenta válida para eliminar.');
        }
        try {
            $this->model->eliminarPermanentemente((int)$id, (int)$_SESSION['usuario_id']);
        } catch (DomainException $exception) {
            $this->redirectWithError($exception->getMessage());
        } catch (PDOException $exception) {
            error_log('Error al eliminar cuenta: ' . $exception->getMessage());
            $this->redirectWithError('La base de datos conserva referencias que impiden eliminar esta cuenta. Desactívala para mantener la trazabilidad.');
        } catch (Throwable $exception) {
            error_log('Error inesperado al eliminar cuenta: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo eliminar la cuenta. No se realizaron cambios.');
        }

        header('Location: index.php?action=usuarios&eliminado=1');
        exit;
    }

    public function cambiarEstado(): void
    {
        $id = filter_var($_POST['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $activo = $_POST['activo'] ?? '';
        if ($id === false || !in_array($activo, ['0', '1'], true)) {
            throw new InvalidArgumentException('La petición para cambiar el estado de la cuenta no es válida.');
        }
        if ((int)$id === (int)$_SESSION['usuario_id'] && $activo === '0') {
            throw new RuntimeException('No puedes desactivar tu propia cuenta.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, rol, activo FROM usuarios WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new RuntimeException('La cuenta solicitada no existe.');
            }
            if ($user['rol'] === 'Administrador' && (int)$user['activo'] === 1 && $activo === '0') {
                $administradoresActivos = $this->db->query(
                    "SELECT id FROM usuarios WHERE rol = 'Administrador' AND activo = 1 FOR UPDATE"
                )->fetchAll(PDO::FETCH_COLUMN);
                $count = count($administradoresActivos);
                if ($count <= 1) {
                    throw new RuntimeException('Debe permanecer al menos un administrador activo.');
                }
            }
            if (!$this->model->cambiarActivo((int)$id, $activo === '1')) {
                throw new RuntimeException('La cuenta ya tiene el estado solicitado.');
            }
            $this->audit('usuario.estado_cambiado', (int)$id, 'activo=' . $activo);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        header('Location: index.php?action=usuarios');
        exit;
    }

    private function audit(string $event, int $entityId, string $detail): void
    {
        (new Auditoria($this->db))->registrar(
            (int)$_SESSION['usuario_id'],
            $event,
            'usuarios',
            $entityId,
            $detail
        );
    }

    private function redirectWithError(string $message): never
    {
        header('Location: index.php?action=usuarios&error=' . rawurlencode($message));
        exit;
    }
}
