<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';
require_once __DIR__ . '/../Core/FileScanner.php';
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
            $this->mostrarFormulario('Introduce un NIF o pasaporte válido.');
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
}
