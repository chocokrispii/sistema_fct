<?php

require_once __DIR__ . '/../Models/Calificacion.php';
require_once __DIR__ . '/../Core/JsonResponse.php';

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
        $jsonRequest = JsonResponse::wantsJson();
        if (!in_array($_SESSION['rol'] ?? null, ['Administrador', 'Coordinador'], true)) {
            http_response_code(403);
            if ($jsonRequest) {
                JsonResponse::failure('No tienes permisos para registrar calificaciones.', 403);
                return;
            }
            throw new RuntimeException('No tienes permisos para registrar calificaciones.');
        }
        $values = $_POST;
        if (str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
            try {
                $decoded = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                JsonResponse::failure('El cuerpo JSON no es válido.', 400);
                return;
            }
            if (!$decoded instanceof stdClass) {
                JsonResponse::failure('El cuerpo JSON debe ser un objeto de datos.', 400);
                return;
            }
            $values = get_object_vars($decoded);
        }
        $assignmentRaw = $values['asignacion_id'] ?? null;
        $asignacionId = is_int($assignmentRaw) || is_string($assignmentRaw)
            ? filter_var($assignmentRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $notaRaw = $values['nota'] ?? '';
        $observacionesRaw = $values['observaciones'] ?? '';
        if ($asignacionId === false || !is_string($notaRaw) || !is_string($observacionesRaw)) {
            if ($jsonRequest) {
                JsonResponse::failure('Los datos de calificación no tienen un formato válido.', 422);
                return;
            }
            $this->redirectWithError('Los datos de calificación no tienen un formato válido.');
        }
        $notaTexto = str_replace(',', '.', trim($notaRaw));
        $notaFloat = filter_var($notaTexto, FILTER_VALIDATE_FLOAT);
        if (
            preg_match('/\A(?:10(?:\.0{1,2})?|[0-9](?:\.[0-9]{1,2})?)\z/', $notaTexto) !== 1
            || $notaFloat === false
            || $notaFloat < 0
            || $notaFloat > 10
        ) {
            if ($jsonRequest) {
                JsonResponse::failure('La nota debe ser un número entre 0 y 10, con hasta dos decimales.', 422);
                return;
            }
            $this->redirectWithError('La nota debe ser un número entre 0 y 10, con hasta dos decimales.');
        }
        $observaciones = trim($observacionesRaw);
        if (mb_strlen($observaciones) > 2000) {
            if ($jsonRequest) {
                JsonResponse::failure('Las observaciones no pueden superar 2000 caracteres.', 422);
                return;
            }
            $this->redirectWithError('Las observaciones no pueden superar 2000 caracteres.');
        }

        try {
            $this->model->guardar(
                (int)$asignacionId,
                (int)$_SESSION['usuario_id'],
                (float)$notaFloat,
                $observaciones
            );
        } catch (DomainException $exception) {
            if ($jsonRequest) {
                JsonResponse::failure($exception->getMessage(), 422);
                return;
            }
            $this->redirectWithError($exception->getMessage());
        }
        if ($jsonRequest) {
            JsonResponse::success(['message' => 'Calificación guardada.']);
            return;
        }
        header('Location: index.php?action=calificaciones&guardada=1');
        exit;
    }

    private function render(bool $soloPropias): void
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $error = $_GET['error'] ?? '';
        View::render('calificaciones/listado', [
            'calificaciones' => $this->model->listar(
                $soloPropias,
                $usuarioId,
                ($_SESSION['rol'] ?? null) === 'Observador'
            ),
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
