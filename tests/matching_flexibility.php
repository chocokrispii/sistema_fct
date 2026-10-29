<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Services/MatchingService.php';

$engine = new MatchingService(5, 20, 80);
$offerSchedule = [['dia_semana' => 1, 'hora_inicio' => '09:00:00', 'hora_fin' => '17:00:00']];
$fullSchedule = [['dia_semana' => 1, 'hora_inicio' => '08:00:00', 'hora_fin' => '18:00:00']];

foreach ([1, 2, 3, 4, 5] as $offset) {
    $result = $engine->evaluate('2027-05-20', '2027-06-30', '2027-05-' . (20 + $offset), '2027-06-30', $offerSchedule, $fullSchedule, false);
    if ($result === null || $result['desfase_inicio_dias'] !== $offset || $result['compatibilidad_porcentaje'] >= 100) {
        throw new RuntimeException('Falló el escenario flexible de ' . $offset . ' días.');
    }
}

if ($engine->evaluate('2027-05-20', '2027-06-30', '2027-05-26', '2027-06-30', $offerSchedule, $fullSchedule, false) !== null) {
    throw new RuntimeException('Un desfase superior a la tolerancia no debe aceptarse.');
}
if ($engine->evaluate('2027-05-20', '2027-06-30', '2027-05-20', '2027-06-30', $offerSchedule, [['dia_semana' => 1, 'hora_inicio' => '12:00:00', 'hora_fin' => '17:00:00']], false) !== null) {
    throw new RuntimeException('Una cobertura horaria inferior al mínimo no debe aceptarse.');
}
$indifferent = $engine->evaluate('2027-05-20', '2027-06-30', '2027-05-21', '2027-06-30', $offerSchedule, [], true);
if ($indifferent === null || $indifferent['compatibilidad_horaria'] !== 100) {
    throw new RuntimeException('El horario indiferente debe ser compatible.');
}

echo "OK: matching flexible de fechas y horarios adaptativos.\n";
