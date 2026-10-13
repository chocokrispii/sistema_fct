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
        $disponibilidades = $this->model->obtenerPorUsuario($usuarioId);
        $disponibilidadActual = $disponibilidades[0] ?? [];
        $horarioActual = [];
        foreach ($this->model->obtenerHorarioSemanalPorUsuario($usuarioId) as $item) {
            $horarioActual[(int)$item['dia_semana']] = [
                'activo' => true,
                'hora_inicio' => substr($item['hora_inicio'], 0, 5),
                'hora_fin' => substr($item['hora_fin'], 0, 5),
            ];
        }
        $values = [
            'fecha_inicio' => $disponibilidadActual['fecha_inicio'] ?? '',
            'fecha_fin' => $disponibilidadActual['fecha_fin'] ?? '',
            'turno' => $disponibilidadActual['turno'] ?? 'Indiferente',
            'horarios' => $horarioActual,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $inicioRaw = $_POST['fecha_inicio'] ?? '';
            $finRaw = $_POST['fecha_fin'] ?? '';
            $turno = $_POST['turno'] ?? '';
            $horariosRaw = $_POST['horarios'] ?? [];
            if (!is_string($inicioRaw) || !is_string($finRaw) || !is_string($turno) || !is_array($horariosRaw)) {
                $error = 'Los datos de disponibilidad no tienen un formato válido.';
            } else {
                $values = [
                    'fecha_inicio' => trim($inicioRaw),
                    'fecha_fin' => trim($finRaw),
                    'turno' => $turno,
                    'horarios' => [],
                ];
                $horarios = [];
                foreach ($horariosRaw as $dia => $horarioRaw) {
                    $diaNumero = filter_var($dia, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
                    if ($diaNumero === false || !is_array($horarioRaw)) {
                        $error = 'Selecciona días de la semana válidos.';
                        break;
                    }
                    $activo = $horarioRaw['activo'] ?? '';
                    $horaInicioRaw = $horarioRaw['hora_inicio'] ?? '';
                    $horaFinRaw = $horarioRaw['hora_fin'] ?? '';
                    if (!is_string($activo) || !is_string($horaInicioRaw) || !is_string($horaFinRaw)) {
                        $error = 'Las franjas horarias no tienen un formato válido.';
                        break;
                    }
                    if (!in_array($activo, ['', '1'], true)) {
                        $error = 'Selecciona días de la semana válidos.';
                        break;
                    }
                    $horaInicio = trim($horaInicioRaw);
                    $horaFin = trim($horaFinRaw);
                    $values['horarios'][$diaNumero] = [
                        'activo' => $activo === '1',
                        'hora_inicio' => $horaInicio,
                        'hora_fin' => $horaFin,
                    ];
                    if ($activo !== '1') {
                        continue;
                    }
                    if (
                        !preg_match('/^(?:[01]\d|2[0-3]):(?:00|10|20|30|40|50)$/', $horaInicio)
                        || !preg_match('/^(?:[01]\d|2[0-3]):(?:00|10|20|30|40|50)$/', $horaFin)
                        || $horaFin <= $horaInicio
                    ) {
                        $error = 'Cada día seleccionado necesita una franja horaria válida.';
                        break;
                    }
                    if (
                        ($turno === 'M' && ($horaInicio < '06:00' || $horaFin > '14:00'))
                        || ($turno === 'T' && ($horaInicio < '14:00' || $horaFin > '23:59'))
                    ) {
                        $error = 'Las franjas deben coincidir con el turno seleccionado (mañana de 06:00 a 14:00 o tarde de 14:00 a 23:59).';
                        break;
                    }
                    $horarios[] = [
                        'dia_semana' => (int)$diaNumero,
                        'hora_inicio' => $horaInicio . ':00',
                        'hora_fin' => $horaFin . ':00',
                    ];
                }
                if ($error === '' && $horarios === []) {
                    $error = 'Selecciona al menos un día y una franja horaria semanal.';
                }
                if ($error === '') {
                    $inicio = $this->parseDate($values['fecha_inicio']);
                    $fin = $this->parseDate($values['fecha_fin']);
                    if ($inicio === null || $fin === null || $fin < $inicio) {
                        $error = 'Indica un periodo válido: la fecha final debe ser igual o posterior a la inicial.';
                    } elseif ($inicio < new DateTimeImmutable('today')) {
                        $error = 'La disponibilidad no puede comenzar en una fecha pasada.';
                    } elseif (!in_array($turno, ['M', 'T', 'Indiferente'], true)) {
                        $error = 'Selecciona un turno válido.';
                    } else {
                        $this->model->reemplazar(
                            $usuarioId,
                            $inicio->format('Y-m-d'),
                            $fin->format('Y-m-d'),
                            $turno,
                            $horarios
                        );
                        header('Location: index.php?action=disponibilidad&guardada=1');
                        exit;
                    }
                }
            }
        }

        View::render('alumnos/disponibilidad', [
            'error' => $error,
            'disponibilidades' => $disponibilidades,
            'horarioSemanal' => $this->model->obtenerHorarioSemanalPorUsuario($usuarioId),
            'values' => $values,
            'diasSemana' => [
                1 => 'Lunes',
                2 => 'Martes',
                3 => 'Miércoles',
                4 => 'Jueves',
                5 => 'Viernes',
                6 => 'Sábado',
                7 => 'Domingo',
            ],
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
