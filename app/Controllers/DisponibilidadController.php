<?php

require_once __DIR__ . '/../Models/Disponibilidad.php';

class DisponibilidadController
{
    private Disponibilidad $model;

    public function __construct(?PDO $db = null)
    {
        $this->model = new Disponibilidad($db ?? getPDOConnection());
    }

    public function gestionar(): void
    {
        $error = '';
        $usuarioId = (int)$_SESSION['usuario_id'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $inicioRaw = $_POST['fecha_inicio'] ?? '';
            $finRaw = $_POST['fecha_fin'] ?? '';
            $turno = $_POST['turno'] ?? '';
            if (!is_string($inicioRaw) || !is_string($finRaw) || !is_string($turno)) {
                throw new InvalidArgumentException('Los datos de disponibilidad no son válidos.');
            }
            $inicio = $this->parseDate($inicioRaw);
            $fin = $this->parseDate($finRaw);
            if ($inicio === null || $fin === null || $fin < $inicio) {
                $error = 'Indica un periodo válido: la fecha final debe ser igual o posterior a la inicial.';
            } elseif ($inicio < new DateTimeImmutable('today')) {
                $error = 'La disponibilidad no puede comenzar en una fecha pasada.';
            } elseif (!in_array($turno, ['M', 'T', 'Indiferente'], true)) {
                $error = 'Selecciona un turno válido.';
            } else {
                $this->model->reemplazar($usuarioId, $inicio->format('Y-m-d'), $fin->format('Y-m-d'), $turno);
                header('Location: index.php?action=disponibilidad&guardada=1');
                exit;
            }
        }

        View::render('alumnos/disponibilidad', [
            'error' => $error,
            'disponibilidades' => $this->model->obtenerPorUsuario($usuarioId),
        ]);
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }
}
