<?php

require_once __DIR__ . '/../Models/Calificacion.php';

class CalificacionController
{
    private Calificacion $model;

    public function __construct(?PDO $db = null)
    {
        $this->model = new Calificacion($db ?? getPDOConnection());
    }

    public function listar(): void
    {
        $this->render(false);
    }

    public function misCalificaciones(): void
    {
        $this->render(true);
    }

    public function guardar(): void
    {
        if (!in_array($_SESSION['rol'] ?? null, ['Administrador', 'Coordinador'], true)) {
            http_response_code(403);
            throw new RuntimeException('No tienes permisos para registrar calificaciones.');
        }
        $asignacionId = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $notaRaw = $_POST['nota'] ?? '';
        $observacionesRaw = $_POST['observaciones'] ?? '';
        if ($asignacionId === false || !is_string($notaRaw) || !is_string($observacionesRaw)) {
            $this->redirectWithError('Los datos de calificación no tienen un formato válido.');
        }
        $notaTexto = str_replace(',', '.', trim($notaRaw));
        if (!preg_match('/^(?:10(?:\.0{1,2})?|[0-9](?:\.[0-9]{1,2})?)$/', $notaTexto)) {
            $this->redirectWithError('La nota debe ser un número entre 0 y 10, con hasta dos decimales.');
        }
        $observaciones = trim($observacionesRaw);
        if (mb_strlen($observaciones) > 2000) {
            $this->redirectWithError('Las observaciones no pueden superar 2000 caracteres.');
        }

        try {
            $this->model->guardar(
                (int)$asignacionId,
                (int)$_SESSION['usuario_id'],
                (float)$notaTexto,
                $observaciones
            );
        } catch (DomainException $exception) {
            $this->redirectWithError($exception->getMessage());
        }
        header('Location: index.php?action=calificaciones&guardada=1');
        exit;
    }

    private function render(bool $soloPropias): void
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $error = $_GET['error'] ?? '';
        View::render('calificaciones/listado', [
            'calificaciones' => $this->model->listar($soloPropias, $usuarioId),
            'soloPropias' => $soloPropias,
            'puedeGestionar' => in_array($_SESSION['rol'] ?? null, ['Administrador', 'Coordinador'], true),
            'error' => is_string($error) ? $error : '',
        ]);
    }

    private function redirectWithError(string $message): never
    {
        header('Location: index.php?action=calificaciones&error=' . rawurlencode($message));
        exit;
    }
}
