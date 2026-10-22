<?php

require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';

class EspecialidadAdminController
{
    private PDO $db;
    private Especialidad $model;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->model = new Especialidad($this->db);
    }

    public function gestionar(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $name = $_POST['nombre_especialidad'] ?? '';
            if (!is_string($name)) {
                throw new InvalidArgumentException('El nombre del ciclo debe ser texto.');
            }

            try {
                $this->db->beginTransaction();
                $id = $this->model->crear($name);
                (new Auditoria($this->db))->registrar(
                    (int)$_SESSION['usuario_id'],
                    'ciclo.creado',
                    'especialidades',
                    $id,
                    'nombre=' . trim($name)
                );
                $this->db->commit();
                header('Location: index.php?action=ciclos&creado=1');
                exit;
            } catch (InvalidArgumentException | DomainException $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                $error = $exception->getMessage();
            } catch (PDOException $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
                    $error = 'Ya existe un ciclo formativo con ese nombre.';
                } else {
                    error_log('Error al crear ciclo formativo: ' . $exception->getMessage());
                    throw $exception;
                }
            } catch (Throwable $exception) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $exception;
            }
        }

        View::render('admin/ciclos', [
            'especialidades' => $this->model->obtenerTodas(false),
            'error' => $error,
            'creado' => isset($_GET['creado']),
            'retirado' => isset($_GET['retirado']),
            'reactivado' => isset($_GET['reactivado']),
        ]);
    }

    public function retirar(): void
    {
        $this->cambiarEstado(false);
    }

    public function reactivar(): void
    {
        $this->cambiarEstado(true);
    }

    private function cambiarEstado(bool $activar): void
    {
        $id = filter_var($_POST['especialidad_id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($id === false) {
            throw new InvalidArgumentException('Selecciona un ciclo formativo válido.');
        }

        $this->db->beginTransaction();
        try {
            $updated = $activar
                ? $this->model->reactivar((int)$id)
                : $this->model->retirar((int)$id);
            if (!$updated) {
                throw new DomainException('El ciclo ya tiene ese estado o no existe. Actualiza la página e inténtalo de nuevo.');
            }

            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                $activar ? 'ciclo.reactivado' : 'ciclo.retirado',
                'especialidades',
                (int)$id
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        header('Location: index.php?action=ciclos&' . ($activar ? 'reactivado=1' : 'retirado=1'));
        exit;
    }
}
