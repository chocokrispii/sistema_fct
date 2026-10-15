<?php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Calificacion.php';

class InicioController
{
    public function mostrar(): void
    {
        if (($_SESSION['rol'] ?? null) !== 'Alumno') {
            View::render('home/index');
            return;
        }

        $db = getPDOConnection();
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $asignaciones = (new Asignacion($db))->listarActivasPorUsuario($usuarioId);
        $asignacionesActivas = count($asignaciones);

        $ofertas = [];
        if ($asignacionesActivas === 0) {
            $ofertaModel = new Oferta($db);
            $ofertas = array_values(array_filter(
                array_map(static function (array $oferta): array {
                    $oferta['plazas_disponibles'] = max(
                        0,
                        (int)$oferta['plazas_cuantas'] - (int)$oferta['asignadas']
                    );
                    return $oferta;
                }, $ofertaModel->obtenerVigentesConAsignaciones()),
                static fn(array $oferta): bool => $oferta['plazas_disponibles'] > 0
            ));
            $candidatosPorOferta = $ofertas === []
                ? []
                : $ofertaModel->obtenerCandidatosVigentes($usuarioId);
            $ofertas = array_values(array_filter(
                $ofertas,
                static fn(array $oferta): bool => isset($candidatosPorOferta[(int)$oferta['id']])
            ));
        }
        $notaMedia = (new Calificacion($db))->obtenerNotaMediaPorUsuario($usuarioId);
        $disponibilidades = (new Disponibilidad($db))->obtenerPorUsuario($usuarioId);
        $hoy = date('Y-m-d');

        $eventos = [];
        foreach ($asignaciones as $a) {
            if ($a['fecha_inicio'] >= $hoy) {
                $eventos[] = ['fecha' => $a['fecha_inicio'], 'titulo' => 'Inicio de prácticas', 'detalle' => $a['nombre_empresa']];
            }
            if ($a['fecha_fin'] >= $hoy) {
                $eventos[] = ['fecha' => $a['fecha_fin'], 'titulo' => 'Fin de prácticas', 'detalle' => $a['nombre_empresa']];
            }
        }
        foreach ($disponibilidades as $d) {
            if ($d['fecha_inicio'] >= $hoy) {
                $eventos[] = ['fecha' => $d['fecha_inicio'], 'titulo' => 'Inicio de disponibilidad', 'detalle' => 'Periodo registrado'];
            }
            if ($d['fecha_fin'] >= $hoy) {
                $eventos[] = ['fecha' => $d['fecha_fin'], 'titulo' => 'Fin de disponibilidad', 'detalle' => 'Periodo registrado'];
            }
        }
        usort($eventos, static function (array $x, array $y): int {
            return strcmp($x['fecha'], $y['fecha'])
                ?: ((str_starts_with($x['titulo'], 'Fin de') ? 1 : 0)
                    <=> (str_starts_with($y['titulo'], 'Fin de') ? 1 : 0))
                ?: strcmp($x['titulo'], $y['titulo'])
                ?: strcmp($x['detalle'], $y['detalle']);
        });

        View::render('home/dashboard_alumno', [
            'totalOfertas' => count($ofertas),
            'totalEmpresas' => count(array_unique(array_column($ofertas, 'empresa_id'))),
            'asignacionesActivas' => $asignacionesActivas,
            'notaMedia' => $notaMedia,
            'ofertasVigentes' => array_slice($ofertas, 0, 4),
            'eventos' => array_slice($eventos, 0, 5),
            'tieneAsignacionActiva' => $asignacionesActivas > 0,
            'tieneDisponibilidad' => array_filter(
                $disponibilidades,
                static fn(array $disponibilidad): bool => $disponibilidad['fecha_fin'] >= $hoy
            ) !== [],
        ]);
    }
}
