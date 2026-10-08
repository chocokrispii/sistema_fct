<?php
// app/Controllers/OfertaController.php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';

class OfertaController {
    private PDO $db;
    private Oferta $ofertaModel;
    private Empresa $empresaModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
        $this->ofertaModel = new Oferta($this->db);
        $this->empresaModel = new Empresa($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function listar(): void {
        $ofertas = $this->ofertaModel->obtenerTodas(true);
        View::render('ofertas/listado', ['ofertas' => $ofertas]);
    }

    public function publicar(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (($_SESSION['rol'] ?? '') !== 'Empresa') {
            header('Location: index.php?action=login');
            exit;
        }

        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            throw new RuntimeException('Tu usuario no tiene un perfil de empresa asociado.');
        }
        $empresaId = (int)$empresa['id'];

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = $this->procesarPublicacion($empresaId);
            if ($error === null) {
                header('Location: index.php?action=publicar-oferta&publicada=1');
                exit;
            }
        } elseif (isset($_GET['publicada'])) {
            $error = '';
        }

        View::render('ofertas/publicar', [
            'empresa' => $empresa,
            'error' => $error,
            'ofertas' => $this->ofertaModel->obtenerPorEmpresa($empresaId),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
        ]);
    }

    public function revisar(): void
    {
        View::render('ofertas/revisar', ['ofertas' => $this->ofertaModel->obtenerPendientes()]);
    }

    public function resolverRevision(): void
    {
        $id = filter_var($_POST['oferta_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decision = $_POST['decision'] ?? '';
        $motivoRaw = $_POST['motivo'] ?? '';
        if (!is_string($motivoRaw)) {
            throw new InvalidArgumentException('El motivo de revisión no es válido.');
        }
        $motivo = trim($motivoRaw);
        if ($id === false || !in_array($decision, ['Aprobada', 'Rechazada'], true)) {
            throw new InvalidArgumentException('La decisión de revisión no es válida.');
        }
        if ($decision === 'Rechazada' && $motivo === '') {
            throw new InvalidArgumentException('Indica el motivo del rechazo.');
        }
        $this->db->beginTransaction();
        try {
            if ($decision === 'Aprobada') {
                $stmt = $this->db->prepare(
                    'SELECT empresa_id, especialidad_id, fecha_inicio, fecha_fin
                     FROM empresa_especialidades_plazas WHERE id = :id FOR UPDATE'
                );
                $stmt->execute(['id' => $id]);
                $offer = $stmt->fetch(PDO::FETCH_ASSOC);
                $agreement = null;
                if ($offer) {
                    $agreement = $this->db->prepare(
                        "SELECT 1 FROM convenios
                         WHERE empresa_id = :empresa_id AND especialidad_id = :especialidad_id
                           AND estado = 'Aprobado'
                           AND fecha_inicio <= :inicio AND fecha_fin >= :fin
                         LIMIT 1 FOR UPDATE"
                    );
                    $agreement->execute([
                        'empresa_id' => $offer['empresa_id'],
                        'especialidad_id' => $offer['especialidad_id'],
                        'inicio' => $offer['fecha_inicio'],
                        'fin' => $offer['fecha_fin'],
                    ]);
                }
                if (!$offer || !$agreement->fetchColumn()) {
                    throw new RuntimeException('No se puede aprobar una oferta sin un convenio aprobado para su ciclo que cubra todo el periodo.');
                }
            }
            if (!$this->ofertaModel->resolver((int)$id, $decision, $motivo, (int)$_SESSION['usuario_id'])) {
                throw new RuntimeException('La oferta ya fue revisada o no existe.');
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.revisada',
                'ofertas',
                (int)$id,
                'estado=' . $decision
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        header('Location: index.php?action=revisar-ofertas');
        exit;
    }

    /** Devuelve el mensaje de error o null si la oferta se ha creado. */
    private function procesarPublicacion(int $empresaId): ?string {
        $especialidadId = filter_var($_POST['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plazas = filter_var($_POST['plazas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $fechaInicioRaw = $_POST['fecha_inicio'] ?? '';
        $fechaFinRaw = $_POST['fecha_fin'] ?? '';
        $turno = $_POST['turno'] ?? '';
        if (!is_string($fechaInicioRaw) || !is_string($fechaFinRaw) || !is_string($turno)) {
            return 'Los datos de fecha y turno no son válidos.';
        }
        $fechaInicio = $this->parsearFecha($fechaInicioRaw);
        $fechaFin = $this->parsearFecha($fechaFinRaw);

        if ($especialidadId === false || !$this->especialidadModel->existe($especialidadId)) {
            return 'Selecciona una especialidad válida.';
        }

        if ($plazas === false) {
            return 'El número de plazas debe ser un entero entre 1 y 100.';
        }
        if ($fechaInicio === null || $fechaFin === null) {
            return 'Las fechas de inicio y fin deben tener un formato válido.';
        }
        if ($fechaInicio < new DateTimeImmutable('today')) {
            return 'La fecha de inicio no puede ser anterior a hoy.';
        }
        if ($fechaFin <= $fechaInicio) {
            return 'La fecha de fin debe ser posterior a la fecha de inicio.';
        }
        if (!in_array($turno, ['M', 'T', 'Indiferente'], true)) {
            return 'Selecciona un turno válido.';
        }
        if (!$this->empresaModel->tieneConvenioParaFechas(
            $empresaId,
            $especialidadId,
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d')
        )) {
            return 'Necesitas un convenio aprobado para ese ciclo y vigente durante todo el periodo de la oferta.';
        }

        $this->db->beginTransaction();
        try {
            $ofertaId = $this->ofertaModel->crearOferta(
                $empresaId,
                $especialidadId,
                $plazas,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d'),
                $turno
            );
            if ($ofertaId < 1) {
                $this->db->rollBack();
                return 'No se pudo publicar la oferta.';
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.creada',
                'ofertas',
                $ofertaId
            );
            $this->db->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al registrar la oferta: ' . $e->getMessage());
            return 'No se pudo publicar la oferta.';
        }
    }

    private function parsearFecha(string $valor): ?DateTimeImmutable {
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', trim($valor));
        $errores = DateTimeImmutable::getLastErrors();
        if (!$fecha || ($errores !== false && ($errores['warning_count'] > 0 || $errores['error_count'] > 0))) {
            return null;
        }
        return $fecha;
    }

}
