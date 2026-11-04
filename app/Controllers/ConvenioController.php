<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/Convenio.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Core/PhpIni.php';
require_once __DIR__ . '/../Core/ConvenioApprovalMailer.php';
require_once __DIR__ . '/../Services/SimplePdfApprovalStampProvider.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';
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
        }

        View::render('convenios/empresa', [
            'empresa' => $empresa,
            'sedes' => $this->empresaModel->obtenerSedes((int)$empresa['id']),
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'convenios' => $this->convenioModel->listar((int)$empresa['id']),
            'error' => $error,
        ]);
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

    public function revisar(): void
    {
        $this->renderListado('pendiente_revision');
    }

    public function archivo(): void
    {
        $this->requireAdministrador('El archivo de convenios está reservado a administración.');
        $this->renderListado('todos');
    }

    public function index(): void
    {
        $this->renderListado('todos');
    }

    public function auditar(): void
    {
        View::render('convenios/observador', [
            'convenios' => $this->convenioModel->listarParaObservador(),
        ]);
    }

    public function resolver(): void
    {
        $this->ejecutarTransicion(['Aprobado', 'Rechazado']);
    }

    public function aprobar(): void
    {
        $this->ejecutarTransicion(['Aprobado']);
    }

    public function firmar(): void
    {
        $this->ejecutarTransicion(['Aprobado']);
    }

    public function rechazar(): void
    {
        $this->ejecutarTransicion(['Rechazado']);
    }

    public function archivar(): void
    {
        $this->ejecutarTransicion(['Archivado']);
    }

    private function ejecutarTransicion(array $estadosPermitidos): void
    {
        if (!$this->esAdministrador()) {
            http_response_code(403);
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('Solo un administrador puede cambiar el estado de los convenios.', 403);
                return;
            }
            throw new RuntimeException('Solo un administrador puede cambiar el estado de los convenios.');
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

        $convenioId = $requestValues['convenio_id'] ?? $requestValues['id'] ?? null;
        $id = is_int($convenioId) || is_string($convenioId)
            ? filter_var($convenioId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $decisionValue = $requestValues['decision'] ?? $estadosPermitidos[0];
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
        if ($id === false || !in_array($decision, $estadosPermitidos, true)) {
            if ($jsonRequest) {
                JsonResponse::failure('La acción sobre el convenio no es válida.', 422);
                return;
            }
            throw new InvalidArgumentException('La acción sobre el convenio no es válida.');
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
        if ($decision === 'Aprobado') {
            $this->firmarConvenio((int)$id, $jsonRequest);
            return;
        }

        $this->db->beginTransaction();
        try {
            $estadoAnterior = $this->convenioModel->transicionar(
                (int)$id,
                $decision,
                $motivo,
                (int)$_SESSION['usuario_id']
            );
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                $decision === 'Archivado' ? 'convenio.archivado' : 'convenio.revisado',
                'convenios',
                (int)$id,
                'estado_anterior=' . $estadoAnterior . ';estado_nuevo=' . $decision
            );
            if ($decision === 'Aprobado') {
                WebhookPublisher::publish($this->db, 'convenio.aprobado_firmado', ['convenio_id' => (int)$id]);
            }
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
            $verb = match ($decision) {
                'Aprobado' => 'aprobado',
                'Rechazado' => 'rechazado',
                default => 'archivado',
            };
            JsonResponse::success([
                'message' => 'El convenio se ha ' . $verb . '.',
                'estado' => $decision,
                'estado_anterior' => $estadoAnterior,
            ]);
            return;
        }
        header('Location: index.php?action=revisar-convenios&estado=' . rawurlencode(
            $decision === 'Archivado' ? 'archivado' : 'pendiente_revision'
        ));
        exit;
    }

    private function firmarConvenio(int $id, bool $jsonRequest): void
    {
        $administratorId = filter_var(
            $_SESSION['usuario_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if ($administratorId === false) {
            http_response_code(403);
            if ($jsonRequest) {
                JsonResponse::failure('La sesión de administrador no es válida.', 403);
                return;
            }
            throw new RuntimeException('La sesión de administrador no es válida.');
        }

        $temporaryPath = null;
        $signedPath = null;
        $commitAttempted = false;
        try {
            $this->db->beginTransaction();
            $convenio = $this->convenioModel->obtenerPendienteParaFirma($id);
            if ($convenio === null || $convenio['estado'] !== 'Pendiente') {
                throw new DomainException('El convenio no existe o ya no está pendiente de aprobación.');
            }
            if (!is_string($convenio['empresa_email']) || !filter_var($convenio['empresa_email'], FILTER_VALIDATE_EMAIL)) {
                throw new DomainException('La empresa no tiene un correo válido para recibir el enlace privado. El convenio sigue pendiente.');
            }

            $sourcePath = $this->resolverRutaConvenioPrivada((string)$convenio['archivo_path']);
            $originalHash = hash_file('sha256', $sourcePath);
            if (!is_string($originalHash)) {
                throw new RuntimeException('No se pudo calcular el hash del documento original.');
            }
            $administratorName = $this->obtenerNombreAdministrador((int)$administratorId);
            $signedAtUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $storageDirectory = dirname($sourcePath);
            $temporaryPath = tempnam($storageDirectory, '.firma-convenio-');
            if (!is_string($temporaryPath)) {
                throw new RuntimeException('No se pudo crear el archivo temporal de la firma.');
            }
            $resolvedTemporaryPath = realpath($temporaryPath);
            if ($resolvedTemporaryPath === false || dirname($resolvedTemporaryPath) !== $storageDirectory) {
                if (is_file($temporaryPath) && !unlink($temporaryPath)) {
                    error_log('No se pudo retirar un archivo temporal de firma fuera del almacenamiento privado.');
                }
                throw new RuntimeException('El archivo temporal de la firma quedó fuera del almacenamiento privado.');
            }
            $temporaryPath = $resolvedTemporaryPath;

            (new SimplePdfApprovalStampProvider())->stampApproval(
                $sourcePath,
                $temporaryPath,
                (string)$convenio['numero_convenio'],
                (int)$administratorId,
                $administratorName,
                $signedAtUtc,
                $originalHash
            );
            $signedHash = hash_file('sha256', $temporaryPath);
            $size = filesize($temporaryPath);
            $handle = fopen($temporaryPath, 'rb');
            $signature = $handle === false ? false : fread($handle, 5);
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (
                !is_string($signedHash)
                || $size === false
                || $size < 1
                || $signature !== '%PDF-'
            ) {
                throw new RuntimeException('El PDF sellado no pasó la validación de integridad.');
            }

            $storedName = bin2hex(random_bytes(24)) . '.pdf';
            $signedPath = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
            if (
                file_exists($signedPath)
                || !rename($temporaryPath, $signedPath)
                || !chmod($signedPath, 0600)
            ) {
                throw new RuntimeException('No se pudo guardar el PDF sellado en el almacenamiento privado.');
            }
            $temporaryPath = null;

            $estadoAnterior = $this->convenioModel->registrarFirma(
                $id,
                $storedName,
                $originalHash,
                $signedHash,
                (int)$administratorId,
                $signedAtUtc
            );
            (new Auditoria($this->db))->registrar(
                (int)$administratorId,
                'convenio.firmado.aprobado',
                'convenios',
                $id,
                'admin_id=' . (int)$administratorId
                    . ';timestamp_utc=' . $signedAtUtc->format('Y-m-d\TH:i:s\Z')
                    . ';document_hash_pre_signature=' . $originalHash
                    . ';document_hash_post_signature=' . $signedHash
                    . ';estado_anterior=' . $estadoAnterior
                    . ';estado_nuevo=Aprobado'
            );

            $commitAttempted = true;
            $this->db->commit();
        } catch (DomainException $exception) {
            $transactionActive = $this->db->inTransaction();
            $this->rollbackFirma($temporaryPath, $signedPath, $transactionActive || !$commitAttempted);
            if ($jsonRequest) {
                JsonResponse::failure($exception->getMessage(), 409);
                return;
            }
            header('Location: index.php?action=revisar-convenios&firma=error');
            exit;
        } catch (Throwable $exception) {
            $transactionActive = $this->db->inTransaction();
            $this->rollbackFirma($temporaryPath, $signedPath, $transactionActive || !$commitAttempted);
            error_log('Error al firmar convenio #' . $id . ': ' . $exception->getMessage());
            if ($jsonRequest) {
                JsonResponse::failure(
                    'No se pudo completar la firma. El convenio permanece pendiente y el documento original se conserva.',
                    500
                );
                return;
            }
            header('Location: index.php?action=revisar-convenios&firma=error');
            exit;
        }

        $notificationSent = true;
        try {
            $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
            $downloadUrl = $appUrl . '/index.php?action=descargar-convenio-firmado&id=' . $id;
            (new ConvenioApprovalMailer())->send(
                (string)$convenio['empresa_email'],
                (string)$convenio['nombre_empresa'],
                (string)$convenio['numero_convenio'],
                $downloadUrl
            );
        } catch (Throwable $exception) {
            $notificationSent = false;
            error_log('No se pudo notificar la aprobación del convenio #' . $id . ': ' . $exception->getMessage());
        }

        $message = $notificationSent
            ? 'El convenio se ha aprobado y sellado. Se envió a la empresa un enlace privado para descargarlo.'
            : 'El convenio se ha aprobado y sellado, pero no se pudo enviar el correo. La empresa puede descargarlo desde su cuenta.';
        if ($jsonRequest) {
            JsonResponse::success([
                'message' => $message,
                'estado' => 'Aprobado',
                'notificacion_enviada' => $notificationSent,
            ]);
            return;
        }
        header(
            'Location: index.php?action=revisar-convenios&firma='
            . ($notificationSent ? 'completada' : 'correo_pendiente')
        );
        exit;
    }

    private function rollbackFirma(?string $temporaryPath, ?string $signedPath, bool $removeSignedFile): void
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }
        $paths = $removeSignedFile ? [$temporaryPath, $signedPath] : [$temporaryPath];
        foreach ($paths as $path) {
            if (is_string($path) && is_file($path) && !unlink($path)) {
                error_log('No se pudo retirar un archivo de firma de convenio tras una reversión.');
            }
        }
    }

    private function obtenerNombreAdministrador(int $administratorId): string
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', NULLIF(nombre, ''), NULLIF(apellidos, ''))), ''),
                email
             )
             FROM usuarios
             WHERE id = :id AND rol = 'Administrador'
             LIMIT 1"
        );
        $stmt->execute(['id' => $administratorId]);
        $name = $stmt->fetchColumn();
        if (!is_string($name) || trim($name) === '') {
            throw new RuntimeException('No se pudo identificar al administrador que firma el convenio.');
        }
        return trim($name);
    }

    private function resolverRutaConvenioPrivada(string $storedName): string
    {
        if (preg_match('/\A[a-f0-9]{48}\.pdf\z/', $storedName) !== 1) {
            throw new RuntimeException('La ruta almacenada del convenio no es válida.');
        }
        $storageRoot = realpath(fctStoragePath());
        $base = $storageRoot === false
            ? false
            : realpath($storageRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios');
        $candidate = $base === false ? false : $base . DIRECTORY_SEPARATOR . $storedName;
        $file = $candidate === false ? false : realpath($candidate);
        if (
            $base === false
            || $candidate === false
            || is_link($candidate)
            || $file === false
            || dirname($file) !== $base
            || !is_file($file)
            || !is_readable($file)
        ) {
            throw new RuntimeException('No se encontró el PDF original del convenio en el almacenamiento privado.');
        }
        return $file;
    }

    public function descargar(): void
    {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$this->esAdministrador()) {
            $this->descargarPdf($id === false ? 0 : (int)$id);
        }
        if ($id === false) {
            http_response_code(400);
            throw new InvalidArgumentException('El identificador del convenio no es válido.');
        }
        $this->descargarPdf((int)$id);
    }

    public function descargarPdf(int $id): void
    {
        if (!$this->esAdministrador()) {
            $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            (new Auditoria($this->db))->registrar(
                $userId === false ? null : (int)$userId,
                'convenio.descarga.denegada',
                'convenios',
                $id > 0 ? $id : null,
                'motivo=rol_no_autorizado'
            );
            http_response_code(403);
            throw new RuntimeException('Solo un administrador puede descargar documentos de convenios.');
        }

        $convenio = $id > 0 ? $this->convenioModel->obtener($id) : null;
        if ($convenio === null) {
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'convenio.descarga.denegada',
                'convenios',
                $id > 0 ? $id : null,
                'motivo=no_encontrado'
            );
            http_response_code(404);
            throw new RuntimeException('El documento solicitado no existe.');
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
                $id,
                'motivo=archivo_no_disponible'
            );
            http_response_code(404);
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
                $id,
                'motivo=archivo_no_legible'
            );
            throw new RuntimeException('No se pudo abrir el archivo del convenio.');
        }
        (new Auditoria($this->db))->registrar(
            (int)$_SESSION['usuario_id'],
            'convenio.descarga',
            'convenios',
            $id,
            'resultado=autorizada'
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="convenio-' . (int)$convenio['id'] . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    public function descargarFirmado(): void
    {
        $userId = filter_var(
            $_SESSION['usuario_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
        if (($_SESSION['rol'] ?? null) !== 'Empresa' || $userId === false) {
            $auditUserId = $userId === false ? null : (int)$userId;
            (new Auditoria($this->db))->registrar(
                $auditUserId,
                'convenio.descarga_firmada.denegada',
                'convenios',
                null,
                'motivo=rol_no_autorizado'
            );
            http_response_code(403);
            throw new RuntimeException('Solo la empresa titular puede descargar el convenio aprobado.');
        }
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            http_response_code(400);
            throw new InvalidArgumentException('El identificador del convenio no es válido.');
        }
        $convenio = $this->convenioModel->obtenerFirmadoParaEmpresa((int)$id, (int)$userId);
        if ($convenio === null) {
            (new Auditoria($this->db))->registrar(
                (int)$userId,
                'convenio.descarga_firmada.denegada',
                'convenios',
                (int)$id,
                'motivo=no_disponible_o_no_autorizado'
            );
            http_response_code(404);
            throw new RuntimeException('No se encontró un convenio aprobado disponible para esta cuenta.');
        }

        $file = $this->resolverRutaConvenioPrivada((string)$convenio['documento_firmado_path']);
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            http_response_code(500);
            throw new RuntimeException('No se pudo abrir el convenio aprobado.');
        }
        $hashContext = hash_init('sha256');
        $hashedBytes = hash_update_stream($hashContext, $handle);
        $actualHash = hash_final($hashContext);
        $fileMetadata = fstat($handle);
        $size = is_array($fileMetadata) ? ($fileMetadata['size'] ?? false) : false;
        $expectedHash = $convenio['hash_documento_firmado'] ?? null;
        if (
            !is_string($expectedHash)
            || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1
            || !hash_equals($expectedHash, $actualHash)
            || !is_int($size)
            || $hashedBytes !== $size
            || $size < 1
            || !rewind($handle)
        ) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            (new Auditoria($this->db))->registrar(
                (int)$userId,
                'convenio.integridad_firma.fallida',
                'convenios',
                (int)$id,
                'motivo=hash_no_coincide'
            );
            http_response_code(500);
            throw new RuntimeException('No se pudo verificar la integridad del convenio aprobado.');
        }
        (new Auditoria($this->db))->registrar(
            (int)$userId,
            'convenio.descarga_firmada',
            'convenios',
            (int)$id,
            'resultado=autorizada'
        );

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Content-Disposition: attachment; filename="convenio-aprobado-' . (int)$id . '.pdf"');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        fpassthru($handle);
        fclose($handle);
        exit;
    }

    private function renderListado(string $estadoPredeterminado): void
    {
        $rol = $_SESSION['rol'] ?? null;
        if (!in_array($rol, ['Administrador', 'Coordinador'], true)) {
            http_response_code(403);
            throw new RuntimeException('No tienes permiso para consultar la gestión de convenios.');
        }
        $filtros = [
            'estado' => $estadoPredeterminado,
            'nif' => '',
            'fecha_desde' => '',
            'fecha_hasta' => '',
            'page' => 1,
            'limit' => 20,
        ];
        $error = '';
        try {
            $filtros['estado'] = $rol === 'Coordinador'
                ? 'pendiente_revision'
                : $this->leerFiltro('estado', $estadoPredeterminado);
            $filtros['nif'] = $this->leerFiltro('nif', '');
            $filtros['fecha_desde'] = $this->leerFiltro('fecha_desde', '');
            $filtros['fecha_hasta'] = $this->leerFiltro('fecha_hasta', '');
            $filtros['page'] = $this->leerFiltroPaginacion('page', 1);
            $filtros['limit'] = $this->leerFiltroPaginacion('limit', 20);
            $resultado = $this->convenioModel->buscarPaginado($filtros);
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $error = $exception->getMessage();
            $resultado = [
                'items' => [],
                'total' => 0,
                'page' => 1,
                'limit' => 20,
                'pages' => 1,
            ];
        }
        $eventosPorConvenio = [];
        $ids = array_map(static fn(array $item): int => (int)$item['id'], $resultado['items']);
        foreach ($this->convenioModel->listarEventos($ids) as $evento) {
            $eventosPorConvenio[(int)$evento['convenio_id']][] = $evento;
        }
        View::render('convenios/listado', [
            'convenios' => $resultado['items'],
            'eventosPorConvenio' => $eventosPorConvenio,
            'filtros' => $filtros,
            'paginacion' => $resultado,
            'error' => $error,
            'puedeResolver' => $rol === 'Administrador',
            'esAdministrador' => $rol === 'Administrador',
            'firmaResultado' => match ($_GET['firma'] ?? '') {
                'completada' => 'El convenio se aprobó y selló; se envió el enlace privado a la empresa.',
                'correo_pendiente' => 'El convenio se aprobó y selló, pero el correo no pudo enviarse. La empresa puede descargarlo desde su cuenta.',
                'error' => 'No se pudo completar la firma. El convenio permanece pendiente y el documento original se conserva.',
                default => '',
            },
            'firmaResultadoTipo' => match ($_GET['firma'] ?? '') {
                'correo_pendiente' => 'warning',
                'error' => 'error',
                default => 'success',
            },
        ]);
    }

    private function leerFiltro(string $nombre, string $predeterminado): string
    {
        $value = $_GET[$nombre] ?? $predeterminado;
        if (!is_string($value)) {
            throw new InvalidArgumentException('Los filtros de convenios no tienen un formato válido.');
        }
        return trim($value);
    }

    private function leerFiltroPaginacion(string $nombre, int $predeterminado): int|string
    {
        $value = $_GET[$nombre] ?? $predeterminado;
        return is_int($value) || is_string($value) ? $value : '';
    }

    private function esAdministrador(): bool
    {
        return ($_SESSION['rol'] ?? null) === 'Administrador';
    }

    private function requireAdministrador(string $mensaje): void
    {
        if (!$this->esAdministrador()) {
            http_response_code(403);
            throw new RuntimeException($mensaje);
        }
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
