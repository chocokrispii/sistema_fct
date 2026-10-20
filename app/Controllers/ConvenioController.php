<?php

require_once __DIR__ . '/../Models/Convenio.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/Webhook.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Core/PhpIni.php';
require_once __DIR__ . '/../../config/storage.php';

class ConvenioController
{
    private PDO $db;
    private Convenio $convenioModel;
    private Empresa $empresaModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->convenioModel = new Convenio($this->db);
        $this->empresaModel = new Empresa($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function gestionarEmpresa(): void
    {
        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->guardarConvenio((int)$empresa['id']);
                header('Location: index.php?action=convenios&enviado=1');
                exit;
            } catch (Throwable $e) {
                error_log('Error al registrar convenio: ' . $e->getMessage());
                if ($e instanceof InvalidArgumentException) {
                    $error = $e->getMessage();
                } elseif ($e instanceof FileScannerUnavailableException) {
                    $error = 'El análisis antivirus no está instalado o configurado. El convenio no se ha guardado; contacta con el administrador.';
                } elseif ($e instanceof FileUploadConfigurationException) {
                    $error = 'Los límites de carga de PHP no permiten recibir el PDF de forma segura. Contacta con el administrador.';
                } else {
                    $error = 'No se pudo registrar el convenio. Comprueba el número y los datos enviados.';
                }
            }
        } elseif (is_string($_GET['error'] ?? null)) {
            $error = $_GET['error'];
        }

