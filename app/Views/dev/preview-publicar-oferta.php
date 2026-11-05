<?php
$previewMode = true;
$error = '';
$sedeError = '';
$sedes = [['id' => 0, 'nombre' => 'Sede de demostración', 'direccion' => 'Madrid · Centro tecnológico']];
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
    'sede_id' => '0',
    'nueva_sede_nombre' => '',
    'nueva_sede_direccion' => '',
    'nueva_sede_telefono' => '',
    'requisitos' => 'Conocimientos básicos de programación y trabajo en equipo.',
    'tecnologias' => 'PHP, Vue, MySQL',
];
require __DIR__ . '/../ofertas/publicar.php';
