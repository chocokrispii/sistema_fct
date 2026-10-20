<?php
$previewMode = true;
$error = '';
$ofertas = [];
$formValues = [
    'titulo' => 'Prácticas de desarrollo web',
    'descripcion' => 'Participación en el desarrollo y mantenimiento de aplicaciones web junto al equipo técnico.',
    'especialidad_id' => 'daw',
    'plazas' => '2',
    'fecha_inicio' => $fechaInicio,
    'fecha_fin' => $fechaFin,
    'turno' => 'M',
    'modalidad' => 'Híbrido',
    'ubicacion' => 'Madrid · Centro tecnológico',
    'requisitos' => 'Conocimientos básicos de programación y trabajo en equipo.',
    'tecnologias' => 'PHP, Vue, MySQL',
];
require __DIR__ . '/../ofertas/publicar.php';
