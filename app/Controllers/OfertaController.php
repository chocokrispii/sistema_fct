<?php
// app/Controllers/OfertaController.php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Core/Webhook.php';

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
        if (($_SESSION['rol'] ?? null) === 'Alumno') {
            $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
            $hoy = date('Y-m-d');
            $asignacionesActivas = (new Asignacion($this->db))->listarActivasPorUsuario($usuarioId) !== [];
            $tieneDisponibilidad = (new Disponibilidad($this->db))
                ->obtenerVigentesPorUsuario($usuarioId, $hoy) !== [];
            $ofertas = [];
            if (!$asignacionesActivas && $tieneDisponibilidad) {
                $ofertasConPlazas = array_values(array_filter(
                    array_map(static function (array $oferta): array {
                        $oferta['plazas_disponibles'] = max(
                            0,
                            (int)$oferta['plazas_cuantas'] - (int)$oferta['asignadas']
                        );
                        return $oferta;
                    }, $this->ofertaModel->obtenerVigentesConAsignaciones()),
                    static fn(array $oferta): bool => $oferta['plazas_disponibles'] > 0
                ));
                $idsCompatibles = array_fill_keys(
                    $this->ofertaModel->obtenerIdsOfertasVigentesCompatibles(
                        $usuarioId,
                        array_map(static fn(array $oferta): int => (int)$oferta['id'], $ofertasConPlazas)
                    ),
                    true
                );
                $ofertas = array_values(array_filter(
                    $ofertasConPlazas,
                    static fn(array $oferta): bool => isset($idsCompatibles[(int)$oferta['id']])
                ));
            }
            View::render('ofertas/listado', [
                'ofertas' => $ofertas,
                'soloCompatibles' => true,
                'tieneAsignacionActiva' => $asignacionesActivas,
                'tieneDisponibilidad' => $tieneDisponibilidad,
            ]);
            return;
        }

        $resultado = $this->ofertaModel->obtenerPaginaTodas(
            true,
            Paginacion::desdeEntrada($_GET['pagina'] ?? null)
        );
        View::render('ofertas/listado', [
            'ofertas' => $resultado['items'],
            'paginacion' => $resultado,
            'soloCompatibles' => false,
            'tieneAsignacionActiva' => false,
            'tieneDisponibilidad' => false,
        ]);
    }

    public function publicar(): void {
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

        $resultadoOfertas = $this->ofertaModel->obtenerPaginaPorEmpresa(
            $empresaId,
            Paginacion::desdeEntrada($_GET['pagina'] ?? null)
        );
        View::render('ofertas/publicar', [
            'empresa' => $empresa,
            'error' => $error,
            'ofertas' => $resultadoOfertas['items'],
            'paginacion' => $resultadoOfertas,
            'especialidades' => $this->especialidadModel->obtenerTodas(),
        ]);
    }

    public function nuevaParaCentro(): void
    {
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = $this->procesarOfertaCentro();
            if ($error === null) {
                header('Location: index.php?action=nueva-oferta&creada=1');
                exit;
            }
        }

        View::render('ofertas/nueva-centro', [
            'empresas' => $this->empresaModel->obtenerTodasParaCentro(),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'error' => $error,
        ]);
    }

    public function revisar(): void
    {
        $resultado = $this->ofertaModel->obtenerPaginaPendientes(
            Paginacion::desdeEntrada($_GET['pagina'] ?? null)
        );
        View::render('ofertas/revisar', [
            'ofertas' => $resultado['items'],
            'paginacion' => $resultado,
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
        ]);
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
                     FROM empresa_especialidades_plazas
                     WHERE id = :id AND estado = \'Pendiente\'
                     FOR UPDATE'
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
                    throw new DomainException($offer
                        ? 'No se puede aprobar: falta un convenio aprobado para el mismo ciclo y que cubra todo el periodo.'
                        : 'La oferta no existe o ya fue revisada.');
                }
            }
            if (!$this->ofertaModel->resolver((int)$id, $decision, $motivo, (int)$_SESSION['usuario_id'])) {
                throw new DomainException('La oferta ya fue revisada o no existe.');
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.revisada',
                'ofertas',
                (int)$id,
                'estado=' . $decision
            );
            Webhook::enqueue($this->db, 'oferta.revisada', [
                'oferta_id' => (int)$id,
                'estado' => $decision,
            ]);
            $this->db->commit();
        } catch (DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            header('Location: index.php?action=revisar-ofertas&error=' . rawurlencode($e->getMessage()));
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        header('Location: index.php?action=revisar-ofertas');
        exit;
    }

    private function procesarOfertaCentro(): ?string
    {
        if (!$this->ofertaModel->tieneDatosDescriptivos()) {
            return 'La creación de ofertas desde el centro requiere aplicar la migración 011.';
        }

        $empresaId = filter_var(
            $_POST['empresa_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $especialidadId = filter_var(
            $_POST['especialidad_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $plazas = filter_var(
            $_POST['plazas'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 100]]
        );
        $tituloRaw = $_POST['titulo'] ?? null;
        $lugarRaw = $_POST['lugar'] ?? null;
        $fechaInicioRaw = $_POST['fecha_inicio'] ?? null;
        $fechaFinRaw = $_POST['fecha_fin'] ?? null;
        $turno = $_POST['turno'] ?? null;
        if (
            !is_string($tituloRaw)
            || !is_string($lugarRaw)
            || !is_string($fechaInicioRaw)
            || !is_string($fechaFinRaw)
            || !is_string($turno)
        ) {
            return 'Completa los datos de la oferta con valores válidos.';
        }

        $titulo = trim($tituloRaw);
        $lugar = trim($lugarRaw);
        $fechaInicio = $this->parsearFecha($fechaInicioRaw);
        $fechaFin = $this->parsearFecha($fechaFinRaw);
        $empresas = $this->empresaModel->obtenerTodasParaCentro();
        $empresaValida = $empresaId !== false && array_filter(
            $empresas,
            static fn(array $empresa): bool => (int)$empresa['id'] === $empresaId
        ) !== [];

        if (!$empresaValida) {
            return 'Selecciona una empresa válida.';
        }
        if ($titulo === '' || mb_strlen($titulo) > 150) {
            return 'El nombre de la oferta es obligatorio y no puede superar 150 caracteres.';
        }
        if ($lugar === '' || mb_strlen($lugar) > 255) {
            return 'El lugar de la oferta es obligatorio y no puede superar 255 caracteres.';
        }
        if ($especialidadId === false || !$this->especialidadModel->existe($especialidadId)) {
            return 'Selecciona un ciclo formativo válido.';
        }
        if ($plazas === false) {
            return 'El número de vacantes debe ser un entero entre 1 y 100.';
        }
        if ($fechaInicio === null || $fechaFin === null) {
            return 'Las fechas de inicio y fin deben tener un formato válido.';
        }
        if ($fechaInicio < new DateTimeImmutable('today') || $fechaFin <= $fechaInicio) {
            return 'Indica un periodo futuro cuya fecha de fin sea posterior a la de inicio.';
        }
        if (!in_array($turno, ['M', 'T', 'Indiferente'], true)) {
            return 'Selecciona un turno válido.';
        }
        if (!$this->empresaModel->tieneConvenioParaFechas(
            (int)$empresaId,
            (int)$especialidadId,
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d')
        )) {
            return 'La empresa necesita un convenio aprobado para ese ciclo y durante todo el periodo indicado.';
        }

        $this->db->beginTransaction();
        try {
            $ofertaId = $this->ofertaModel->crearOferta(
                (int)$empresaId,
                (int)$especialidadId,
                (int)$plazas,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d'),
                $turno,
                $titulo,
                $lugar
            );
            if ($ofertaId < 1) {
                $this->db->rollBack();
                return 'No se pudo registrar la oferta.';
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.creada',
                'ofertas',
                $ofertaId
            );
            Webhook::enqueue($this->db, 'oferta.creada', [
                'oferta_id' => $ofertaId,
                'empresa_id' => (int)$empresaId,
                'especialidad_id' => (int)$especialidadId,
                'estado' => 'Pendiente',
                'titulo' => $titulo,
                'lugar' => $lugar,
                'plazas' => (int)$plazas,
                'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                'fecha_fin' => $fechaFin->format('Y-m-d'),
                'turno' => $turno,
            ]);
            $this->db->commit();
            return null;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al crear una oferta desde el centro: ' . $e->getMessage());
            return 'No se pudo registrar la oferta.';
        }
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
            Webhook::enqueue($this->db, 'oferta.creada', [
                'oferta_id' => $ofertaId,
                'empresa_id' => $empresaId,
                'especialidad_id' => (int)$especialidadId,
                'estado' => 'Pendiente',
                'plazas' => (int)$plazas,
                'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                'fecha_fin' => $fechaFin->format('Y-m-d'),
                'turno' => $turno,
            ]);
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
