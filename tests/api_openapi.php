<?php

declare(strict_types=1);

$specPath = __DIR__ . '/../public/openapi.json';
$spec = json_decode((string)file_get_contents($specPath), true, 512, JSON_THROW_ON_ERROR);

if (($spec['openapi'] ?? null) !== '3.0.3'
    || !isset($spec['components']['securitySchemes']['BearerToken'])
    || !isset($spec['paths']['/alumnos']['get'], $spec['paths']['/alumnos']['post'])
    || ($spec['x-webhook-events']['events'] ?? []) !== [
        'alumno.alta',
        'convenio.aprobado_firmado',
        'alumno.evaluacion_apto',
        'oferta.nueva_publicada',
    ]
    || ($spec['x-webhook-events']['payload']['required'] ?? []) !== ['event', 'timestamp', 'event_id', 'data']
    || !str_contains($spec['info']['description'] ?? '', 'webhook')) {
    throw new RuntimeException('La especificación OpenAPI no describe el contrato REST requerido.');
}

echo "OK: especificación OpenAPI válida para la API tokenizada.\n";
