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
    '2027-05-20',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
);
$laterButViable = $engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-05-26',
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

if ($engine->evaluate(
    $offerStart,
    $offerEnd,
    '2027-06-29',
    $offerEnd,
    $offerSchedule,
    $fullSchedule,
    false
) !== null) {
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

echo "OK: matching temporal viable, penalización progresiva y mínimo de horas de prácticas.\n";
