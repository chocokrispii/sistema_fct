<?php
// app/Controllers/OfertaController.php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Models/Asignacion.php';
require_once __DIR__ . '/../Models/Disponibilidad.php';
require_once __DIR__ . '/../Core/Webhook.php';
require_once __DIR__ . '/../Core/JsonResponse.php';

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
                'ofertas' => $this->withWeeklySchedules($ofertas),
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
        $resultado['items'] = $this->withWeeklySchedules($resultado['items']);
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
        $formValues = $this->formValues($_POST);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = $this->procesarPublicacion($empresaId, $_POST);
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
            'ofertas' => $this->withWeeklySchedules($resultadoOfertas['items']),
            'paginacion' => $resultadoOfertas,
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'formValues' => $formValues,
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
            'ofertas' => $this->withWeeklySchedules($resultado['items']),
            'paginacion' => $resultado,
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
        ]);
    }

    public function resolverRevision(): void
    {
        if (($_SESSION['rol'] ?? null) !== 'Administrador') {
            http_response_code(403);
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('Solo un administrador puede resolver ofertas.', 403);
                return;
            }
            throw new RuntimeException('Solo un administrador puede resolver ofertas.');
        }
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

        $offerId = $requestValues['oferta_id'] ?? null;
        $id = is_int($offerId) || is_string($offerId)
            ? filter_var($offerId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $decisionValue = $requestValues['decision'] ?? '';
        $decision = is_string($decisionValue) ? $decisionValue : '';
        $motivoRaw = $requestValues['motivo'] ?? '';
        if (!is_string($motivoRaw)) {
            if ($jsonRequest) {
                JsonResponse::failure('El motivo de revisión no es válido.', 422);
                return;
            }
            throw new InvalidArgumentException('El motivo de revisión no es válido.');
        }
        $motivo = trim($motivoRaw);
        if ($id === false || !in_array($decision, ['Aprobada', 'Rechazada'], true)) {
            if ($jsonRequest) {
                JsonResponse::failure('La decisión de revisión no es válida.', 422);
                return;
            }
            throw new InvalidArgumentException('La decisión de revisión no es válida.');
        }
        if ($decision === 'Rechazada' && $motivo === '') {
            if ($jsonRequest) {
                JsonResponse::failure('Indica el motivo del rechazo.', 422);
                return;
            }
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
                    $shiftCheck = $this->db->prepare(
                        'SELECT 1 FROM empresa_oferta_horarios_semanales oh
                         WHERE oh.oferta_id = :id
                           AND (
                               DATEDIFF(:fin, :inicio) >= 6
                               OR MOD(oh.dia_semana - 1 - WEEKDAY(:inicio_calculo) + 7, 7)
                                  <= DATEDIFF(:rango_fin, :rango_inicio)
                           )
                         LIMIT 1'
                    );
                    $shiftCheck->execute([
                        'id' => $id,
                        'fin' => $offer['fecha_fin'],
                        'inicio' => $offer['fecha_inicio'],
                        'inicio_calculo' => $offer['fecha_inicio'],
                        'rango_fin' => $offer['fecha_fin'],
                        'rango_inicio' => $offer['fecha_inicio'],
                    ]);
                    if (!$shiftCheck->fetchColumn()) {
                        throw new DomainException('No se puede aprobar una oferta sin un horario exacto aplicable a sus fechas.');
                    }
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
            if ($jsonRequest) {
                JsonResponse::failure($e->getMessage(), 409);
                return;
            }
            header('Location: index.php?action=revisar-ofertas&error=' . rawurlencode($e->getMessage()));
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        if ($jsonRequest) {
            JsonResponse::success([
                'message' => 'La oferta se ha ' . ($decision === 'Aprobada' ? 'aprobado.' : 'rechazado.'),
                'decision' => $decision,
            ]);
            return;
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
    private function procesarPublicacion(int $empresaId, array $request): ?string {
        $especialidadId = filter_var($request['especialidad_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $plazas = filter_var($request['plazas'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $fechaInicioRaw = $request['fecha_inicio'] ?? '';
        $fechaFinRaw = $request['fecha_fin'] ?? '';
        $horariosRaw = $request['horarios'] ?? null;
        $tituloRaw = $request['titulo'] ?? null;
        $descripcionRaw = $request['descripcion'] ?? null;
        $turnoRaw = $request['turno'] ?? null;
        $jornadaRaw = $request['jornada'] ?? 'normal';
        $modalidadRaw = $request['modalidad'] ?? null;
        $ubicacionRaw = $request['ubicacion'] ?? null;
        $requisitosRaw = $request['requisitos'] ?? '';
        $tecnologiasRaw = $request['tecnologias'] ?? '';
        if (!is_string($fechaInicioRaw) || !is_string($fechaFinRaw) || !is_array($horariosRaw)) {
            return 'Los datos de fecha y horario no son válidos.';
        }
        if (
            !is_string($tituloRaw)
            || !is_string($descripcionRaw)
            || (!is_string($turnoRaw) && $turnoRaw !== null)
            || !is_string($jornadaRaw)
            || !is_string($modalidadRaw)
            || !is_string($ubicacionRaw)
            || !is_string($requisitosRaw)
            || !is_string($tecnologiasRaw)
        ) {
            return 'Los detalles de la oferta no tienen un formato válido.';
        }
        $titulo = trim($tituloRaw);
        $descripcion = trim($descripcionRaw);
        $turno = $turnoRaw ?? 'Indiferente';
        $jornada = $jornadaRaw;
        $modalidad = $modalidadRaw;
        $ubicacion = trim($ubicacionRaw);
        $requisitos = trim($requisitosRaw);
        $tecnologias = $this->normalizeTechnologyTags($tecnologiasRaw);
        if (
            $titulo === ''
            || preg_match('/\A.{1,150}\z/us', $titulo) !== 1
            || preg_match('/\A.{1,2000}\z/us', $descripcion) !== 1
        ) {
            return 'Indica un título de hasta 150 caracteres y una descripción de hasta 2.000 caracteres.';
        }
        if (!in_array($turno, ['M', 'T', 'P', 'Indiferente'], true)) {
            return 'Selecciona un tipo de turno válido.';
        }
        if (!in_array($jornada, ['normal', 'partida'], true)) {
            return 'Selecciona una jornada válida.';
        }
        if (!in_array($modalidad, ['Presencial', 'Remoto', 'Híbrido'], true)) {
            return 'Selecciona una modalidad válida.';
        }
        if (
            ($modalidad !== 'Remoto' && $ubicacion === '')
            || preg_match('/\A.{0,255}\z/us', $ubicacion) !== 1
        ) {
            return 'Indica una ubicación de hasta 255 caracteres para las modalidades presencial e híbrida.';
        }
        if (preg_match('/\A.{0,2000}\z/us', $requisitos) !== 1 || $tecnologias === false) {
            return 'Los requisitos no pueden superar 2.000 caracteres y las tecnologías deben ser hasta 10 etiquetas de 50 caracteres.';
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
        $horarios = [];
        foreach ($horariosRaw as $dia => $horario) {
            $diaNumero = filter_var($dia, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
            if ($diaNumero === false || !is_array($horario)) {
                return 'Selecciona días y horarios semanales válidos.';
            }
            $activo = $horario['activo'] ?? '';
            if (!in_array($activo, ['', '1'], true)) {
                return 'Selecciona días y horarios semanales válidos.';
            }
            if ($activo !== '1') {
                continue;
            }
            $horaInicio = $horario['hora_inicio'] ?? null;
            $horaFin = $horario['hora_fin'] ?? null;
            $horaInicio2 = $horario['hora_inicio_2'] ?? '';
            $horaFin2 = $horario['hora_fin_2'] ?? '';
            if (
                !is_string($horaInicio)
                || !is_string($horaFin)
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaInicio) !== 1
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaFin) !== 1
                || $horaFin <= $horaInicio
            ) {
                return 'Cada día seleccionado necesita una hora válida en intervalos de cinco minutos.';
            }
            $horarioNormalizado = [
                'dia_semana' => (int)$diaNumero,
                'hora_inicio' => $horaInicio . ':00',
                'hora_fin' => $horaFin . ':00',
            ];
            if ($jornada === 'partida') {
                if (
                    !is_string($horaInicio2)
                    || !is_string($horaFin2)
                    || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaInicio2) !== 1
                    || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaFin2) !== 1
                    || $horaFin <= $horaInicio
                    || $horaInicio2 < $horaFin
                    || $horaFin2 <= $horaInicio2
                ) {
                    return 'Cada día seleccionado necesita dos franjas válidas, no solapadas y en orden horario.';
                }
                $horarioNormalizado['hora_inicio_2'] = $horaInicio2 . ':00';
                $horarioNormalizado['hora_fin_2'] = $horaFin2 . ':00';
            }
            $horarios[] = $horarioNormalizado;
        }
        if ($horarios === []) {
            return 'Selecciona al menos un día y una franja horaria semanal.';
        }
        if (!$this->empresaModel->tieneConvenioParaFechas(
            $empresaId,
            $especialidadId,
            $fechaInicio->format('Y-m-d'),
            $fechaFin->format('Y-m-d')
        )) {
            return 'Necesitas un convenio aprobado para ese ciclo y vigente durante todo el periodo de la oferta.';
        }

        $cicloStmt = $this->db->prepare(
            'SELECT nombre_especialidad FROM especialidades WHERE id = :id'
        );
        $cicloStmt->execute(['id' => $especialidadId]);
        $ciclo = $cicloStmt->fetchColumn();
        if (!is_string($ciclo)) {
            return 'El ciclo formativo seleccionado ya no está disponible.';
        }

        $this->db->beginTransaction();
        try {
            $ofertaId = $this->ofertaModel->crearOferta(
                $empresaId,
                $especialidadId,
                $plazas,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d'),
                $titulo,
                $descripcion,
                $turno,
                $modalidad,
                $ubicacion !== '' ? $ubicacion : null,
                $requisitos !== '' ? $requisitos : null,
                $tecnologias !== '' ? $tecnologias : null,
                $horarios,
                $jornada
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
                'ciclo' => $ciclo,
                'estado' => 'Pendiente',
                'plazas' => (int)$plazas,
                'fecha_inicio' => $fechaInicio->format('Y-m-d'),
                'fecha_fin' => $fechaFin->format('Y-m-d'),
                'turno' => $turno,
                'jornada' => $jornada,
                'titulo' => $titulo,
                'descripcion' => $descripcion,
                'modalidad' => $modalidad,
                'ubicacion' => $ubicacion !== '' ? $ubicacion : null,
                'requisitos_tecnologicos' => $requisitos !== '' ? $requisitos : null,
                'tecnologias' => $tecnologias !== '' ? $tecnologias : null,
                'horarios' => $horarios,
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

    private function withWeeklySchedules(array $offers): array
    {
        foreach ($offers as &$offer) {
            $offer['horarios'] = $this->ofertaModel->obtenerHorarioSemanal((int)$offer['id']);
        }
        unset($offer);
        return $offers;
    }

    private function formValues(array $request): array
    {
        $fields = ['titulo', 'descripcion', 'especialidad_id', 'plazas', 'fecha_inicio', 'fecha_fin', 'turno', 'jornada', 'modalidad', 'ubicacion', 'requisitos', 'tecnologias'];
        $values = [];
        foreach ($fields as $field) {
            $values[$field] = isset($request[$field]) && is_string($request[$field])
                ? $request[$field]
                : '';
        }

        return $values;
    }

    private function normalizeTechnologyTags(string $value): string|false
    {
        $value = trim($value);
        if (preg_match('/\A.{0,500}\z/us', $value) !== 1) {
            return false;
        }
        if ($value === '') {
            return '';
        }

        $tags = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            static fn(string $tag): bool => $tag !== ''
        )));
        if (count($tags) > 10) {
            return false;
        }
        foreach ($tags as $tag) {
            if (preg_match('/\A.{1,50}\z/us', $tag) !== 1) {
                return false;
            }
        }

        return implode(', ', $tags);
    }
}
