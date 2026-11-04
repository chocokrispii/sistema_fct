<?php
// app/Controllers/OfertaController.php

require_once __DIR__ . '/../Models/Oferta.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';

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
        $ofertas = $this->withWeeklySchedules($this->ofertaModel->obtenerTodas(true));
        View::render('ofertas/listado', ['ofertas' => $ofertas]);
    }

    public function publicar(): void {
        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            throw new RuntimeException('Tu usuario no tiene un perfil de empresa asociado.');
        }
        $empresaId = (int)$empresa['id'];

        $error = '';
        $sedeError = '';
        $formValues = $this->formValues($_POST);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $error = $this->procesarPublicacion($empresaId, $_POST);
            if ($error !== null && str_contains(mb_strtolower($error), 'sede')) {
                $sedeError = $error;
            }
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
            'sedeError' => $sedeError,
            'sedes' => $this->empresaModel->obtenerSedes($empresaId),
            'ofertas' => $this->withWeeklySchedules($this->ofertaModel->obtenerPorEmpresa($empresaId)),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'formValues' => $formValues,
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
            if ($decision === 'Aprobada' && is_array($offer)) {
                WebhookPublisher::publish($this->db, 'oferta.nueva_publicada', [
                    'oferta_id' => (int)$id,
                    'empresa_id' => (int)$offer['empresa_id'],
                    'especialidad_id' => (int)$offer['especialidad_id'],
                ]);
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.revisada',
                'ofertas',
                (int)$id,
                'estado_anterior=Pendiente;estado_nuevo=' . $decision,
                $decision === 'Aprobada' ? 'aprobación' : 'rechazo'
            );
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

    public function actualizarHorarioRevision(): void
    {
        if (($_SESSION['rol'] ?? null) !== 'Administrador') {
            http_response_code(403);
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('Solo un administrador puede actualizar horarios de ofertas.', 403);
                return;
            }
            throw new RuntimeException('Solo un administrador puede actualizar horarios de ofertas.');
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
        $rawSchedule = $requestValues['horarios'] ?? null;
        if ($id === false || !is_array($rawSchedule)) {
            $this->responderErrorHorario('Indica una oferta y franjas horarias válidas.', $jsonRequest, 422, $id);
            return;
        }

        try {
            $schedule = $this->parsearHorarioRevision($rawSchedule);
        } catch (InvalidArgumentException $exception) {
            $this->responderErrorHorario($exception->getMessage(), $jsonRequest, 422, (int)$id);
            return;
        }

        $this->db->beginTransaction();
        try {
            $previous = $this->ofertaModel->reemplazarHorarioPendiente((int)$id, $schedule);
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'oferta.horario_actualizado',
                'ofertas',
                (int)$id,
                'horario_anterior=' . $this->formatearHorarioAuditoria($previous)
                    . ';horario_nuevo=' . $this->formatearHorarioAuditoria($schedule),
                'actualización de horario'
            );
            $this->db->commit();
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->responderErrorHorario($exception->getMessage(), $jsonRequest, 409, (int)$id);
            return;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }

        if ($jsonRequest) {
            JsonResponse::success(['message' => 'El horario semanal se ha actualizado.']);
            return;
        }
        header('Location: index.php?action=revisar-ofertas');
        exit;
    }

    private function parsearHorarioRevision(array $rawSchedule): array
    {
        $schedule = [];
        foreach ($rawSchedule as $day => $values) {
            $dayNumber = filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]);
            if ($dayNumber === false || !is_array($values)) {
                throw new InvalidArgumentException('Las franjas semanales no tienen un formato válido.');
            }
            $active = $values['activo'] ?? '';
            if (!in_array($active, ['', '1'], true)) {
                throw new InvalidArgumentException('Las franjas semanales no tienen un formato válido.');
            }
            if ($active !== '1') {
                continue;
            }
            $start = $values['hora_inicio'] ?? null;
            $end = $values['hora_fin'] ?? null;
            if (
                !is_string($start)
                || !is_string($end)
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $start) !== 1
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $end) !== 1
                || $end <= $start
            ) {
                throw new InvalidArgumentException('Cada día seleccionado necesita horas válidas en intervalos de cinco minutos.');
            }
            $schedule[] = [
                'dia_semana' => (int)$dayNumber,
                'hora_inicio' => $start . ':00',
                'hora_fin' => $end . ':00',
            ];
        }
        if ($schedule === []) {
            throw new InvalidArgumentException('Selecciona al menos un día y una franja horaria semanal.');
        }
        usort($schedule, static fn(array $left, array $right): int => $left['dia_semana'] <=> $right['dia_semana']);
        return $schedule;
    }

    private function formatearHorarioAuditoria(array $schedule): string
    {
        if ($schedule === []) {
            return 'sin_horario';
        }
        $dayNames = ['', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo'];
        $entries = array_map(
            static fn(array $item): string => $dayNames[(int)$item['dia_semana']]
                . ':' . substr((string)$item['hora_inicio'], 0, 5)
                . '-' . substr((string)$item['hora_fin'], 0, 5),
            $schedule
        );
        return implode(',', $entries);
    }

    private function responderErrorHorario(string $message, bool $jsonRequest, int $status, int|false|null $offerId): void
    {
        if ($jsonRequest) {
            JsonResponse::failure($message, $status);
            return;
        }
        $query = ['error' => $message];
        if (is_int($offerId) && $offerId > 0) {
            $query['oferta_id'] = $offerId;
        }
        header('Location: index.php?action=revisar-ofertas&' . http_build_query($query));
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
        $modalidadRaw = $request['modalidad'] ?? null;
        $sedeRaw = $request['sede_id'] ?? null;
        $newSedeNameRaw = $request['nueva_sede_nombre'] ?? '';
        $newSedeAddressRaw = $request['nueva_sede_direccion'] ?? '';
        $newSedePhoneRaw = $request['nueva_sede_telefono'] ?? '';
        $requisitosRaw = $request['requisitos'] ?? '';
        $tecnologiasRaw = $request['tecnologias'] ?? '';
        if (!is_string($fechaInicioRaw) || !is_string($fechaFinRaw) || !is_array($horariosRaw)) {
            return 'Los datos de fecha y horario no son válidos.';
        }
        if (
            !is_string($tituloRaw)
            || !is_string($descripcionRaw)
            || !is_string($turnoRaw)
            || !is_string($modalidadRaw)
            || !is_string($requisitosRaw)
            || !is_string($tecnologiasRaw)
        ) {
            return 'Los detalles de la oferta no tienen un formato válido.';
        }
        if (!is_string($sedeRaw)) {
            return 'Selecciona una sede activa asociada a tu empresa.';
        }
        if (!is_string($newSedeNameRaw) || !is_string($newSedeAddressRaw) || !is_string($newSedePhoneRaw)) {
            return 'Los datos de la sede no tienen un formato válido.';
        }
        $titulo = trim($tituloRaw);
        $descripcion = trim($descripcionRaw);
        $turno = $turnoRaw;
        $modalidad = $modalidadRaw;
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
        if (!in_array($modalidad, ['Presencial', 'Remoto', 'Híbrido'], true)) {
            return 'Selecciona una modalidad válida.';
        }
        $newSedeName = trim($newSedeNameRaw);
        $newSedeAddress = trim($newSedeAddressRaw);
        $newSedePhone = trim($newSedePhoneRaw);
        $crearSede = $sedeRaw === 'nueva';
        $sedeId = $crearSede
            ? null
            : filter_var($sedeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($crearSede) {
            if (preg_match('/\A.{1,150}\z/us', $newSedeName) !== 1
                || preg_match('/\A.{1,255}\z/us', $newSedeAddress) !== 1
                || preg_match('/\A.{0,20}\z/us', $newSedePhone) !== 1) {
                return 'Completa el nombre y la dirección de la nueva sede (hasta 150 y 255 caracteres) y revisa el teléfono.';
            }
        } elseif ($sedeId === false || $this->empresaModel->obtenerSedeActiva($empresaId, (int)$sedeId) === null) {
            return 'Selecciona una sede activa asociada a tu empresa.';
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
            if (
                !is_string($horaInicio)
                || !is_string($horaFin)
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaInicio) !== 1
                || preg_match('/^(?:[01]\d|2[0-3]):(?:[0-5][05])$/', $horaFin) !== 1
                || $horaFin <= $horaInicio
            ) {
                return 'Cada día seleccionado necesita una hora válida en intervalos de cinco minutos.';
            }
            $horarios[] = [
                'dia_semana' => (int)$diaNumero,
                'hora_inicio' => $horaInicio . ':00',
                'hora_fin' => $horaFin . ':00',
            ];
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
            if ($crearSede) {
                $sedeId = $this->empresaModel->crearSede(
                    $empresaId,
                    $newSedeName,
                    $newSedeAddress,
                    $newSedePhone
                );
            }
            if (!is_int($sedeId) || $sedeId < 1) {
                throw new DomainException('Selecciona una sede válida para la oferta.');
            }
            $ofertaId = $this->ofertaModel->crearOferta(
                $empresaId,
                $sedeId,
                $especialidadId,
                $plazas,
                $fechaInicio->format('Y-m-d'),
                $fechaFin->format('Y-m-d'),
                $titulo,
                $descripcion,
                $turno,
                $modalidad,
                $requisitos !== '' ? $requisitos : null,
                $tecnologias !== '' ? $tecnologias : null,
                $horarios
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
        $fields = [
            'titulo',
            'descripcion',
            'especialidad_id',
            'plazas',
            'fecha_inicio',
            'fecha_fin',
            'turno',
            'modalidad',
            'sede_id',
            'nueva_sede_nombre',
            'nueva_sede_direccion',
            'nueva_sede_telefono',
            'requisitos',
            'tecnologias',
        ];
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
