<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Auditoria.php';

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
            $password = $_POST['password'] ?? '';
            $role = $_POST['rol'] ?? '';
            if (!is_string($emailRaw) || !is_string($password) || !is_string($role)) {
                throw new InvalidArgumentException('Los datos de la cuenta no tienen un formato válido.');
            }
            $email = trim($emailRaw);
            if (
                !filter_var($email, FILTER_VALIDATE_EMAIL)
                || strlen($email) > 100
                || strlen($password) < 12
                || strlen($password) > 72
            ) {
                $error = 'Indica un correo válido y una contraseña de 12 a 72 bytes.';
            } else {
                try {
                    $this->db->beginTransaction();
                    $nombreRaw = $_POST['nombre'] ?? '';
                    $apellidosRaw = $_POST['apellidos'] ?? '';
                    if (!is_string($nombreRaw) || !is_string($apellidosRaw)) {
                        throw new InvalidArgumentException('El nombre y los apellidos deben ser texto.');
                    }
                    $nombre = trim($nombreRaw);
                    $apellidos = trim($apellidosRaw);
                    if (
                        $role === 'Coordinador'
                        && ($nombre === '' || $apellidos === '' || mb_strlen($nombre) > 50 || mb_strlen($apellidos) > 100)
                    ) {
                        throw new InvalidArgumentException('Los coordinadores necesitan nombre y apellidos.');
                    }
                    $id = $this->model->crearCuentaStaff($email, $password, $role);
                    if ($role === 'Coordinador') {
                        $profile = $this->db->prepare(
                            'INSERT INTO coordinadores (usuario_id, nombre, apellidos)
                             VALUES (:usuario, :nombre, :apellidos)'
                        );
                        $profile->execute(['usuario' => $id, 'nombre' => $nombre, 'apellidos' => $apellidos]);
                    }
                    $this->audit('usuario.creado', $id, 'rol=' . $role);
                    $this->db->commit();
                    header('Location: index.php?action=usuarios');
                    exit;
                } catch (Throwable $e) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    error_log('Error al crear cuenta de personal: ' . $e->getMessage());
                    $error = 'No se pudo crear la cuenta. Comprueba si el correo ya está registrado.';
                }
            }
        }

        View::render('admin/usuarios', [
            'usuarios' => $this->model->obtenerTodos(),
            'error' => $error,
        ]);
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
}
