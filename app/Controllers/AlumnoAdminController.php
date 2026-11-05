<?php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Alumno.php';
require_once __DIR__ . '/../Models/Especialidad.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/StudentImportParser.php';
require_once __DIR__ . '/../Core/StudentImporter.php';
require_once __DIR__ . '/../Services/WebhookPublisher.php';

class AlumnoAdminController
{
    private PDO $db;
    private Usuario $usuarioModel;
    private Alumno $alumnoModel;
    private Especialidad $especialidadModel;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
        $this->usuarioModel = new Usuario($this->db);
        $this->alumnoModel = new Alumno($this->db);
        $this->especialidadModel = new Especialidad($this->db);
    }

    public function gestionar(): void
    {
        $error = is_string($_SESSION['alumno_admin_error'] ?? null)
            ? $_SESSION['alumno_admin_error']
            : '';
        unset($_SESSION['alumno_admin_error']);
        $search = $_GET['q'] ?? '';
        $cycleRaw = $_GET['ciclo'] ?? '';
        $statusRaw = $_GET['estado'] ?? '';
        if (!is_string($search) || !is_string($cycleRaw) || !is_string($statusRaw)) {
            $error = 'Los filtros de búsqueda no tienen un formato válido.';
            $search = '';
            $cycleRaw = '';
            $statusRaw = '';
        }
        $search = trim($search);
        if (
            mb_strlen($search) > 100
            || preg_match('/[\p{Cc}]/u', $search) === 1
            || preg_match('//u', $search) !== 1
        ) {
            $error = 'La búsqueda debe tener como máximo 100 caracteres válidos.';
            $search = '';
        }
        $cycleId = null;
        if ($cycleRaw !== '') {
            $validatedCycle = filter_var($cycleRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($validatedCycle === false) {
                $error = 'Selecciona un ciclo formativo válido.';
            } else {
                $cycleId = (int)$validatedCycle;
            }
        }
        $active = null;
        if ($statusRaw !== '') {
            if (!in_array($statusRaw, ['0', '1'], true)) {
                $error = 'Selecciona un estado válido.';
            } else {
                $active = $statusRaw === '1';
            }
        }

        View::render('admin/alumnos', [
            'error' => $error,
            'actualizado' => isset($_GET['actualizado']),
            'search' => $search,
            'cycleId' => $cycleId,
            'status' => $statusRaw,
            'especialidades' => $this->especialidadModel->obtenerTodas(false),
            'alumnos' => $this->alumnoModel->obtenerTodosParaCentro($search, $cycleId, $active),
        ]);
    }

    public function alta(): void
    {
        $error = '';
        $values = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach (['tipo_documento', 'dni', 'nombre', 'apellidos', 'email', 'especialidad_id', 'curso', 'observaciones_administrativas'] as $field) {
                $value = $_POST[$field] ?? '';
                if (!is_string($value)) {
                    $error = 'Los datos del alumno no tienen un formato válido.';
                    break;
                }
                $values[$field] = trim($value);
            }

            if ($error === '') {
                $documentType = $values['tipo_documento'];
                $dni = $documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                    ? strtoupper($values['dni'])
                    : SpanishDataValidator::normalizeDniNie($values['dni']);
                $nombre = $values['nombre'];
                $apellidos = $values['apellidos'];
                $email = $values['email'];
                $cycleId = filter_var(
                    $values['especialidad_id'],
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]]
                );
                $course = $values['curso'];
                $observations = $values['observaciones_administrativas'];

                if (
                    !SpanishDataValidator::isStudentDocumentType($documentType)
                    || ($documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                        ? !SpanishDataValidator::isPassport($dni)
                        : !SpanishDataValidator::isDniNie($dni))
                    || !SpanishDataValidator::isPersonName($nombre, 50)
                    || !SpanishDataValidator::isPersonName($apellidos, 100)
                    || !SpanishDataValidator::isEmail($email)
                    || $cycleId === false
                    || !in_array($course, ['1', '2'], true)
                    || mb_strlen($observations) > 2000
                    || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $observations) === 1
                ) {
                    $error = 'Revisa el tipo y número de documento, nombre, apellidos, correo, ciclo, curso y observaciones.';
                } elseif (!$this->especialidadModel->existe((int)$cycleId)) {
                    $error = 'Selecciona un ciclo formativo activo.';
                } else {
                    $activationToken = bin2hex(random_bytes(32));
                    try {
                        $this->db->beginTransaction();
                        $this->usuarioModel->assertIdentificadorFiscalDisponible($dni);
                        $userId = $this->usuarioModel->crear(
                            $email,
                            bin2hex(random_bytes(32)),
                            'Alumno',
                            false
                        );
                        if ($userId < 1) {
                            throw new RuntimeException('No se pudo crear la cuenta del alumno.');
                        }

                        $studentId = $this->alumnoModel->crearDesdePersonal(
                            $userId,
                            $nombre,
                            $apellidos,
                            $dni,
                            $email,
                            (int)$cycleId,
                            (int)$course,
                            $observations,
                            $documentType
                        );
                        WebhookPublisher::publish($this->db, 'alumno.alta', ['alumno_id' => $studentId]);
                        (new Auditoria($this->db))->registrar(
                            (int)$_SESSION['usuario_id'],
                            'alumno.creado_por_personal',
                            'alumnos',
                            $studentId,
                            'ciclo_id=' . (int)$cycleId . '; curso=' . (int)$course
                        );
                        $tokenInsert = $this->db->prepare(
                            'INSERT INTO student_activation_tokens (usuario_id, token_hash, expires_at)
                             VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
                        );
                        $tokenInsert->execute([
                            'usuario_id' => $userId,
                            'token_hash' => hash('sha256', $activationToken),
                        ]);
                        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
                        if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
                            throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible para el alumno.');
                        }
                        $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($activationToken);
                        (new AccountActivationMailer())->send($email, $nombre . ' ' . $apellidos, $activationUrl);
                        $this->db->commit();

                        $_SESSION['alumno_creado_email'] = $email;
                        header('Location: index.php?action=alta-alumno&tab=altas&creado=1');
                        exit;
                    } catch (Throwable $exception) {
                        if ($this->db->inTransaction()) {
                            $this->db->rollBack();
                        }
                        if ($exception instanceof PDOException) {
                            error_log('Error de base de datos al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = 'No se pudo crear la cuenta. Comprueba si el correo o el documento de identidad ya están registrados.';
                        } elseif ($exception instanceof DomainException) {
                            $error = $exception->getMessage();
                        } elseif ($exception instanceof RuntimeException) {
                            error_log('Error al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = $exception->getMessage();
                        } else {
                            error_log('Error inesperado al crear alumno desde el centro: ' . $exception->getMessage());
                            $error = 'No se pudo completar el alta del alumno.';
                        }
                    }
                }
            }
        }

        $sentTo = $_SESSION['alumno_creado_email'] ?? null;
        unset($_SESSION['alumno_creado_email']);
        View::render('admin/alta-alumno', [
            'error' => $error,
            'values' => $values,
            'especialidades' => $this->especialidadModel->obtenerTodas(),
            'creado' => isset($_GET['creado']) && is_string($sentTo),
            'sentTo' => is_string($sentTo) ? $sentTo : '',
        ]);
    }

    public function importarMasivo(): void
    {
        $error = '';
        $preview = null;
        $summary = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $step = $_POST['paso'] ?? '';
            if (!is_string($step)) {
                $error = 'La solicitud de importación no tiene un formato válido.';
            } elseif ($step === 'previsualizar') {
                [$error, $preview] = $this->previewStudentImport();
            } elseif ($step === 'confirmar') {
                [$error, $summary] = $this->confirmStudentImport();
            } elseif ($step === 'cancelar') {
                unset($_SESSION['student_import_pending'], $_SESSION['student_import_report']);
            } else {
                $error = 'Selecciona una acción de importación válida.';
            }
        }

        View::render('admin/importar-alumnos', [
            'error' => $error,
            'preview' => $preview,
            'summary' => $summary,
        ]);
    }

    public function plantillaImportacion(): never
    {
        $workbook = StudentImportParser::createTemplate();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="plantilla-importacion-alumnado.xlsx"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save('php://output');
        $workbook->disconnectWorksheets();
        exit;
    }

    public function descargarVerificacionImportacion(): never
    {
        $students = $this->alumnoModel->obtenerTodosParaExportacion();
        $cycles = $this->especialidadModel->obtenerTodas();
        if ($cycles === []) {
            throw new RuntimeException('No hay ciclos activos para preparar los registros de prueba.');
        }

        $samples = $this->buildStudentVerificationRows($students, $cycles);
        $workbook = StudentImportParser::createVerificationWorkbook($students, $samples);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="prueba-verificacion_de_datos1.xlsx"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($workbook))->save('php://output');
        $workbook->disconnectWorksheets();
        exit;
    }

    /**
     * @param list<array{correo:string,dni:string,tipo_documento:?string,nombre:string,apellidos:string,nombre_especialidad:?string,curso:string|int}> $students
     * @param list<array{id:int,nombre_especialidad:string,activo:int|string}> $cycles
     * @return list<array{email:string,dni:string,nombre:string,apellidos:string,ciclo:string,curso:string}>
     */
    private function buildStudentVerificationRows(array $students, array $cycles): array
    {
        $usedDocuments = [];
        $registeredByEmail = [];
        foreach ($students as $student) {
            $usedDocuments[SpanishDataValidator::normalizeDniNie($student['dni'])] = true;
            $registeredByEmail[strtolower($student['correo'])] = $student;
        }

        $names = ['Ana', 'Luis', 'Lucía', 'Hugo', 'Sofía', 'Mario', 'Nora', 'Leo', 'Elena', 'Pablo', 'Vera', 'Diego', 'Irene', 'Álex', 'Clara'];
        $cycleNames = array_values(array_map(
            static fn(array $cycle): string => (string)$cycle['nombre_especialidad'],
            $cycles
        ));
        $rows = [];
        $number = 90000000;
        $checkLetters = 'TRWAGMYFPDXBNJZSQVHLCKE';
        $surnames = [
            'Prueba Uno', 'Prueba Dos', 'Muestra Tres', 'Muestra Cuatro', 'Verificacion Cinco',
            'Ejemplo Seis', 'Ejemplo Siete', 'Muestra Ocho', 'Prueba Nueve', 'Ejemplo Diez',
            'Verificacion Once', 'Muestra Doce', 'Prueba Trece', 'Ejemplo Catorce', 'Muestra Quince',
        ];

        for ($index = 1; $index <= 15; $index++) {
            $email = sprintf('prueba.importacion.alumno%02d@example.invalid', $index);
            $registered = $registeredByEmail[$email] ?? null;
            if (
                is_array($registered)
                && SpanishDataValidator::isDniNie($registered['dni'])
                && in_array($registered['nombre_especialidad'], $cycleNames, true)
                && in_array((string)$registered['curso'], ['1', '2'], true)
            ) {
                $rows[] = [
                    'email' => $email,
                    'dni' => SpanishDataValidator::normalizeDniNie($registered['dni']),
                    'nombre' => $registered['nombre'],
                    'apellidos' => $registered['apellidos'],
                    'ciclo' => $registered['nombre_especialidad'],
                    'curso' => (string)$registered['curso'],
                ];
                continue;
            }

            do {
                if ($number > 99999999) {
                    throw new RuntimeException('No se pudieron generar identificadores únicos para el Excel de prueba.');
                }
                $dni = (string)$number . $checkLetters[$number % 23];
                $number++;
            } while (isset($usedDocuments[$dni]));

            $usedDocuments[$dni] = true;
            $rows[] = [
                'email' => $email,
                'dni' => $dni,
                'nombre' => $names[$index - 1],
                'apellidos' => $surnames[$index - 1],
                'ciclo' => $cycleNames[($index - 1) % count($cycleNames)],
                'curso' => ($index % 2 === 0) ? '2' : '1',
            ];
        }

        return $rows;
    }

    public function descargarInformeImportacion(): never
    {
        $report = $_SESSION['student_import_report'] ?? null;
        if (
            !is_array($report)
            || (int)($report['expires_at'] ?? 0) < time()
            || !is_array($report['rows'] ?? null)
            || $report['rows'] === []
        ) {
            unset($_SESSION['student_import_report']);
            http_response_code(404);
            echo 'No hay un informe de importación disponible.';
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="informe-importacion-alumnado.csv"');
        header('Cache-Control: no-store');
        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new RuntimeException('No se pudo generar el informe de importación.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ['Fila', 'Correo', 'Resultado', 'Detalle'], ';', '"', '\\');
        foreach ($report['rows'] as $row) {
            fputcsv($stream, [
                (int)($row['linea'] ?? 0),
                self::safeCsvCell((string)($row['email'] ?? '')),
                self::safeCsvCell((string)($row['estado'] ?? 'Error')),
                self::safeCsvCell((string)($row['motivo'] ?? '')),
            ], ';', '"', '\\');
        }
        fclose($stream);
        exit;
    }

    /**
     * @return array{0:string,1:?array<string,mixed>}
     */
    private function previewStudentImport(): array
    {
        $file = $_FILES['archivo_alumnado'] ?? null;
        try {
            if (
                !is_array($file)
                || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($file['tmp_name'] ?? null)
                || !is_string($file['name'] ?? null)
                || !is_int($file['size'] ?? null)
                || $file['size'] < 1
                || $file['size'] > StudentImportParser::MAX_FILE_BYTES
                || !is_uploaded_file($file['tmp_name'])
            ) {
                throw new StudentImportException('Adjunta un archivo .xlsx de hasta 5 MB.');
            }

            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (
                $extension !== 'xlsx'
                || !in_array($mime, [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip',
                ], true)
            ) {
                throw new StudentImportException('El archivo debe ser un Excel (.xlsx) válido.');
            }

            try {
                FileScanner::scan($file['tmp_name']);
            } catch (Throwable $exception) {
                error_log('SECURITY importación masiva de alumnado bloqueada por antivirus: ' . $exception->getMessage());
                throw new StudentImportException('El archivo no superó el análisis antivirus o el análisis no está disponible.');
            }

            $hash = hash_file('sha256', $file['tmp_name']);
            if (!is_string($hash)) {
                throw new RuntimeException('No se pudo calcular la huella del archivo.');
            }
            $parsedRows = StudentImportParser::parse($file['tmp_name']);
            $result = (new StudentImporter($this->db))->preview(
                $parsedRows,
                $this->especialidadModel->obtenerTodas()
            );
            $token = bin2hex(random_bytes(32));
            $previewErrors = array_map(
                static fn(array $row): array => [
                    'linea' => $row['linea'],
                    'email' => $row['email'],
                    'estado' => 'Error',
                    'motivo' => $row['motivo'],
                ],
                $result['errors']
            );
            $_SESSION['student_import_pending'] = [
                'token' => $token,
                'admin_id' => (int)($_SESSION['usuario_id'] ?? 0),
                'expires_at' => time() + 1800,
                'filename' => basename($file['name']),
                'hash' => $hash,
                'total' => $result['total'],
                'valid_rows' => $result['valid_rows'],
                'activation_rows' => $result['activation_rows'],
                'errors' => $result['errors'],
            ];
            $_SESSION['student_import_report'] = [
                'expires_at' => time() + 3600,
                'rows' => array_merge(
                    array_map(
                        static fn(array $row): array => [
                            'linea' => $row['linea'],
                            'email' => $row['email'],
                            'estado' => 'Pendiente de confirmación',
                            'motivo' => '',
                        ],
                        $result['valid_rows']
                    ),
                    array_map(
                        static fn(array $row): array => [
                            'linea' => $row['linea'],
                            'email' => $row['email'],
                            'estado' => 'Pendiente de envío de activación',
                            'motivo' => '',
                        ],
                        $result['activation_rows']
                    ),
                    $previewErrors
                ),
            ];
            $preview = [
                'token' => $token,
                'total' => $result['total'],
                'validas' => count($result['valid_rows']),
                'activaciones' => count($result['activation_rows']),
                'errores' => $result['errors'],
                'muestra' => array_slice($result['valid_rows'], 0, 10),
                'muestra_activaciones' => array_slice($result['activation_rows'], 0, 10),
            ];
            return ['', $preview];
        } catch (StudentImportException $exception) {
            return [$exception->getMessage(), null];
        } catch (Throwable $exception) {
            error_log('Error al previsualizar importación masiva de alumnado: ' . $exception->getMessage());
            return ['No se pudo analizar el archivo. Contacta con el administrador del sistema.', null];
        }
    }

    /**
     * @return array{0:string,1:?array<string,int>}
     */
    private function confirmStudentImport(): array
    {
        $submittedToken = $_POST['importacion_id'] ?? '';
        $pending = $_SESSION['student_import_pending'] ?? null;
        if (
            !is_string($submittedToken)
            || !is_array($pending)
            || !is_string($pending['token'] ?? null)
            || !hash_equals($pending['token'], $submittedToken)
            || (int)($pending['admin_id'] ?? 0) !== (int)($_SESSION['usuario_id'] ?? 0)
            || (int)($pending['expires_at'] ?? 0) < time()
            || !is_array($pending['valid_rows'] ?? null)
            || !is_array($pending['activation_rows'] ?? null)
            || !is_array($pending['errors'] ?? null)
        ) {
            unset($_SESSION['student_import_pending']);
            return ['La previsualización ha caducado o ya se utilizó. Sube de nuevo el archivo.', null];
        }

        unset($_SESSION['student_import_pending']);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(0);

        $error = '';
        $summary = null;
        try {
            $importer = new StudentImporter($this->db);
            $result = $pending['valid_rows'] === []
                ? ['insertados' => 0, 'resultados' => []]
                : $importer->import(
                    $pending['valid_rows'],
                    (int)$pending['admin_id'],
                    (string)$pending['filename'],
                    (string)$pending['hash'],
                    count($pending['valid_rows']) + count($pending['errors']),
                    count($pending['errors'])
                );
            $resend = $pending['activation_rows'] === []
                ? ['enviados' => 0, 'resultados' => []]
                : $importer->resendActivation($pending['activation_rows'], (int)$pending['admin_id']);
            $reportRows = array_merge(
                array_map(
                    static fn(array $row): array => [
                        'linea' => $row['linea'],
                        'email' => $row['email'],
                        'estado' => 'Error',
                        'motivo' => $row['motivo'],
                    ],
                    $pending['errors']
                ),
                $result['resultados'],
                $resend['resultados']
            );
            usort($reportRows, static fn(array $left, array $right): int => $left['linea'] <=> $right['linea']);
            $inserted = $result['insertados'];
            $sentEmails = $inserted + $resend['enviados'];
            $summary = [
                'total' => (int)$pending['total'],
                'insertados' => $inserted,
                'emails_enviados' => $sentEmails,
                'fallidos' => (int)$pending['total'] - $sentEmails,
                'resultados' => $reportRows,
            ];
        } catch (Throwable $exception) {
            error_log('Error al confirmar importación masiva de alumnado: ' . $exception->getMessage());
            $error = 'No se pudo completar la importación. Las filas ya procesadas se indican en el informe si corresponde.';
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
            session_start();
        }
        if ($summary !== null) {
            $_SESSION['student_import_report'] = [
                'expires_at' => time() + 3600,
                'rows' => $summary['resultados'],
            ];
        } elseif ($error !== '') {
            $_SESSION['student_import_report'] = [
                'expires_at' => time() + 3600,
                'rows' => [[
                    'linea' => 0,
                    'email' => '',
                    'estado' => 'Error',
                    'motivo' => $error,
                ]],
            ];
        }
        return [$error, $summary];
    }

    private static function safeCsvCell(string $value): string
    {
        return preg_match('/\A[\s]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    }

    public function actualizar(): void
    {
        $studentId = filter_var($_POST['alumno_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $fields = ['tipo_documento', 'dni', 'nombre', 'apellidos', 'email', 'especialidad_id', 'curso', 'observaciones_administrativas'];
        $values = [];
        if ($studentId === false) {
            $this->redirectWithError('Selecciona un expediente de alumno válido.');
        }
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->redirectWithError('Los datos del alumno no tienen un formato válido.');
            }
            $values[$field] = trim($value);
        }

        $documentType = $values['tipo_documento'];
        $dni = $documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
            ? strtoupper($values['dni'])
            : SpanishDataValidator::normalizeDniNie($values['dni']);
        $cycleId = filter_var($values['especialidad_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (
            !SpanishDataValidator::isStudentDocumentType($documentType)
            || ($documentType === SpanishDataValidator::DOCUMENT_TYPE_PASSPORT
                ? !SpanishDataValidator::isPassport($dni)
                : !SpanishDataValidator::isDniNie($dni))
            || !SpanishDataValidator::isPersonName($values['nombre'], 50)
            || !SpanishDataValidator::isPersonName($values['apellidos'], 100)
            || !SpanishDataValidator::isEmail($values['email'])
            || $cycleId === false
            || !in_array($values['curso'], ['1', '2'], true)
            || mb_strlen($values['observaciones_administrativas']) > 2000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $values['observaciones_administrativas']) === 1
        ) {
            $this->redirectWithError('Revisa el tipo y número de documento, nombre, apellidos, correo, ciclo, curso y observaciones.');
        }
        if (!$this->especialidadModel->existe((int)$cycleId, false)) {
            $this->redirectWithError('Selecciona un ciclo formativo existente.');
        }

        try {
            $this->db->beginTransaction();
            $userId = $this->alumnoModel->obtenerUsuarioIdParaCentro((int)$studentId, true);
            if ($userId === null) {
                throw new DomainException('No se encontró el expediente del alumno.');
            }
            $this->usuarioModel->assertIdentificadorFiscalDisponible($dni, $userId);
            $this->alumnoModel->actualizarDesdePersonal(
                (int)$studentId,
                $values['nombre'],
                $values['apellidos'],
                $dni,
                $documentType,
                $values['email'],
                (int)$cycleId,
                (int)$values['curso'],
                $values['observaciones_administrativas']
            );
            (new Auditoria($this->db))->registrar(
                (int)$_SESSION['usuario_id'],
                'alumno.actualizado_por_personal',
                'alumnos',
                (int)$studentId,
                'ciclo_id=' . (int)$cycleId . '; curso=' . (int)$values['curso']
            );
            $this->db->commit();
        } catch (DomainException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->redirectWithError($exception->getMessage());
        } catch (PDOException $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al actualizar alumno desde el centro: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo actualizar el expediente. Comprueba que el correo o documento no pertenezcan a otra cuenta.');
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error inesperado al actualizar alumno desde el centro: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo actualizar el expediente.');
        }

        header('Location: index.php?action=alumnos-centro&tab=principal&actualizado=1');
        exit;
    }

    private function redirectWithError(string $message): never
    {
        $_SESSION['alumno_admin_error'] = $message;
        header('Location: index.php?action=alumnos-centro&tab=principal');
        exit;
    }
}
