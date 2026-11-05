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
require_once __DIR__ . '/../Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/../Services/PadesSignatureVerifier.php';
require_once __DIR__ . '/../Services/AutoFirmaNativeStrategy.php';
require_once __DIR__ . '/../Services/AutoFirmaMockStrategy.php';
require_once __DIR__ . '/../Services/SignatureProviderFactory.php';
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
        if (!ConvenioSignatureMode::canUseAutoFirma()) {
            $this->responderFirmaError(
                'La aprobación solo puede completarse en modo AutoFirma nativo.',
                409
            );
            return;
        }

        $id = filter_var($_POST['convenio_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $upload = $_FILES['documento_firmado'] ?? null;
        if ($id === false || !is_array($upload)) {
            $this->responderFirmaError(
                'Firma el PDF original con AutoFirma y adjunta el PDF firmado para completar la aprobación.',
                422
            );
            return;
        }

        try {
            $leaseToken = bin2hex(random_bytes(32));
            $this->db->beginTransaction();
            $agreement = $this->convenioModel->obtenerPendienteParaFirma((int)$id);
            if (
                !is_array($agreement)
                || $agreement['estado'] !== 'Pendiente'
                || !$this->convenioModel->reservarFirma(
                    (int)$id,
                    (int)$_SESSION['usuario_id'],
                    $leaseToken,
                    new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'))
                )
            ) {
                throw new DomainException('El convenio ya está siendo firmado o ya no está pendiente de aprobación.');
            }
            $this->db->commit();
            $result = $this->finalizarFirmaAutofirma(
                (int)$id,
                (int)$_SESSION['usuario_id'],
                $upload,
                null,
                $leaseToken
            );
            header(
                'Location: index.php?action=revisar-convenios&firma='
                . ($result['notificacion_enviada'] ? 'completada' : 'correo_pendiente')
            );
            exit;
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->responderFirmaError($exception->getMessage(), 409);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al validar firma AutoFirma del convenio #' . (int)$id . ': ' . $exception->getMessage());
            $this->responderFirmaError(
                'No se pudo validar la firma PAdES. El convenio sigue pendiente y no se guardaron cambios.',
                422
            );
        }
    }

    public function prepararFirmaAutofirma(): void
    {
        if (!ConvenioSignatureMode::canUseAutoFirma() || !$this->esFirmanteConvenio()) {
            JsonResponse::failure('La firma AutoFirma no está habilitada para esta cuenta.', 403);
            return;
        }
        if (JsonResponse::wantsJson()) {
            $payload = $this->leerObjetoJson();
            if ($payload === null) {
                return;
            }
            $rawId = $payload->convenio_id ?? null;
        } else {
            $rawId = $_POST['convenio_id'] ?? null;
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            JsonResponse::failure('El identificador del convenio no es válido.', 422);
            return;
        }

        try {
            $this->db->beginTransaction();
            $convenio = $this->convenioModel->obtenerPendienteParaFirma((int)$id);
            if ($convenio === null || $convenio['estado'] !== 'Pendiente') {
                throw new DomainException('El convenio no existe o ya no está pendiente de aprobación.');
            }
            $challengeToken = bin2hex(random_bytes(32));
            if (!$this->convenioModel->reservarFirma(
                (int)$id,
                (int)$_SESSION['usuario_id'],
                $challengeToken,
                new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'))
            )) {
                throw new DomainException('El convenio ya está siendo firmado por otra persona.');
            }
            $sourcePath = $this->resolverRutaConvenioPrivada((string)$convenio['archivo_path']);
            $size = filesize($sourcePath);
            $originalHash = hash_file('sha256', $sourcePath);
            $document = file_get_contents($sourcePath);
            if (
                !is_int($size)
                || $size < 1
                || $size > 10 * 1024 * 1024
                || !is_string($originalHash)
                || !is_string($document)
            ) {
                throw new RuntimeException('El PDF original no está disponible para firmar.');
            }
            $this->db->commit();
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            JsonResponse::failure($exception->getMessage(), 409);
            return;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('No se pudo preparar la firma AutoFirma del convenio #' . (int)$id . ': ' . $exception->getMessage());
            JsonResponse::failure('No se pudo preparar el PDF para AutoFirma.', 500);
            return;
        }

        $challenges = $_SESSION['autofirma_convenios'] ?? [];
        if (!is_array($challenges)) {
            $challenges = [];
        }
        $now = time();
        foreach ($challenges as $token => $challenge) {
            if (!is_array($challenge) || (int)($challenge['expires_at'] ?? 0) < $now) {
                unset($challenges[$token]);
            }
        }
        if (count($challenges) >= 5) {
            array_shift($challenges);
        }
        $challenges[$challengeToken] = [
            'convenio_id' => (int)$id,
            'usuario_id' => (int)$_SESSION['usuario_id'],
            'hash_original' => $originalHash,
            'expires_at' => $now + 600,
            'lease_token' => $challengeToken,
        ];
        $_SESSION['autofirma_convenios'] = $challenges;
        JsonResponse::success([
            'challenge' => $challengeToken,
            'pdf_base64' => base64_encode($document),
            'filename' => 'convenio-' . (int)$id . '.pdf',
        ]);
    }

    public function completarFirmaAutofirma(): void
    {
        if (!ConvenioSignatureMode::canUseAutoFirma() || !$this->esFirmanteConvenio()) {
            JsonResponse::failure('La firma AutoFirma no está habilitada para esta cuenta.', 403);
            return;
        }
        $token = $_POST['challenge'] ?? null;
        $challenges = $_SESSION['autofirma_convenios'] ?? [];
        if (
            !is_string($token)
            || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1
            || !is_array($challenges)
            || !isset($challenges[$token])
            || !is_array($challenges[$token])
        ) {
            JsonResponse::failure('La solicitud de firma ha caducado o no es válida. Vuelve a iniciar la firma.', 409);
            return;
        }
        $challenge = $challenges[$token];
        unset($challenges[$token]);
        $_SESSION['autofirma_convenios'] = $challenges;
        if (
            (int)($challenge['usuario_id'] ?? 0) !== (int)$_SESSION['usuario_id']
            || (int)($challenge['expires_at'] ?? 0) < time()
            || !is_string($challenge['hash_original'] ?? null)
        ) {
            JsonResponse::failure('La solicitud de firma ha caducado o no es válida. Vuelve a iniciar la firma.', 409);
            return;
        }
        $upload = $_FILES['documento_firmado'] ?? null;
        if (!is_array($upload)) {
            JsonResponse::failure('AutoFirma no devolvió un documento firmado.', 422);
            return;
        }

        try {
            $result = $this->finalizarFirmaAutofirma(
                (int)$challenge['convenio_id'],
                (int)$_SESSION['usuario_id'],
                $upload,
                $challenge['hash_original'],
                $challenge['lease_token']
            );
            JsonResponse::success($result);
        } catch (DomainException $exception) {
            JsonResponse::failure($exception->getMessage(), 409);
        } catch (Throwable $exception) {
            error_log('Error al completar firma AutoFirma del convenio #' . (int)$challenge['convenio_id'] . ': ' . $exception->getMessage());
            JsonResponse::failure(
                'No se pudo validar la firma PAdES. El convenio sigue pendiente y no se guardaron cambios.',
                422
            );
        }
    }

    public function firmarMock(): void
    {
        if (!ConvenioSignatureMode::canUseMockSignature() || !$this->esFirmanteConvenio()) {
            JsonResponse::failure('La firma mock solo está habilitada en desarrollo o pruebas.', 403);
            return;
        }
        if (JsonResponse::wantsJson()) {
            $payload = $this->leerObjetoJson();
            if ($payload === null) {
                return;
            }
            $rawId = $payload->convenio_id ?? null;
        } else {
            $rawId = $_POST['convenio_id'] ?? null;
        }
        $id = filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            JsonResponse::failure('El identificador del convenio no es válido.', 422);
            return;
        }

        $temporaryPath = null;
        try {
            $leaseToken = bin2hex(random_bytes(32));
            $this->db->beginTransaction();
            $agreement = $this->convenioModel->obtenerPendienteParaFirma((int)$id);
            if (
                !is_array($agreement)
                || $agreement['estado'] !== 'Pendiente'
                || !$this->convenioModel->reservarFirma(
                    (int)$id,
                    (int)$_SESSION['usuario_id'],
                    $leaseToken,
                    new DateTimeImmutable('+10 minutes', new DateTimeZone('UTC'))
                )
            ) {
                throw new DomainException('El convenio ya está siendo firmado o ya no está pendiente de aprobación.');
            }
            $sourcePath = $this->resolverRutaConvenioPrivada((string)$agreement['archivo_path']);
            $originalHash = hash_file('sha256', $sourcePath);
            if (!is_string($originalHash)) {
                throw new RuntimeException('No se pudo calcular el hash del documento original.');
            }
            $this->db->commit();

            $temporaryPath = tempnam(dirname($sourcePath), '.mock-firma-');
            if (!is_string($temporaryPath)) {
                throw new RuntimeException('No se pudo preparar el archivo temporal de la firma mock.');
            }
            (new AutoFirmaMockStrategy())->sign($sourcePath, $temporaryPath);
            $size = filesize($temporaryPath);
            if (!is_int($size) || $size < 1 || $size > 20 * 1024 * 1024) {
                throw new RuntimeException('El PDF firmado de desarrollo supera el tamaño permitido.');
            }
            $result = $this->finalizarFirmaAutofirma(
                (int)$id,
                (int)$_SESSION['usuario_id'],
                [
                    'error' => UPLOAD_ERR_OK,
                    'tmp_name' => $temporaryPath,
                    'size' => $size,
                    'name' => 'convenio-firmado.pdf',
                ],
                $originalHash,
                $leaseToken,
                true
            );
            if (JsonResponse::wantsJson()) {
                JsonResponse::success($result);
                return;
            }
            header('Location: index.php?action=revisar-convenios&firma='
                . ($result['notificacion_enviada'] ? 'mock_completada' : 'mock_correo_pendiente'));
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure($exception->getMessage(), 409);
                return;
            }
            $this->responderFirmaError($exception->getMessage(), 409);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error en firma mock del convenio #' . (int)$id . ': ' . $exception->getMessage());
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('No se pudo firmar el convenio con el certificado local de desarrollo.', 422);
                return;
            }
            $this->responderFirmaError('No se pudo firmar el convenio con el certificado local de desarrollo.', 422);
        } finally {
            if (is_string($temporaryPath) && is_file($temporaryPath) && !unlink($temporaryPath)) {
                error_log('No se pudo retirar el archivo temporal de una firma mock.');
            }
        }
    }

    public function probarAutoFirma(): void
    {
        if (
            (getenv('APP_ENV') ?: 'production') !== 'development'
            || ConvenioSignatureMode::current() !== 'test'
            || !$this->esFirmanteConvenio()
        ) {
            http_response_code(404);
            throw new RuntimeException('La prueba de AutoFirma solo está disponible en desarrollo.');
        }

        $pdf = new \setasign\Fpdi\Fpdi();
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(0, 10, 'Documento de prueba AutoFirma - no tiene validez contractual.', 0, 1);
        $pdf->Cell(0, 10, 'La firma de esta prueba no modifica ningun convenio.', 0, 1);
        $document = $pdf->Output('S');
        if (!is_string($document) || !str_starts_with($document, '%PDF-')) {
            throw new RuntimeException('No se pudo generar el PDF de prueba para AutoFirma.');
        }
        $_SESSION['autofirma_test_challenge'] = [
            'token' => bin2hex(random_bytes(32)),
            'pdf' => $document,
            'expires_at' => time() + 600,
        ];
        View::render('convenios/probar-autofirma', [
            'pdfBase64' => base64_encode($document),
            'challenge' => $_SESSION['autofirma_test_challenge']['token'],
            'csrfToken' => CsrfMiddleware::token(),
        ]);
    }

    public function verificarPruebaAutoFirma(): void
    {
        if (
            (getenv('APP_ENV') ?: 'production') !== 'development'
            || ConvenioSignatureMode::current() !== 'test'
            || !$this->esFirmanteConvenio()
        ) {
            JsonResponse::failure('La verificación de prueba solo está disponible en desarrollo.', 403);
            return;
        }

        $challenge = $_POST['challenge'] ?? null;
        $expected = $_SESSION['autofirma_test_challenge'] ?? null;
        unset($_SESSION['autofirma_test_challenge']);
        if (
            !is_string($challenge)
            || !is_array($expected)
            || !is_string($expected['token'] ?? null)
            || !hash_equals($expected['token'], $challenge)
            || !is_string($expected['pdf'] ?? null)
            || (int)($expected['expires_at'] ?? 0) < time()
        ) {
            JsonResponse::failure('La prueba ha caducado o no es válida. Recarga la página para intentarlo de nuevo.', 409);
            return;
        }

        $upload = $_FILES['documento_firmado'] ?? null;
        if (
            !is_array($upload)
            || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || !is_int($upload['size'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 20 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'])
        ) {
            JsonResponse::failure('Adjunta el PDF de prueba firmado con AutoFirma (máximo 20 MB).', 422);
            return;
        }

        try {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            if (
                $mime !== 'application/pdf'
                || strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf'
            ) {
                throw new InvalidArgumentException('El resultado de AutoFirma no es un PDF válido.');
            }
            FileScanner::scan($upload['tmp_name']);

            $signedPdf = file_get_contents($upload['tmp_name']);
            if (!is_string($signedPdf) || !str_starts_with($signedPdf, $expected['pdf'])) {
                throw new DomainException('El PDF firmado no corresponde al documento ficticio de esta prueba.');
            }

            $nssDirectory = getenv('FCT_AUTOFIRMA_NSS_DIR') ?: '/var/lib/fct/autofirma-nssdb';
            $validation = (new PadesSignatureVerifier())->verify($upload['tmp_name'], $nssDirectory, true);
            JsonResponse::success([
                'signer' => $validation['signer'],
                'signature_count' => $validation['signature_count'],
                'persisted' => false,
                'approved' => false,
            ]);
        } catch (DomainException | InvalidArgumentException $exception) {
            JsonResponse::failure($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            error_log('No se pudo verificar el PDF de prueba AutoFirma: ' . $exception->getMessage());
            JsonResponse::failure('No se pudo verificar la firma de prueba. No se guardó ningún documento.', 422);
        }
    }

    private function finalizarFirmaAutofirma(
        int $id,
        int $signatoryId,
        array $upload,
        ?string $expectedOriginalHash = null,
        ?string $leaseToken = null,
        bool $mockGenerated = false
    ): array {
        if (!$this->esFirmanteConvenio()) {
            throw new DomainException('Solo Administración puede firmar convenios.');
        }
        if (!is_string($leaseToken) || preg_match('/\A[a-f0-9]{64}\z/', $leaseToken) !== 1) {
            throw new DomainException('La reserva de firma ha caducado o no es válida.');
        }
        if (
            ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($upload['tmp_name'] ?? null)
            || !is_int($upload['size'] ?? null)
            || $upload['size'] < 1
            || $upload['size'] > 20 * 1024 * 1024
            || (!$mockGenerated && !is_uploaded_file($upload['tmp_name']))
        ) {
            throw new InvalidArgumentException('Adjunta un PDF firmado con AutoFirma de hasta 20 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo((string)($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'pdf') {
            throw new InvalidArgumentException('El documento devuelto por AutoFirma debe ser un archivo PDF.');
        }
        FileScanner::scan($upload['tmp_name']);

        $convenio = $this->convenioModel->obtener($id);
        if (!is_array($convenio) || $convenio['estado'] !== 'Pendiente') {
            throw new DomainException('El convenio no existe o ya no está pendiente de aprobación.');
        }
        $sourcePath = $this->resolverRutaConvenioPrivada((string)$convenio['archivo_path']);
        $originalHash = hash_file('sha256', $sourcePath);
        $sourceContents = file_get_contents($sourcePath);
        if (
            !is_string($originalHash)
            || !is_string($sourceContents)
            || ($expectedOriginalHash !== null && !hash_equals($expectedOriginalHash, $originalHash))
        ) {
            throw new DomainException('El PDF original ha cambiado. Inicia de nuevo el proceso de firma.');
        }

        $storageDirectory = dirname($sourcePath);
        $temporaryPath = tempnam($storageDirectory, '.autofirma-convenio-');
        if (!is_string($temporaryPath)) {
            throw new RuntimeException('No se pudo preparar el archivo temporal de la firma.');
        }
        $signedPath = null;
        try {
            if (!copy($upload['tmp_name'], $temporaryPath) || !chmod($temporaryPath, 0600)) {
                throw new RuntimeException('No se pudo copiar el documento firmado al almacenamiento privado.');
            }
            $resolvedTemporaryPath = realpath($temporaryPath);
            if ($resolvedTemporaryPath === false || dirname($resolvedTemporaryPath) !== $storageDirectory) {
                throw new RuntimeException('El documento firmado quedó fuera del almacenamiento privado.');
            }
            $temporaryPath = $resolvedTemporaryPath;
            $signedContents = file_get_contents($temporaryPath);
            if (
                !is_string($signedContents)
                || (!$mockGenerated && !str_starts_with($signedContents, $sourceContents))
            ) {
                throw new DomainException(
                    'El PDF firmado no corresponde al documento original. Firma el archivo descargado desde el sistema sin modificarlo.'
                );
            }
            $validation = SignatureProviderFactory::create()->verify($temporaryPath);
            $signedHash = hash_file('sha256', $temporaryPath);
            if (!is_string($signedHash)) {
                throw new RuntimeException('No se pudo calcular el hash del documento firmado.');
            }

            $storedName = bin2hex(random_bytes(24)) . '.pdf';
            $signedPath = $storageDirectory . DIRECTORY_SEPARATOR . $storedName;
            $this->db->beginTransaction();
            $lockedAgreement = $this->convenioModel->obtenerPendienteParaFirma($id);
            if (
                !is_array($lockedAgreement)
                || $lockedAgreement['estado'] !== 'Pendiente'
            ) {
                throw new DomainException('El convenio ya se ha firmado o ya no está pendiente de aprobación.');
            }
            $lockedSourcePath = $this->resolverRutaConvenioPrivada((string)$lockedAgreement['archivo_path']);
            $lockedOriginalHash = hash_file('sha256', $lockedSourcePath);
            if (!is_string($lockedOriginalHash) || !hash_equals($originalHash, $lockedOriginalHash)) {
                throw new DomainException('El documento original ha cambiado. Inicia de nuevo el proceso de firma.');
            }
            if (!rename($temporaryPath, $signedPath) || !chmod($signedPath, 0600)) {
                throw new RuntimeException('No se pudo guardar el PDF firmado en el almacenamiento privado.');
            }
            $temporaryPath = null;
            $signedAtUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $previousState = $this->convenioModel->registrarFirma(
                $id,
                $storedName,
                $originalHash,
                $signedHash,
                $signatoryId,
                $signedAtUtc,
                $leaseToken
            );
            $signerDetail = $validation['signer'] !== ''
                ? ';cert_subject=' . rawurlencode($validation['signer'])
                : '';
            (new Auditoria($this->db))->registrar(
                $signatoryId,
                'convenio.firmado.aprobado',
                'convenios',
                $id,
                'signatory_id=' . $signatoryId
                    . ';timestamp_utc=' . $signedAtUtc->format('Y-m-d\TH:i:s\Z')
                    . ';document_hash_pre_signature=' . $originalHash
                    . ';document_hash_post_signature=' . $signedHash
                    . ';estado_anterior=' . $previousState
                    . ';estado_nuevo=Aprobado'
                    . ';firma=PAdES'
                    . $signerDetail
            );
            $signatureAudit = $this->db->prepare(
                'INSERT INTO convenio_audit_logs
                    (convenio_id, usuario_id, evento, hash_documento_original, hash_documento_firmado, detalle)
                 VALUES
                    (:convenio, :usuario, :evento, :hash_original, :hash_firmado, :detalle)'
            );
            $signatureAudit->execute([
                'convenio' => $id,
                'usuario' => $signatoryId,
                'evento' => 'FIRMADO_Y_APROBADO',
                'hash_original' => $originalHash,
                'hash_firmado' => $signedHash,
                'detalle' => json_encode([
                    'proveedor' => $mockGenerated ? 'mock' : 'autofirma',
                    'firmante_certificado' => $validation['signer'],
                    'firma_count' => $validation['signature_count'],
                    'certificado_confiable' => $validation['certificate_trusted'],
                    'estado_anterior' => $previousState,
                    'estado_nuevo' => 'Aprobado',
                    'timestamp_utc' => $signedAtUtc->format('Y-m-d\TH:i:s\Z'),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            WebhookPublisher::publish($this->db, 'convenio.aprobado_firmado', ['convenio_id' => $id]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            foreach ([$temporaryPath, $signedPath] as $path) {
                if (is_string($path) && is_file($path) && !unlink($path)) {
                    error_log('No se pudo retirar un PDF de firma tras una reversión.');
                }
            }
            throw $exception;
        }

        $notificationSent = true;
        try {
            $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
            $downloadUrl = $appUrl . '/index.php?action=descargar-convenio-firmado&id=' . $id;
            (new ConvenioApprovalMailer())->send(
                (string)$lockedAgreement['empresa_email'],
                (string)$lockedAgreement['nombre_empresa'],
                (string)$lockedAgreement['numero_convenio'],
                $downloadUrl,
                $signedPath
            );
        } catch (Throwable $exception) {
            $notificationSent = false;
            error_log('No se pudo notificar la aprobación del convenio #' . $id . ': ' . $exception->getMessage());
        }

        $message = $mockGenerated
            ? ($notificationSent
                ? 'Firma PAdES local de desarrollo completada; el certificado de prueba no tiene validez contractual.'
                : 'Firma PAdES local de desarrollo completada; el certificado de prueba no tiene validez contractual y el correo está pendiente.')
            : ($notificationSent
                ? 'El convenio se firmó digitalmente y se envió el PDF a la empresa.'
                : 'El convenio se firmó digitalmente, pero no se pudo enviar el correo. La empresa puede descargarlo desde su cuenta.');

        return [
            'message' => $message,
            'estado' => 'Aprobado',
            'notificacion_enviada' => $notificationSent,
        ];
    }

    private function leerObjetoJson(): ?stdClass
    {
        try {
            $payload = json_decode((string)file_get_contents('php://input'), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            JsonResponse::failure('El cuerpo JSON no es válido.', 400);
            return null;
        }
        if (!$payload instanceof stdClass) {
            JsonResponse::failure('El cuerpo JSON debe ser un objeto de datos.', 400);
            return null;
        }
        return $payload;
    }

    private function responderFirmaError(string $message, int $status): void
    {
        http_response_code($status);
        if (JsonResponse::wantsJson()) {
            JsonResponse::failure($message, $status);
            return;
        }
        $_SESSION['convenio_firma_error'] = $message;
        http_response_code(303);
        header('Location: index.php?action=revisar-convenios&firma=error');
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
        if (!$this->esFirmanteConvenio()) {
            http_response_code(403);
            if (JsonResponse::wantsJson()) {
                JsonResponse::failure('Solo Administración o Coordinación puede revisar convenios.', 403);
                return;
            }
            throw new RuntimeException('Solo Administración o Coordinación puede revisar convenios.');
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
            $this->responderFirmaError(
                'La aprobación debe completarse mediante una firma PAdES verificada.',
                409
            );
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
            throw new RuntimeException('Solo Administración puede descargar documentos de convenios.');
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
        $role = $_SESSION['rol'] ?? null;
        if (!in_array($role, ['Administrador', 'Empresa'], true) || $userId === false) {
            $auditUserId = $userId === false ? null : (int)$userId;
            (new Auditoria($this->db))->registrar(
                $auditUserId,
                'convenio.descarga_firmada.denegada',
                'convenios',
                null,
                'motivo=rol_no_autorizado'
            );
            http_response_code(403);
            throw new RuntimeException('No tienes permiso para descargar el convenio aprobado.');
        }
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            http_response_code(400);
            throw new InvalidArgumentException('El identificador del convenio no es válido.');
        }
        $convenio = $role === 'Administrador'
            ? $this->convenioModel->obtenerFirmadoParaAdministrador((int)$id)
            : $this->convenioModel->obtenerFirmadoParaEmpresa((int)$id, (int)$userId);
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
            'resultado=autorizada;rol=' . $role
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
        $signatureMode = ConvenioSignatureMode::current();
        $firmaError = $_SESSION['convenio_firma_error'] ?? '';
        unset($_SESSION['convenio_firma_error']);
        View::render('convenios/listado', [
            'convenios' => $resultado['items'],
            'eventosPorConvenio' => $eventosPorConvenio,
            'filtros' => $filtros,
            'paginacion' => $resultado,
            'error' => $error,
            'puedeResolver' => $rol === 'Administrador',
            'puedeFirmar' => $rol === 'Administrador',
            'esAdministrador' => $rol === 'Administrador',
            'signatureMode' => $signatureMode,
            'firmaResultado' => match ($_GET['firma'] ?? '') {
                'completada' => 'El convenio se firmó digitalmente y se envió el PDF a la empresa.',
                'correo_pendiente' => 'El convenio se firmó digitalmente, pero el correo no pudo enviarse. La empresa puede descargarlo desde su cuenta.',
                'mock_completada' => 'Firma PAdES local de desarrollo completada; el certificado de prueba no tiene validez contractual. El PDF se envió a Mailpit.',
                'mock_correo_pendiente' => 'Firma PAdES local de desarrollo completada; el certificado de prueba no tiene validez contractual. El correo está pendiente.',
                'error' => is_string($firmaError) && $firmaError !== ''
                    ? $firmaError
                    : 'No se pudo completar la firma. El convenio permanece pendiente y el documento original se conserva.',
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

    private function esFirmanteConvenio(): bool
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
