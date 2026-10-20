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

        $ofertaModel = new Oferta($db);
        $ofertaResumen = $ofertaModel->obtenerResumenVigentes();
        $ofertasRecientes = $ofertaModel->obtenerRecientesVigentes();
        $asignacionModel = new Asignacion($db);
        $eventos = $asignacionModel->obtenerProximosEventos($usuarioId);
        $notaMedia = (new Calificacion($db))->obtenerNotaMediaPorUsuario($usuarioId);
        $disponibilidadModel = new Disponibilidad($db);

        View::render('home/dashboard_alumno', [
            'totalOfertas' => (int)$ofertaResumen['total_ofertas'],
            'totalEmpresas' => (int)$ofertaResumen['total_empresas'],
            'asignacionesActivas' => $asignacionModel->contarActivasPorUsuario($usuarioId),
            'notaMedia' => $notaMedia,
            'ofertasRecientes' => $ofertasRecientes,
            'eventos' => $eventos,
            'tieneDisponibilidad' => $disponibilidadModel->tienePorUsuario($usuarioId),
        ]);
    }
}
