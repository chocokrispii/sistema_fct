<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';
require_once __DIR__ . '/../Core/FileScanner.php';
require_once __DIR__ . '/../Core/CompanyImportParser.php';
require_once __DIR__ . '/../Core/CompanyImporter.php';
require_once __DIR__ . '/../../config/storage.php';

class EmpresaController {
    private PDO $db;
    private Usuario $usuarioModel;
    private Empresa $empresaModel;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
        $this->usuarioModel = new Usuario($this->db);
        $this->empresaModel = new Empresa($this->db);
    }

    public function registrar(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->mostrarFormulario();
            return;
        }

        $fields = ['email', 'nif_persona_juridica', 'nombre_comercial', 'telefono', 'direccion', 'persona_contacto'];
        $input = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->mostrarFormulario('Los datos del registro deben ser valores de texto válidos.');
                return;
            }
            $input[$field] = trim($value);
        }
        $email = $input['email'];
        $nif = SpanishDataValidator::normalizeFiscalIdentifier($input['nif_persona_juridica']);
        $nombreComercial = $input['nombre_comercial'];
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $contacto = $input['persona_contacto'];

        if (!SpanishDataValidator::isNifPersonaJuridica($nif) && !SpanishDataValidator::isPassport($input['nif_persona_juridica'])) {
            $this->mostrarFormulario('Introduce un NIF (Persona Jurídica) con un carácter de control válido.');
            return;
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 100
            || $nombreComercial === ''
            || $contacto === ''
            || $telefono === ''
            || mb_strlen($nombreComercial) > 150
            || mb_strlen($contacto) > 150
            || mb_strlen($telefono) > 20
            || $direccion === ''
            || mb_strlen($direccion) > 255
        ) {
            $this->mostrarFormulario('Completa correctamente todos los campos obligatorios.');
            return;
        }

        try {
            $this->db->beginTransaction();
            $this->usuarioModel->assertIdentificadorFiscalDisponible($nif);

            $usuarioId = $this->usuarioModel->crear($email, bin2hex(random_bytes(32)), 'Empresa', false);
            if ($usuarioId < 1) {
                throw new RuntimeException('No se pudo crear el usuario de la empresa.');
            }

            $empresaId = $this->empresaModel->crear(
                $usuarioId,
                $nif,
                $nombreComercial,
                $telefono,
                $direccion,
                $contacto
            );
            if ($empresaId < 1) {
                throw new RuntimeException('No se pudo crear el perfil de la empresa.');
            }

            $this->db->commit();
            header('Location: index.php?action=login&registrada=1');
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al registrar empresa: ' . $e->getMessage());
            if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'FCT_APP_URL')) {
                $message = 'No se pudo iniciar el registro seguro. Contacta con el centro.';
            } elseif ($e instanceof PDOException) {
                $message = 'No se pudo completar el registro. Comprueba si el correo y el NIF de persona jurídica ya están registrados.';
            } else {
                $message = 'No se pudo completar el registro o enviar el enlace de activación. Comprueba el correo e inténtalo de nuevo.';
            }
            $this->mostrarFormulario($message);
        }
    }

    private function mostrarFormulario(string $error = ''): void {
        View::render('empresas/registro', ['error' => $error]);
    }

    public function solicitudes(): void
    {
        $solicitudes = $this->db->query(
            "SELECT e.id, e.nombre_empresa, e.cif_empresa, e.persona_contacto,
                    d.telefono, d.direccion_completa, u.email
             FROM empresas e
             INNER JOIN usuarios u ON u.id = e.usuario_id
             LEFT JOIN direcciones_telefonos d ON d.empresa_id = e.id
             WHERE e.estado_solicitud = 'Pendiente_Aprobacion'
             ORDER BY e.id"
        )->fetchAll(PDO::FETCH_ASSOC);
        View::render('admin/solicitudes-empresa', [
            'solicitudes' => $solicitudes,
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
            'aprobada' => isset($_GET['aprobada']),
        ]);
    }

    public function aprobarSolicitud(): void
    {
        $empresaId = filter_var($_POST['empresa_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $numero = $_POST['numero_convenio_marco'] ?? '';
        $archivo = $_FILES['archivo_convenio_marco'] ?? null;
        if ($empresaId === false || !is_string($numero) || trim($numero) === '' || mb_strlen(trim($numero)) > 100) {
            $this->redirectSolicitudError('Indica un número de convenio marco válido.');
        }
        if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($archivo['tmp_name'] ?? null) || !is_string($archivo['name'] ?? null)
            || !is_int($archivo['size'] ?? null) || $archivo['size'] < 1 || $archivo['size'] > 10 * 1024 * 1024
            || !is_uploaded_file($archivo['tmp_name'])) {
            $this->redirectSolicitudError('Adjunta un PDF firmado de hasta 10 MB.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        if ($mime !== 'application/pdf' || strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION)) !== 'pdf') {
            $this->redirectSolicitudError('El convenio marco debe ser un PDF válido.');
        }
        FileScanner::scan($archivo['tmp_name']);

        $storage = fctStoragePath() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'convenios-marco';
        if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
            throw new RuntimeException('No se pudo preparar el almacenamiento del convenio marco.');
        }
        chmod($storage, 0700);
        $storedName = bin2hex(random_bytes(24)) . '.pdf';
        $destination = $storage . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file($archivo['tmp_name'], $destination) || !chmod($destination, 0600)) {
            if (is_file($destination)) { unlink($destination); }
            throw new RuntimeException('No se pudo guardar el convenio marco.');
        }

        $token = bin2hex(random_bytes(32));
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->prepare(
                "SELECT e.nombre_empresa, e.usuario_id, u.email
                 FROM empresas e INNER JOIN usuarios u ON u.id = e.usuario_id
                 WHERE e.id = :id AND e.estado_solicitud = 'Pendiente_Aprobacion' FOR UPDATE"
            );
            $stmt->execute(['id' => $empresaId]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$empresa) { throw new DomainException('La solicitud ya no está pendiente.'); }
            $update = $this->db->prepare(
                "UPDATE empresas SET estado_solicitud = 'Aprobada', numero_convenio_marco = :numero,
                 archivo_convenio_marco = :archivo, aprobada_por = :admin, aprobada_en = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $update->execute(['numero' => trim($numero), 'archivo' => $storedName, 'admin' => (int)$_SESSION['usuario_id'], 'id' => $empresaId]);
            $activation = $this->db->prepare(
                'INSERT INTO company_activation_tokens (usuario_id, token_hash, expires_at)
                 VALUES (:usuario, :hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $activation->execute(['usuario' => $empresa['usuario_id'], 'hash' => hash('sha256', $token)]);
            $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
            if (!ActivationUrl::isAllowedBaseUrl($appUrl)) { throw new RuntimeException('FCT_APP_URL no está configurada.'); }
            $url = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($token);
            (new AccountActivationMailer())->send($empresa['email'], $empresa['nombre_empresa'], $url);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if (is_file($destination)) { unlink($destination); }
            throw $e;
        }
        header('Location: index.php?action=solicitudes-empresa&aprobada=1');
        exit;
    }

    private function redirectSolicitudError(string $message): never
    {
        header('Location: index.php?action=solicitudes-empresa&error=' . rawurlencode($message));
        exit;
    }

    public function importar(): void
    {
        $json = JsonResponse::wantsJson();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            View::render('admin/importar-empresas', ['error' => '', 'resultado' => null]);
            return;
        }

        $archivo = $_FILES['archivo_empresas'] ?? null;
        $error = '';
        $status = 422;
        $resultado = null;
        try {
            if (!is_array($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($archivo['tmp_name'] ?? null) || !is_string($archivo['name'] ?? null)
                || !is_int($archivo['size'] ?? null) || $archivo['size'] < 1 || $archivo['size'] > 5 * 1024 * 1024
                || !is_uploaded_file($archivo['tmp_name'])) {
                throw new CompanyImportException('Adjunta un archivo .xlsx o .pdf de hasta 5 MB.');
            }
            $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
            $validMime = match ($extension) {
                'pdf' => $mime === 'application/pdf',
                'xlsx' => in_array($mime, ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'], true),
                default => false,
            };
            if (!$validMime) {
                throw new CompanyImportException('El archivo debe ser un Excel (.xlsx) o un PDF válido.');
            }

            // Fail-closed: cualquier fallo o detección del antivirus aborta antes de leer el contenido.
            try {
                FileScanner::scan($archivo['tmp_name']);
            } catch (Throwable $e) {
                error_log('SECURITY importación de empresas bloqueada por el antivirus: ' . $e->getMessage());
                $status = 503;
                throw new CompanyImportException('El archivo no superó el análisis antivirus o el análisis no está disponible.');
            }

            $hash = hash_file('sha256', $archivo['tmp_name']) ?: '';
            $filas = CompanyImportParser::parse($archivo['tmp_name'], $extension);
            $resultado = (new CompanyImporter($this->db))->import($filas, (int)$_SESSION['usuario_id'], $archivo['name'], $hash);
        } catch (CompanyImportException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('Error en la importación de empresas: ' . $e->getMessage());
            $status = 500;
            $error = 'No se pudo completar la importación. Contacta con el administrador del sistema.';
        }

        if ($json) {
            $resultado !== null ? JsonResponse::success($resultado) : JsonResponse::failure($error, $status);
            return;
        }
        View::render('admin/importar-empresas', ['error' => $error, 'resultado' => $resultado]);
    }

    public function listarParaCentro(): void
    {
        View::render('empresas/listado-centro', [
            'empresas' => $this->empresaModel->obtenerTodasParaCentro(),
        ]);
    }

    public function sedes(): void
    {
        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)$_SESSION['usuario_id']);
        if ($empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = $_POST['nombre'] ?? '';
            $direccion = $_POST['direccion'] ?? '';
            $telefono = $_POST['telefono'] ?? '';
            if (!is_string($nombre) || !is_string($direccion) || !is_string($telefono)) {
                $error = 'Los datos de la sede deben ser valores de texto válidos.';
            } elseif (
                trim($nombre) === ''
                || trim($direccion) === ''
                || mb_strlen(trim($nombre)) > 150
                || mb_strlen(trim($direccion)) > 255
                || mb_strlen(trim($telefono)) > 20
            ) {
                $error = 'Indica un nombre y una dirección de sede dentro de los límites permitidos.';
            } else {
                $this->empresaModel->crearSede(
                    (int)$empresa['id'],
                    trim($nombre),
                    trim($direccion),
                    trim($telefono)
                );
                header('Location: index.php?action=sedes');
                exit;
            }
        }

        View::render('empresas/sedes', [
            'empresa' => $empresa,
            'sedes' => $this->empresaModel->obtenerSedes((int)$empresa['id']),
            'error' => $error,
        ]);
    }

    public function onboarding(): void
    {
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $empresa = $this->empresaModel->obtenerPorUsuarioId($usuarioId);
        if ($empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }

        $contacto = $this->empresaModel->obtenerContactoPrincipal((int)$empresa['id']);
        $sedes = $this->empresaModel->obtenerSedes((int)$empresa['id']);
        $error = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = trim((string)($_POST['nombre_empresa'] ?? ''));
            $nif = SpanishDataValidator::normalizeFiscalIdentifier((string)($_POST['cif_empresa'] ?? ''));
            $representante = trim((string)($_POST['persona_contacto'] ?? ''));
            $telefono = trim((string)($_POST['telefono'] ?? ''));
            $direccion = trim((string)($_POST['direccion'] ?? ''));
            $sedesInput = $_POST['sedes'] ?? [];

            if (!is_array($sedesInput)) {
                $sedesInput = [];
            }
            $sedesValidadas = [];
            foreach ($sedesInput as $sede) {
                if (!is_array($sede)) {
                    continue;
                }
                $sedeNombre = trim((string)($sede['nombre'] ?? ''));
                $sedeDireccion = trim((string)($sede['direccion'] ?? ''));
                $sedeTelefono = trim((string)($sede['telefono'] ?? ''));
                if ($sedeNombre === '' && $sedeDireccion === '' && $sedeTelefono === '') {
                    continue;
                }
                if ($sedeNombre === '' || mb_strlen($sedeNombre) > 150
                    || $sedeDireccion === '' || mb_strlen($sedeDireccion) > 255
                    || mb_strlen($sedeTelefono) > 20) {
                    $error = 'Completa correctamente el nombre, la dirección y el teléfono opcional de cada sede.';
                    break;
                }
                $sedesValidadas[] = [
                    'nombre' => $sedeNombre,
                    'direccion' => $sedeDireccion,
                    'telefono' => $sedeTelefono,
                ];
            }

            if ($error === '' && (
                $nombre === '' || mb_strlen($nombre) > 150
                || !SpanishDataValidator::isNifPersonaJuridica($nif)
                || $representante === '' || mb_strlen($representante) > 150
                || $telefono === '' || !SpanishDataValidator::isSpanishPhone($telefono)
                || $direccion === '' || mb_strlen($direccion) > 255
                || $sedesValidadas === []
            )) {
                $error = 'Completa la razón social, un NIF jurídico válido, los datos del representante y al menos una sede.';
            }

            if ($error === '') {
                try {
                    $this->db->beginTransaction();
                    $updateEmpresa = $this->db->prepare(
                        'UPDATE empresas
                         SET nombre_empresa = :nombre, cif_empresa = :cif, persona_contacto = :contacto
                         WHERE id = :id AND usuario_id = :usuario_id'
                    );
                    $updateEmpresa->execute([
                        'nombre' => $nombre,
                        'cif' => $nif,
                        'contacto' => $representante,
                        'id' => (int)$empresa['id'],
                        'usuario_id' => $usuarioId,
                    ]);

                    $contactoExistente = $this->db->prepare(
                        'SELECT id FROM direcciones_telefonos WHERE empresa_id = :empresa_id ORDER BY es_principal DESC, id LIMIT 1'
                    );
                    $contactoExistente->execute(['empresa_id' => (int)$empresa['id']]);
                    $contactoId = $contactoExistente->fetchColumn();
                    if ($contactoId !== false) {
                        $actualizarContacto = $this->db->prepare(
                            'UPDATE direcciones_telefonos
                             SET telefono = :telefono, direccion_completa = :direccion, es_principal = 1
                             WHERE id = :id AND empresa_id = :empresa_id'
                        );
                        $actualizarContacto->execute([
                            'telefono' => SpanishDataValidator::normalizePhone($telefono),
                            'direccion' => $direccion,
                            'id' => (int)$contactoId,
                            'empresa_id' => (int)$empresa['id'],
                        ]);
                    } else {
                        $crearContacto = $this->db->prepare(
                            'INSERT INTO direcciones_telefonos (empresa_id, telefono, direccion_completa, es_principal)
                             VALUES (:empresa_id, :telefono, :direccion, 1)'
                        );
                        $crearContacto->execute([
                            'empresa_id' => (int)$empresa['id'],
                            'telefono' => SpanishDataValidator::normalizePhone($telefono),
                            'direccion' => $direccion,
                        ]);
                    }

                    $desactivarSedes = $this->db->prepare(
                        'UPDATE empresa_sedes SET activa = 0 WHERE empresa_id = :empresa_id'
                    );
                    $desactivarSedes->execute(['empresa_id' => (int)$empresa['id']]);
                    $crearSede = $this->db->prepare(
                        'INSERT INTO empresa_sedes (empresa_id, nombre, direccion, telefono, activa)
                         VALUES (:empresa_id, :nombre, :direccion, :telefono, 1)'
                    );
                    foreach ($sedesValidadas as $sede) {
                        $crearSede->execute([
                            'empresa_id' => (int)$empresa['id'],
                            'nombre' => $sede['nombre'],
                            'direccion' => $sede['direccion'],
                            'telefono' => $sede['telefono'] !== '' ? SpanishDataValidator::normalizePhone($sede['telefono']) : null,
                        ]);
                    }
                    $this->db->commit();
                    header('Location: index.php?onboarding=1');
                    exit;
                } catch (Throwable $exception) {
                    if ($this->db->inTransaction()) {
                        $this->db->rollBack();
                    }
                    error_log('Error al completar onboarding de empresa: ' . $exception->getMessage());
                    $error = $exception instanceof PDOException
                        ? 'No se pudieron guardar los datos. Comprueba que el NIF jurídico no esté ya registrado.'
                        : 'No se pudieron guardar todos los datos. Inténtalo de nuevo.';
                }
            }

            $empresa['nombre_empresa'] = $nombre;
            $empresa['cif_empresa'] = $nif;
            $empresa['persona_contacto'] = $representante;
            $contacto = ['telefono' => $telefono, 'direccion_completa' => $direccion];
            $sedes = $sedesValidadas;
        }

        View::render('empresas/onboarding', [
            'empresa' => $empresa,
            'contacto' => $contacto,
            'sedes' => $sedes,
            'error' => $error,
        ]);
    }

    public function hub(): void
    {
        $empresa = $this->empresaModel->obtenerPorUsuarioId((int)($_SESSION['usuario_id'] ?? 0));
        if ($empresa === null) {
            throw new RuntimeException('No se encontró el perfil de empresa.');
        }

        View::render('empresas/hub', [
            'empresa' => $empresa,
            'sedes' => $this->empresaModel->obtenerSedes((int)$empresa['id']),
        ]);
    }

    public function hubAdmin(): void
    {
        View::render('admin/gestion-empresas');
    }
}
