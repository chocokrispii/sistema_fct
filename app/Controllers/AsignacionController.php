<?php

require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Services/DocumentacionInicioService.php';

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
        $anonimizarAlumnado = ($_SESSION['rol'] ?? null) === 'Observador';
        $resultado = $this->model->obtenerPagina(
            false,
            0,
            Paginacion::desdeEntrada($_GET['pagina'] ?? null),
            20,
            ($_SESSION['rol'] ?? null) === 'Observador'
        );
        View::render('asignaciones/listado', [
            'soloPropias' => false,
            'anonimizarAlumnado' => $anonimizarAlumnado,
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
            'anonimizarAlumnado' => false,
            'puedeGestionar' => false,
            'asignaciones' => $resultado['items'],
            'paginacion' => $resultado,
            'error' => '',
        ]);
    }

    public function empresaAsignada(): void
    {
        $company = $this->model->obtenerEmpresaAsignada((int)$_SESSION['usuario_id']);
        if ($company === null) {
            JsonResponse::failure('No tienes una práctica activa asignada.', 404);
            return;
        }
        JsonResponse::success($company);
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
            $oferta['horarios'] = $ofertaModel->obtenerHorarioSemanal($ofertaId);
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
        $jsonRequest = JsonResponse::wantsJson();
        $requestValues = $_POST;
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (str_contains($contentType, 'application/json')) {
            try {
                $decoded = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                JsonResponse::failure('El cuerpo JSON no es válido.', 400);
                return;
            }
            if (!$decoded instanceof stdClass) {
                JsonResponse::failure('El cuerpo JSON debe ser un objeto de datos.', 400);
                return;
            }
            $requestValues = get_object_vars($decoded);
        }

        $offerValue = $requestValues['oferta_id'] ?? null;
        $studentValue = $requestValues['alumno_id'] ?? null;
        $offerId = is_int($offerValue) || is_string($offerValue)
            ? filter_var($offerValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $studentId = is_int($studentValue) || is_string($studentValue)
            ? filter_var($studentValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $tutorRaw = $requestValues['tutor_empresa'] ?? '';
        if (!is_string($tutorRaw)) {
            if ($jsonRequest) {
                JsonResponse::failure('El tutor de empresa no es válido.', 422);
                return;
            }
            throw new InvalidArgumentException('El tutor de empresa no es válido.');
        }
        $tutor = trim($tutorRaw);
        if ($offerId === false || $studentId === false || $tutor === '' || mb_strlen($tutor) > 150) {
            if ($jsonRequest) {
                JsonResponse::failure('Revisa los datos de la asignación y el tutor de empresa.', 422);
                return;
            }
            throw new InvalidArgumentException('Revisa los datos de la asignación y el tutor de empresa.');
        }

        try {
            $assignmentId = $this->model->crear(
                (int)$offerId,
                (int)$studentId,
                (int)$_SESSION['usuario_id'],
                $tutor
            );
            (new DocumentacionInicioService($this->model->getDb()))->generarYNotificar($assignmentId, (int)$_SESSION['usuario_id']);
        } catch (Throwable $e) {
            error_log('No se pudo asignar alumno: ' . $e->getMessage());
            $safeMessages = [
                'La oferta no existe o no está aprobada.',
                'La oferta ya no tiene plazas disponibles.',
                'No se encontró el expediente del alumno.',
                'El alumno no cumple el ciclo, disponibilidad o cobertura horaria de la oferta.',
            ];
            $message = in_array($e->getMessage(), $safeMessages, true)
                ? $e->getMessage()
                : 'No se pudo completar la asignación. Inténtalo de nuevo.';
            if ($jsonRequest) {
                $status = $e instanceof DomainException
                    ? 422
                    : (in_array($e->getMessage(), $safeMessages, true) ? 409 : 500);
                JsonResponse::failure($message, $status);
                return;
            }
            header('Location: index.php?action=emparejar&error=' . rawurlencode($message));
            exit;
        }

        if ($jsonRequest) {
            JsonResponse::success(['message' => 'El alumno se ha asignado correctamente.'], 201);
            return;
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
