<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/MatchingService.php';

$engine = new MatchingService(2, 80);
$offerStart = '2027-05-17';
$offerEnd = '2027-06-30';
$offerSchedule = [
    ['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '17:00:00'],
    ['dia_semana' => 3, 'hora_inicio' => '09:00:00', 'hora_fin' => '15:00:00'],
];
$fullSchedule = [
    ['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '18:00:00'],
    ['dia_semana' => 3, 'hora_inicio' => '08:00:00', 'hora_fin' => '18:00:00'],
];

$exact = $engine->evaluate(
    $offerStart,
    $offerEnd,
    $offerStart,
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
if ($exact === null || $exact['compatibilidad_porcentaje'] !== 100 || $exact['compatibilidad_fechas'] !== 100) {
    throw new RuntimeException('La incorporación el día de inicio debe conservar una coincidencia del 100%.');
}

$lateButViable = $engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-05-19',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
$laterButViable = $engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-05-20',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
if ($lateButViable === null || $laterButViable === null
    || $lateButViable['compatibilidad_porcentaje'] >= 100
    || $laterButViable['compatibilidad_porcentaje'] >= $lateButViable['compatibilidad_porcentaje']
    || $lateButViable['horas_posibles'] < $lateButViable['horas_minimas_requeridas']) {
    throw new RuntimeException('La incorporación tardía viable debe conservar el match con una penalización progresiva.');
}
$beyondTolerance = $engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-05-23',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
if ($beyondTolerance !== null) {
    throw new RuntimeException('La incorporación posterior a la tolerancia configurada debe rechazarse.');
}

$earlyFinish = $engine->evaluate('2027-05-20', '2027-06-30', '2027-05-20', '2027-06-25', $offerSchedule, $fullSchedule, false);
if (
    $earlyFinish === null
    || $earlyFinish['desfase_fin_dias'] !== 5
    || $earlyFinish['fecha_fin_adaptada'] !== '2027-06-25'
) {
    throw new RuntimeException('Debe aceptarse un fin anticipado dentro de la tolerancia y adaptar el periodo.');
}
if ($engine->evaluate('2027-05-20', '2027-06-30', '2027-05-20', '2027-06-24', $offerSchedule, $fullSchedule, false) !== null) {
    throw new RuntimeException('Un fin anticipado superior a la tolerancia no debe aceptarse.');
}
$minimumOverlap = (new MatchingService(
    minimumPracticeDays: 1,
    minimumSchedulePercent: 80,
    toleranceDays: 5,
    minimumOverlapDays: 25
))->evaluate(
    '2027-05-20',
    '2027-06-30',
    '2027-05-25',
    '2027-06-30',
    $offerSchedule,
    $fullSchedule,
    false
);
if ($minimumOverlap === null || $minimumOverlap['dias_solapados'] !== 37) {
    throw new RuntimeException('El mínimo de días solapados debe ser inclusivo.');
}
if ((new MatchingService(
    minimumPracticeDays: 1,
    minimumSchedulePercent: 80,
    toleranceDays: 5,
    minimumOverlapDays: 38
))->evaluate(
    '2027-05-20',
    '2027-06-30',
    '2027-05-25',
    '2027-06-30',
    $offerSchedule,
    $fullSchedule,
    false
) !== null) {
    throw new RuntimeException('Un solapamiento menor que el mínimo configurado no debe aceptarse.');
}
if ($engine->evaluate('2027-05-20', '2027-06-30', '2027-05-20', '2027-06-30', $offerSchedule, [['dia_semana' => 1, 'hora_inicio' => '12:00:00', 'hora_fin' => '17:00:00']], false) !== null) {
    throw new RuntimeException('Una cobertura horaria inferior al mínimo no debe aceptarse.');
}

$insufficientPracticeDays = (new MatchingService(
    minimumPracticeDays: 2,
    minimumSchedulePercent: 80,
    toleranceDays: 30,
))->evaluate(
    $offerStart,
    $offerEnd,
    '2027-06-29',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
if ($insufficientPracticeDays !== null) {
    throw new RuntimeException('Debe descartarse al alumno que no dispone del tiempo mínimo de prácticas.');
}

$indifferent = $engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-05-20',
    $offerEnd,
    $offerSchedule,
    [],
    true
);
if ($indifferent === null || $indifferent['compatibilidad_horaria'] !== 100) {
    throw new RuntimeException('El horario indiferente debe seguir siendo compatible.');
}

echo "OK: tolerancia temporal, solapamiento, penalización progresiva y mínimos de prácticas y horario.\n";