        View::render('convenios/empresa', [
            'empresa' => $empresa,
            'sedes' => $this->empresaModel->obtenerSedes((int)$empresa['id']),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'convenios' => $this->convenioModel->listar((int)$empresa['id']),
            'error' => $error,
        ]);
    }

    public function modificarConvenios(): void
    {
        View::render('convenios/modificar', [
            'convenios' => $this->convenioModel->listarModificables(),
        ]);
    }

    public function editar(): void
    {
        $esEmpresa = ($_SESSION['rol'] ?? null) === 'Empresa';
        $empresa = $esEmpresa
            ? $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id'])
            : null;
        if ($esEmpresa && $empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }
        $volverA = $esEmpresa ? 'convenios' : 'modificar-convenios';
        $id = filter_var(
            $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['convenio_id'] ?? null) : ($_GET['id'] ?? null),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $convenio = $id === false ? null : $this->convenioModel->obtener((int)$id);
        if (
            $convenio === null
            || ($esEmpresa && (int)$convenio['empresa_id'] !== (int)$empresa['id'])
        ) {
            header('Location: index.php?action=' . $volverA . '&error=' . rawurlencode('No se encontró el convenio solicitado.'));
            exit;
        }
        $estadoModificable = in_array($convenio['estado'], ['Pendiente', 'Rechazado'], true)
            || (!$esEmpresa && $convenio['estado'] === 'Aprobado');
        if (!$estadoModificable) {
            header('Location: index.php?action=' . $volverA . '&error=' . rawurlencode('Las empresas solo pueden modificar convenios pendientes o rechazados.'));
            exit;
        }
        $empresaId = (int)$convenio['empresa_id'];

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $this->actualizarConvenio($empresaId, (int)$id, $convenio);
                header('Location: index.php?action=' . $volverA . '&modificado=1');
                exit;
            } catch (Throwable $e) {
                error_log('Error al modificar convenio: ' . $e->getMessage());
                if ($e instanceof InvalidArgumentException) {
                    $error = $e->getMessage();
                } elseif ($e instanceof FileScannerUnavailableException) {
                    $error = 'El análisis antivirus no está instalado o configurado. El convenio no se ha guardado; contacta con el administrador.';
                } elseif ($e instanceof FileUploadConfigurationException) {
                    $error = 'Los límites de carga de PHP no permiten recibir el PDF de forma segura. Contacta con el administrador.';
                } else {
                    $error = 'No se pudo modificar el convenio. Comprueba el número y los datos enviados.';
                }
            }
        }

        View::render('convenios/editar', [
            'empresa' => $empresa ?? $this->dbEmpresa($empresaId),
            'convenio' => $convenio,
            'sedes' => $this->empresaModel->obtenerSedes($empresaId),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'error' => $error,
            'volverA' => $volverA,
            'esPersonalCentro' => !$esEmpresa,
        ]);
    }

    private function dbEmpresa(int $empresaId): array
    {
        $stmt = $this->db->prepare('SELECT id, nombre_empresa FROM empresas WHERE id = :id');
        $stmt->execute(['id' => $empresaId]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($empresa)) {
            throw new RuntimeException('No se encontró la empresa del convenio.');
        }
        return $empresa;
    }

    private function guardarConvenio(int $empresaId): void
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        $postLimit = PhpIni::sizeToBytes((string)ini_get('post_max_size'));
        if (is_string($contentLength) && ctype_digit($contentLength) && (int)$contentLength > $postLimit) {
            throw new InvalidArgumentException('La solicitud supera el límite de carga configurado por PHP.');
        }
        $this->validarLimitesCarga();

        $numeroRaw = $_POST['numero_convenio'] ?? '';
        $especialidadId = filter_var(
            $_POST['especialidad_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $sedeRaw = $_POST['sede_id'] ?? '';
        $firmaRaw = $_POST['fecha_firma'] ?? '';
        $inicioRaw = $_POST['fecha_inicio'] ?? '';
        $finRaw = $_POST['fecha_fin'] ?? '';
        if (
            !is_string($numeroRaw)
            || !is_string($sedeRaw)
            || !is_string($firmaRaw)
            || !is_string($inicioRaw)
            || !is_string($finRaw)
        ) {
            throw new InvalidArgumentException('Los datos del convenio no tienen un formato válido.');
        }
        $numero = trim($numeroRaw);
        if ($especialidadId === false || !$this->especialidadModel->existe($especialidadId)) {
            throw new InvalidArgumentException('Selecciona un ciclo formativo válido.');
        }
        $sede = $sedeRaw === ''
            ? null
            : filter_var($sedeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sede === false) {
            throw new InvalidArgumentException('Selecciona una sede válida.');
        }
        $firma = $this->parseDate($firmaRaw);
        $inicio = $this->parseDate($inicioRaw);
        $fin = $this->parseDate($finRaw);
        $upload = $_FILES['archivo'] ?? null;

        if ($numero === '' || mb_strlen($numero) > 100 || $firma === null || $inicio === null || $fin === null) {
            throw new InvalidArgumentException('Completa un número de convenio y fechas válidas.');
        }
        if ($inicio > $fin || $firma > $inicio) {
            throw new InvalidArgumentException('La firma no puede ser posterior al inicio y la fecha final debe ser igual o posterior a la inicial.');
        }
        if ($sede !== null) {
            $belongs = $this->db->prepare('SELECT 1 FROM empresa_sedes WHERE id = :sede AND empresa_id = :empresa AND activa = 1');
            $belongs->execute(['sede' => $sede, 'empresa' => $empresaId]);
            if (!$belongs->fetchColumn()) {
                throw new InvalidArgumentException('La sede seleccionada no pertenece a tu empresa.');
            }
        }
        $existingAgreement = $this->db->prepare(
            'SELECT 1 FROM convenios WHERE empresa_id = :empresa AND especialidad_id = :especialidad LIMIT 1'
        );
        $existingAgreement->execute([
            'empresa' => $empresaId,
            'especialidad' => $especialidadId,
        ]);
        if ($existingAgreement->fetchColumn()) {
            throw new InvalidArgumentException('Ya existe un convenio registrado para ese ciclo formativo.');
        }

        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            if (is_array($upload) && in_array($upload['error'] ?? null, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw new InvalidArgumentException('El archivo supera el límite de carga configurado en el servidor.');
            }
            throw new InvalidArgumentException('Selecciona un PDF válido de hasta 10 MB.');
        }
        if (
            !is_int($upload['size'] ?? null)
            || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 10 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new InvalidArgumentException('El archivo excede el límite de 10 MB o no es una carga válida.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('El documento debe ser un archivo PDF.');
        }

        FileScanner::scan($upload['tmp_name']);

        $storageRoot = fctStoragePath();
        $uploadRoot = $storageRoot . DIRECTORY_SEPARATOR . 'uploads';
        $directory = $uploadRoot . DIRECTORY_SEPARATOR . 'convenios';
        foreach ([$storageRoot, $uploadRoot, $directory] as $privateDirectory) {
            if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700) && !is_dir($privateDirectory)) {
                throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
            }
            if (is_link($privateDirectory) || !chmod($privateDirectory, 0700)) {
                throw new RuntimeException('No se pudieron establecer los permisos privados de almacenamiento.');
            }
        }
        if (!is_dir($directory)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
        }
        [$storedName, $destination] = $this->almacenarArchivo($upload, $directory);

        $this->db->beginTransaction();
        try {
            $convenioId = $this->convenioModel->crear(
                $empresaId,
                $especialidadId,
                $sede === null ? null : (int)$sede,
                $numero,
                $firma->format('Y-m-d'),
                $inicio->format('Y-m-d'),
                $fin->format('Y-m-d'),
                $storedName
            );
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.creado',
                'convenios',
                $convenioId,
                'estado=Pendiente'
            );
            Webhook::enqueue($this->db, 'convenio.creado', [
                'convenio_id' => $convenioId,
                'empresa_id' => $empresaId,
                'especialidad_id' => $especialidadId,
                'estado' => 'Pendiente',
                'fecha_inicio' => $inicio->format('Y-m-d'),
                'fecha_fin' => $fin->format('Y-m-d'),
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_file($destination) && !unlink($destination)) {
                error_log('No se pudo retirar un archivo de convenio tras fallar su registro.');
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException('Ya existe un convenio registrado para ese ciclo formativo.', 0, $e);
            }
            throw $e;
        }
    }

    private function actualizarConvenio(int $empresaId, int $convenioId, array $convenio): void
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        $postLimit = PhpIni::sizeToBytes((string)ini_get('post_max_size'));
        if (is_string($contentLength) && ctype_digit($contentLength) && (int)$contentLength > $postLimit) {
            throw new InvalidArgumentException('La solicitud supera el límite de carga configurado por PHP.');
        }

        $numeroRaw = $_POST['numero_convenio'] ?? '';
        $especialidadId = filter_var(
            $_POST['especialidad_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        $sedeRaw = $_POST['sede_id'] ?? '';
        $firmaRaw = $_POST['fecha_firma'] ?? '';
        $inicioRaw = $_POST['fecha_inicio'] ?? '';
        $finRaw = $_POST['fecha_fin'] ?? '';
        if (
            !is_string($numeroRaw)
            || !is_string($sedeRaw)
            || !is_string($firmaRaw)
            || !is_string($inicioRaw)
            || !is_string($finRaw)
        ) {
            throw new InvalidArgumentException('Los datos del convenio no tienen un formato válido.');
        }

        $numero = trim($numeroRaw);
        if ($especialidadId === false || !$this->especialidadModel->existe($especialidadId)) {
            throw new InvalidArgumentException('Selecciona un ciclo formativo válido.');
        }
        $sede = $sedeRaw === ''
            ? null
            : filter_var($sedeRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($sede === false) {
            throw new InvalidArgumentException('Selecciona una sede válida.');
        }
        $firma = $this->parseDate($firmaRaw);
        $inicio = $this->parseDate($inicioRaw);
        $fin = $this->parseDate($finRaw);
        if ($numero === '' || mb_strlen($numero) > 100 || $firma === null || $inicio === null || $fin === null) {
            throw new InvalidArgumentException('Completa un número de convenio y fechas válidas.');
        }
        if ($inicio > $fin || $firma > $inicio) {
            throw new InvalidArgumentException('La firma no puede ser posterior al inicio y la fecha final debe ser igual o posterior a la inicial.');
        }
        if ($sede !== null) {
            $belongs = $this->db->prepare(
                'SELECT 1 FROM empresa_sedes WHERE id = :sede AND empresa_id = :empresa AND activa = 1'
            );
            $belongs->execute(['sede' => $sede, 'empresa' => $empresaId]);
            if (!$belongs->fetchColumn()) {
                throw new InvalidArgumentException('La sede seleccionada no pertenece a tu empresa.');
            }
        }

        $existingAgreement = $this->db->prepare(
            'SELECT 1 FROM convenios
             WHERE empresa_id = :empresa AND especialidad_id = :especialidad AND id <> :id
             LIMIT 1'
        );
        $existingAgreement->execute([
            'empresa' => $empresaId,
            'especialidad' => $especialidadId,
            'id' => $convenioId,
        ]);
        if ($existingAgreement->fetchColumn()) {
            throw new InvalidArgumentException('Ya existe un convenio registrado para ese ciclo formativo.');
        }

        $upload = $_FILES['archivo'] ?? null;
        $storedName = null;
        $destination = null;
        if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->validarLimitesCarga();
            if (($upload['error'] ?? null) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('No se pudo recibir el PDF. Comprueba el límite de carga.');
            }
            $storageRoot = fctStoragePath();
            $uploadRoot = $storageRoot . DIRECTORY_SEPARATOR . 'uploads';
            $directory = $uploadRoot . DIRECTORY_SEPARATOR . 'convenios';
            foreach ([$storageRoot, $uploadRoot, $directory] as $privateDirectory) {
                if (!is_dir($privateDirectory) && !mkdir($privateDirectory, 0700) && !is_dir($privateDirectory)) {
                    throw new RuntimeException('No se pudo preparar el almacenamiento privado de convenios.');
                }
                if (is_link($privateDirectory) || !chmod($privateDirectory, 0700)) {
                    throw new RuntimeException('No se pudieron establecer los permisos privados de almacenamiento.');
                }
            }
            [$storedName, $destination] = $this->almacenarArchivo($upload, $directory);
        }

        $this->db->beginTransaction();
        try {
            if (!$this->convenioModel->actualizarPendiente(
                $convenioId,
                $empresaId,
                (int)$especialidadId,
                $sede === null ? null : (int)$sede,
                $numero,
                $firma->format('Y-m-d'),
                $inicio->format('Y-m-d'),
                $fin->format('Y-m-d'),
                $storedName,
                ($_SESSION['rol'] ?? null) !== 'Empresa'
            )) {
                throw new RuntimeException('El convenio ya no se puede modificar.');
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.modificado',
                'convenios',
                $convenioId,
                'estado_anterior=' . $convenio['estado'] . '; estado=Pendiente'
            );
            Webhook::enqueue($this->db, 'convenio.modificado', [
                'convenio_id' => $convenioId,
                'empresa_id' => $empresaId,
                'especialidad_id' => (int)$especialidadId,
                'estado_anterior' => $convenio['estado'],
                'estado' => 'Pendiente',
                'fecha_inicio' => $inicio->format('Y-m-d'),
                'fecha_fin' => $fin->format('Y-m-d'),
            ]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_string($destination) && is_file($destination) && !unlink($destination)) {
                error_log('No se pudo retirar el nuevo archivo tras fallar la modificación del convenio.');
            }
            if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new InvalidArgumentException('Ya existe un convenio registrado para ese ciclo formativo.', 0, $e);
            }
            throw $e;
        }

        if (is_string($destination)) {
            $oldFile = $this->rutaPrivadaDelConvenio((string)$convenio['archivo_path']);
            if (is_string($oldFile) && $oldFile !== $destination && is_file($oldFile) && !unlink($oldFile)) {
                error_log('No se pudo retirar el archivo anterior del convenio modificado.');
            }
        }
    }

    /** @return array{0: string, 1: string} */
    private function almacenarArchivo(array $upload, string $directory): array
    {
        if (
            !is_int($upload['size'] ?? null)
            || !is_string($upload['tmp_name'] ?? null)
            || !is_string($upload['name'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 10 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            throw new InvalidArgumentException('El archivo excede el límite de 10 MB o no es una carga válida.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('El documento debe ser un archivo PDF.');
        }
        FileScanner::scan($upload['tmp_name']);

        $storedName = bin2hex(random_bytes(24)) . '.pdf';
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($upload['tmp_name'], $destination)) {
            throw new RuntimeException('No se pudo guardar el archivo del convenio.');
        }
        if (!chmod($destination, 0600)) {
            if (!unlink($destination)) {
                error_log('No se pudo retirar un archivo de convenio sin permisos privados.');
            }
            throw new RuntimeException('No se pudieron establecer los permisos privados del convenio.');
        }
        return [$storedName, $destination];
    }

    private function rutaPrivadaDelConvenio(string $storedName): ?string
    {
        if (preg_match('/\A[a-f0-9]{48}\.pdf\z/', $storedName) !== 1) {
            return null;
        }
        return fctStoragePath() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
            . 'convenios' . DIRECTORY_SEPARATOR . $storedName;
    }

    public function revisar(): void
    {
        View::render('convenios/revisar', [
            'convenios' => $this->convenioModel->listar(null, 'Pendiente'),
            'puedeResolver' => ($_SESSION['rol'] ?? null) === 'Administrador',
        ]);
    }

    public function auditar(): void
    {
        View::render('convenios/observador', [
            'convenios' => $this->convenioModel->listarParaObservador(),
        ]);
    }

    public function resolver(): void
    {
        if (($_SESSION['rol'] ?? null) !== 'Administrador') {
            http_response_code(403);
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('Solo un administrador puede resolver convenios.', 403);
                return;
            }
            throw new RuntimeException('Solo un administrador puede resolver convenios.');
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

        $convenioId = $requestValues['convenio_id'] ?? null;
        $id = is_int($convenioId) || is_string($convenioId)
            ? filter_var($convenioId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
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
        if ($id === false || !in_array($decision, ['Aprobado', 'Rechazado'], true)) {
            if ($jsonRequest) {
                JsonResponse::failure('La decisión de revisión no es válida.', 422);
                return;
            }
            throw new InvalidArgumentException('La decisión de revisión no es válida.');
        }
        if ($decision === 'Rechazado' && $motivo === '') {
            if ($jsonRequest) {
                JsonResponse::failure('Indica el motivo del rechazo.', 422);
                return;
            }
            throw new InvalidArgumentException('Indica el motivo del rechazo.');
        }
        if (preg_match('/\A.{501}/us', $motivo) !== 0) {
            if ($jsonRequest) {
                JsonResponse::failure('El motivo de revisión no puede superar los 500 caracteres.', 422);
                return;
            }
            throw new InvalidArgumentException('El motivo de revisión no puede superar los 500 caracteres.');
        }
        $this->db->beginTransaction();
        try {
            if (!$this->convenioModel->resolver((int)$id, $decision, $motivo, (int)$_SESSION['usuario_id'])) {
                throw new DomainException('El convenio ya fue revisado o no existe.');
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.revisado',
                'convenios',
                (int)$id,
                'estado=' . $decision
            );
            Webhook::enqueue($this->db, 'convenio.revisado', [
                'convenio_id' => (int)$id,
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
            throw $e;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
        if ($jsonRequest) {
            JsonResponse::success([
                'message' => 'El convenio se ha ' . ($decision === 'Aprobado' ? 'aprobado.' : 'rechazado.'),
                'decision' => $decision,
            ]);
            return;
        }
        header('Location: index.php?action=revisar-convenios');
        exit;
    }

    public function descargar(): void
    {
        if (!in_array($_SESSION['rol'] ?? null, ['Administrador', 'Coordinador', 'Empresa'], true)) {
            $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            (new Auditoria($this->db))->registrar(
                $userId === false ? null : (int)$userId,
                'convenio.descarga.denegada',
                'convenios',
                null,
                'motivo=rol_no_autorizado'
            );
            http_response_code(403);
            throw new RuntimeException('No tienes permiso para descargar convenios.');
        }

        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $convenio = $id === false ? null : $this->convenioModel->obtener((int)$id);
        if ($convenio === null) {
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.descarga.denegada',
                'convenios',
                $id === false ? null : (int)$id,
                'motivo=no_encontrado'
            );
            http_response_code(404);
            throw new RuntimeException('El documento solicitado no existe.');
        }
        if ($_SESSION['rol'] === 'Empresa') {
            $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
            if ($empresa === null || (int)$empresa['id'] !== (int)$convenio['empresa_id']) {
                (new Auditoria($this->db))->registrar(
                    (int)$_SESSION['usuario_id'],
                    'convenio.descarga.denegada',
                    'convenios',
                    (int)$id,
                    'motivo=empresa_sin_titularidad'
                );
                http_response_code(403);
                throw new RuntimeException('No tienes permiso para descargar este documento.');
            }
        }

        $storedName = $convenio['archivo_path'];
        $base = realpath(fctStoragePath() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios');
        $candidate = is_string($storedName) && preg_match('/\A[a-f0-9]{48}\.pdf\z/', $storedName) === 1
            ? ($base === false ? false : $base . DIRECTORY_SEPARATOR . $storedName)
            : false;
        $file = $candidate === false ? false : realpath($candidate);
        if (
            $base === false
            || $candidate === false
            || is_link($candidate)
            || $file === false
            || dirname($file) !== $base
            || !is_file($file)
        ) {
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.descarga.denegada',
                'convenios',
                (int)$id,
                'motivo=archivo_no_disponible'
            );
            throw new RuntimeException('No se encontró el archivo del convenio.');
        }

        $handle = fopen($file, 'rb');
        $size = filesize($file);
        if ($handle === false || $size === false) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.descarga.denegada',
                'convenios',
                (int)$id,
                'motivo=archivo_no_legible'
            );
            throw new RuntimeException('No se pudo abrir el archivo del convenio.');
        }
        (new Auditoria($this->db))->registrar(
            (int)$_SESSION['usuario_id'],
            'convenio.descarga',
            'convenios',
            (int)$id,
            'resultado=autorizada'
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="convenio-' . (int)$convenio['id'] . '.pdf"');
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    private function validarLimitesCarga(): void
    {
        if (
            PhpIni::sizeToBytes((string)ini_get('upload_max_filesize')) < 10 * 1024 * 1024
            || PhpIni::sizeToBytes((string)ini_get('post_max_size')) < 12 * 1024 * 1024
            || (int)ini_get('max_file_uploads') < 1
        ) {
            throw new FileUploadConfigurationException('Los límites de carga de PHP no cumplen la configuración requerida.');
        }
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        $errors = DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }
}
