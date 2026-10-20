<?php

require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Core/JsonResponse.php';

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
            'turno' => $disponibilidadActual['turno'] ?? 'M',
            'es_indiferente' => (bool)($disponibilidadActual['es_indiferente'] ?? false),
            'horarios' => $horarioActual,
        ];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $jsonRequest = JsonResponse::wantsJson();
            $requestValues = $_POST;
            $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
            if (str_contains($contentType, 'application/json')) {
                $body = (string)file_get_contents('php://input');
                try {
                    $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
                    if (!$decoded instanceof stdClass) {
                        JsonResponse::failure('El cuerpo JSON debe ser un objeto de datos.', 400);
                        return;
                    }
                    $requestValues = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    JsonResponse::failure('El cuerpo JSON no es válido.', 400);
                    return;
                }
            }

            $inicioRaw = $requestValues['fecha_inicio'] ?? '';
            $finRaw = $requestValues['fecha_fin'] ?? '';
            $turnoRaw = $requestValues['turno'] ?? 'M';
            $esIndiferenteRaw = $requestValues['es_indiferente']
                ?? (($requestValues['turno'] ?? null) === 'Indiferente');
            $horariosRaw = $requestValues['horarios'] ?? [];
            $esIndiferente = null;
            if (is_bool($esIndiferenteRaw)) {
                $esIndiferente = $esIndiferenteRaw;
            } elseif (!$jsonRequest && in_array($esIndiferenteRaw, ['0', '1'], true)) {
                $esIndiferente = $esIndiferenteRaw === '1';
            }
            if (
                !is_string($inicioRaw)
                || !is_string($finRaw)
                || !is_string($turnoRaw)
                || !in_array($turnoRaw, ['M', 'T', 'Indiferente'], true)
                || !is_array($horariosRaw)
                || $esIndiferente === null
            ) {
                $error = 'Los datos de disponibilidad no tienen un formato válido.';
            } else {
                $values = [
                    'fecha_inicio' => trim($inicioRaw),
                    'fecha_fin' => trim($finRaw),
                    'turno' => $esIndiferente ? 'Indiferente' : $turnoRaw,
                    'es_indiferente' => $esIndiferente,
                    'horarios' => [],
                ];
                $horarios = [];
                $diasProcesados = [];
                foreach ($horariosRaw as $dia => $horarioRaw) {
                    $diaNumero = filter_var($dia, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
                    if ($diaNumero === false || !is_array($horarioRaw)) {
                        $error = 'Selecciona días de la semana válidos.';
                        break;
                    }
                    if (isset($diasProcesados[$diaNumero])) {
                        $error = 'Selecciona cada día de la semana una sola vez.';
                        break;
                    }
                    $diasProcesados[$diaNumero] = true;
                    $activo = $horarioRaw['activo'] ?? '';
                    if ($jsonRequest && is_bool($activo)) {
                        $activo = $activo ? '1' : '';
                    }
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
                    if ($activo !== '1' || $esIndiferente) {
                        continue;
                    }
                    $horaInicioNormalizada = $this->normalizeTime($horaInicio);
                    $horaFinNormalizada = $this->normalizeTime($horaFin);
                    if (
                        $horaInicioNormalizada === null
                        || $horaFinNormalizada === null
                        || $horaFinNormalizada <= $horaInicioNormalizada
                    ) {
                        $error = 'Cada día seleccionado necesita una franja horaria válida.';
                        break;
                    }
                    $values['horarios'][$diaNumero]['hora_inicio'] = $horaInicioNormalizada;
                    $values['horarios'][$diaNumero]['hora_fin'] = $horaFinNormalizada;
                    $horarios[] = [
                        'dia_semana' => (int)$diaNumero,
                        'hora_inicio' => $horaInicioNormalizada . ':00',
                        'hora_fin' => $horaFinNormalizada . ':00',
                    ];
                }
                if ($error === '' && !$esIndiferente && $horarios === []) {
                    $error = 'Selecciona al menos un día y una franja horaria semanal.';
                }
                if ($error === '') {
                    $inicio = $this->parseDate($values['fecha_inicio']);
                    $fin = $this->parseDate($values['fecha_fin']);
                    if ($inicio === null || $fin === null || $fin < $inicio) {
                        $error = 'Indica un periodo válido: la fecha final debe ser igual o posterior a la inicial.';
                    } elseif ($inicio < new DateTimeImmutable('today')) {
                        $error = 'La disponibilidad no puede comenzar en una fecha pasada.';
                    } else {
                        try {
                            $this->model->reemplazar(
                                $usuarioId,
                                $inicio->format('Y-m-d'),
                                $fin->format('Y-m-d'),
                                $values['turno'],
                                $esIndiferente,
                                $horarios
                            );
                        } catch (PDOException $exception) {
                            error_log('No se pudo guardar disponibilidad: ' . $exception->getMessage());
                            if ($jsonRequest) {
                                $message = in_array(getenv('APP_ENV'), ['development', 'local'], true)
                                    ? $exception->getMessage()
                                    : 'No se pudo guardar la disponibilidad.';
                                JsonResponse::failure($message, 500);
                                return;
                            }
                            $error = 'No se pudo guardar la disponibilidad. Inténtalo de nuevo.';
                        }
                        if ($error === '') {
                            if ($jsonRequest) {
                                $dayNames = [
                                    1 => 'Lunes',
                                    2 => 'Martes',
                                    3 => 'Miércoles',
                                    4 => 'Jueves',
                                    5 => 'Viernes',
                                    6 => 'Sábado',
                                    7 => 'Domingo',
                                ];
                                JsonResponse::success([
                                    'message' => 'Disponibilidad guardada con éxito.',
                                    'savedSchedule' => [
                                        'fecha_inicio' => $inicio->format('Y-m-d'),
                                        'fecha_fin' => $fin->format('Y-m-d'),
                                        'es_indiferente' => $esIndiferente,
                                        'dias' => array_map(
                                            static fn(array $horario): array => [
                                                'dia_semana' => $horario['dia_semana'],
                                                'dia' => $dayNames[$horario['dia_semana']],
                                                'hora_inicio' => $horario['hora_inicio'],
                                                'hora_fin' => $horario['hora_fin'],
                                            ],
                                            $horarios
                                        ),
                                    ],
                                ]);
                                return;
                            }
                            header('Location: index.php?action=disponibilidad&guardada=1');
                            exit;
                        }
                    }
                }
            }
            if ($error !== '' && $jsonRequest) {
                JsonResponse::failure($error, 422);
                return;
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

    private function normalizeTime(string $value): ?string
    {
        if (preg_match('/\A((?:[01]\d|2[0-3]):(?:[0-5][05]))(?::00)?\z/', $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
