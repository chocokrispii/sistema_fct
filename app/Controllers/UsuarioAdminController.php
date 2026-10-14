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
        $busqueda = '';
        $rolFiltro = '';
        $estadoFiltro = '';
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
                || trim($password) === ''
            ) {
                $error = 'Indica un correo válido y una contraseña de 12 a 72 caracteres.';
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
        } else {
            $busquedaRaw = $_GET['buscar'] ?? '';
            $rolRaw = $_GET['rol'] ?? '';
            $estadoRaw = $_GET['activo'] ?? '';
            if (
                !is_string($busquedaRaw)
                || !is_string($rolRaw)
                || !is_string($estadoRaw)
                || mb_strlen(trim($busquedaRaw)) > 100
                || ($rolRaw !== '' && !in_array($rolRaw, ['Administrador', 'Coordinador', 'Observador', 'Empresa', 'Alumno'], true))
                || !in_array($estadoRaw, ['', '0', '1'], true)
            ) {
                $error = 'Los filtros de búsqueda no tienen un formato válido.';
            } else {
                $busqueda = trim($busquedaRaw);
                $rolFiltro = $rolRaw;
                $estadoFiltro = $estadoRaw;
            }
        }

        View::render('admin/usuarios', [
            'usuarios' => $this->model->obtenerTodos(
                $busqueda !== '' ? $busqueda : null,
                $rolFiltro !== '' ? $rolFiltro : null,
                $estadoFiltro === '' ? null : $estadoFiltro === '1'
            ),
            'busqueda' => $busqueda,
            'rolFiltro' => $rolFiltro,
            'estadoFiltro' => $estadoFiltro,
            'error' => $error !== '' ? $error : (is_string($_GET['error'] ?? null) ? $_GET['error'] : ''),
            'eliminado' => isset($_GET['eliminado']),
        ]);
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

        header('Location: ' . $this->gestionUrl(['eliminado' => '1']));
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

        header('Location: ' . $this->gestionUrl());
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
        header('Location: ' . $this->gestionUrl(['error' => $message]));
        exit;
    }

    private function gestionUrl(array $extra = []): string
    {
        $parameters = ['action' => 'usuarios'];
        $search = $_POST['buscar'] ?? '';
        $role = $_POST['rol'] ?? '';
        $active = $_POST['activo_filtro'] ?? '';

        if (is_string($search) && mb_strlen(trim($search)) <= 100 && trim($search) !== '') {
            $parameters['buscar'] = trim($search);
        }
        if (is_string($role) && in_array($role, ['Administrador', 'Coordinador', 'Observador', 'Empresa', 'Alumno'], true)) {
            $parameters['rol'] = $role;
        }
        if (is_string($active) && in_array($active, ['0', '1'], true)) {
            $parameters['activo'] = $active;
        }

        return 'index.php?' . http_build_query($parameters + $extra);
    }
}
