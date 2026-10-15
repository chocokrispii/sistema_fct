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

        $ofertas = (new Oferta($db))->obtenerTodas(true);
        $asignaciones = (new Asignacion($db))->listar(true, $usuarioId);
        $calificaciones = (new Calificacion($db))->listar(true, $usuarioId);
        $disponibilidades = (new Disponibilidad($db))->obtenerPorUsuario($usuarioId);

        $notas = array_values(array_filter(
            array_map(static fn(array $c) => $c['nota'], $calificaciones),
            static fn($nota) => $nota !== null
        ));

        $hoy = date('Y-m-d');
        $eventos = [];
        foreach ($asignaciones as $a) {
            if ($a['estado'] !== 'Asignada') {
                continue;
            }
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
                ?: strcmp($x['titulo'], $y['titulo'])
                ?: strcmp($x['detalle'], $y['detalle']);
        });

        View::render('home/dashboard_alumno', [
            'totalOfertas' => count($ofertas),
            'totalEmpresas' => count(array_unique(array_column($ofertas, 'empresa_id'))),
            'asignacionesActivas' => count(array_filter($asignaciones, static fn(array $a) => $a['estado'] === 'Asignada')),
            'notaMedia' => $notas === [] ? null : array_sum(array_map('floatval', $notas)) / count($notas),
            'ofertasVigentes' => array_slice($ofertas, 0, 4),
            'eventos' => array_slice($eventos, 0, 5),
            'tieneDisponibilidad' => $disponibilidades !== [],
        ]);
    }
}
