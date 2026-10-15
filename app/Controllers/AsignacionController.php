<?php

require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Oferta.php';

class AsignacionController
{
    private PDO $db;
    private Asignacion $model;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->model = new Asignacion($this->db);
    }

    public function listar(): void
    {
        $resultado = $this->model->obtenerPagina(
            false,
            0,
            Paginacion::desdeEntrada($_GET['pagina'] ?? null)
        );
        View::render('asignaciones/listado', [
            'soloPropias' => false,
            'puedeGestionar' => in_array($_SESSION['rol'], ['Administrador', 'Coordinador'], true),
            'asignaciones' => $resultado['items'],
            'paginacion' => $resultado,
            'error' => '',
        ]);
    }

    public function misAsignaciones(): void
    {
        $resultado = $this->model->obtenerPagina(
            true,
            (int)$_SESSION['usuario_id'],
            Paginacion::desdeEntrada($_GET['pagina'] ?? null)
        );
        View::render('asignaciones/listado', [
            'soloPropias' => true,
            'puedeGestionar' => false,
            'asignaciones' => $resultado['items'],
            'paginacion' => $resultado,
            'error' => '',
        ]);
    }

    public function emparejar(): void
    {
        $errorRaw = $_GET['error'] ?? '';
        $error = is_string($errorRaw) ? $errorRaw : '';
        $ofertaModel = new Oferta($this->db);
        $resultado = $ofertaModel->obtenerPaginaTodas(
            true,
            Paginacion::desdeEntrada($_GET['pagina'] ?? null),
            10
        );
        $ofertas = $resultado['items'];
        $ofertaIds = array_map(static fn(array $oferta): int => (int)$oferta['id'], $ofertas);
        $asignadasPorOferta = $ofertaModel->contarAsignacionesPorOferta($ofertaIds);
        $paginasCandidatosRaw = $_GET['candidatos_pagina'] ?? [];
        $paginasCandidatosRaw = is_array($paginasCandidatosRaw) ? $paginasCandidatosRaw : [];
        $ofertaIdsConPlazas = [];
        $paginasCandidatos = [];

        foreach ($ofertas as &$oferta) {
            $ofertaId = (int)$oferta['id'];
            $oferta['asignadas'] = $asignadasPorOferta[$ofertaId] ?? 0;
            $oferta['plazas_disponibles'] = max(0, (int)$oferta['plazas_cuantas'] - $oferta['asignadas']);
            $oferta['candidatos'] = [];
            if ($oferta['plazas_disponibles'] > 0) {
                $ofertaIdsConPlazas[] = $ofertaId;
                $paginasCandidatos[$ofertaId] = Paginacion::desdeEntrada(
                    $paginasCandidatosRaw[$ofertaId] ?? null
                );
            }
        }
        unset($oferta);

        $candidatosPorOferta = $ofertaModel->obtenerCandidatosPaginados(
            $ofertaIdsConPlazas,
            $paginasCandidatos
        );
        foreach ($ofertas as &$oferta) {
            $ofertaId = (int)$oferta['id'];
            if (isset($candidatosPorOferta[$ofertaId])) {
                $oferta['candidatos'] = $candidatosPorOferta[$ofertaId]['candidatos'];
                $oferta['paginacionCandidatos'] = $candidatosPorOferta[$ofertaId];
            }
        }
        unset($oferta);

        View::render('asignaciones/matching', [
            'ofertas' => $ofertas,
            'paginacionOfertas' => $resultado,
            'error' => $error,
        ]);
    }

    public function asignar(): void
    {
        $offerId = filter_var($_POST['oferta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $studentId = filter_var($_POST['alumno_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $tutorRaw = $_POST['tutor_empresa'] ?? '';
        if (!is_string($tutorRaw)) {
            throw new InvalidArgumentException('El tutor de empresa no es válido.');
        }
        $tutor = trim($tutorRaw);
        if ($offerId === false || $studentId === false || $tutor === '' || mb_strlen($tutor) > 150) {
            throw new InvalidArgumentException('Revisa los datos de la asignación y el tutor de empresa.');
        }

        try {
            $this->model->crear(
                (int)$offerId,
                (int)$studentId,
                (int)$_SESSION['usuario_id'],
                $tutor
            );
        } catch (Throwable $e) {
            error_log('No se pudo asignar alumno: ' . $e->getMessage());
            $safeMessages = [
                'La oferta no existe o no está aprobada.',
                'La oferta ya no tiene plazas disponibles.',
                'No se encontró el expediente del alumno.',
                'El alumno no cumple el ciclo, disponibilidad o turno de la oferta.',
            ];
            $message = in_array($e->getMessage(), $safeMessages, true)
                ? $e->getMessage()
                : 'No se pudo completar la asignación. Inténtalo de nuevo.';
            header('Location: index.php?action=emparejar&error=' . rawurlencode($message));
            exit;
        }

        header('Location: index.php?action=asignaciones');
        exit;
    }

    public function cerrar(): void
    {
        $id = filter_var($_POST['asignacion_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !$this->model->cerrar((int)$id, (int)$_SESSION['usuario_id'])) {
            throw new RuntimeException('La asignación no existe o ya está cerrada.');
        }
        header('Location: index.php?action=asignaciones');
        exit;
    }

}
