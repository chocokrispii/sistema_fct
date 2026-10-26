<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Models/Auditoria.php';
require_once __DIR__ . '/../Core/JsonResponse.php';
require_once __DIR__ . '/../Core/PrivatePdfStorage.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class EmpresaController {
    private const MENSAJE_SOLICITUD_ENVIADA = 'Solicitud enviada con éxito. Su cuenta se encuentra en estado Pendiente de Aprobación. Se le notificará una vez el equipo administrativo valide la información.';

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
            $this->mostrarFormulario('', [], isset($_GET['enviada']));
            return;
        }

        $fields = [
            'email',
            'password',
            'razon_social',
            'nombre_comercial',
            'sector',
            'cif',
            'representante_legal',
            'dni_representante',
            'telefono',
            'direccion',
        ];
        $input = [];
        $values = [];
        $inputErrors = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $input[$field] = '';
                $inputErrors[$field] = 'Introduce un valor de texto válido.';
                continue;
            }
            $input[$field] = $field === 'password' ? $value : trim($value);
            if ($field !== 'password' && $field !== 'dni_representante') {
                $values[$field] = $input[$field];
            }
        }
        if ($inputErrors !== []) {
            $this->fallarRegistro(
                'Los datos del registro deben ser valores de texto válidos.',
                $values,
                422,
                $inputErrors
            );
            return;
        }
        $email = $input['email'];
        $password = $input['password'];
        $cif = $input['cif'];
        $razonSocial = $input['razon_social'];
        $nombreComercial = $input['nombre_comercial'];
        $sector = $input['sector'];
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $representante = $input['representante_legal'];
        $dniRepresentante = SpanishDataValidator::normalizeDniNie($input['dni_representante']);
        $cifNormalizado = strtoupper((string)preg_replace('/[\s-]+/u', '', $cif));

        $fieldErrors = [];
        if (!SpanishDataValidator::isEmail($email)) {
            $fieldErrors['email'] = 'Introduce un correo electrónico válido.';
        }
        if (strlen($password) < 12 || strlen($password) > 72 || trim($password) === '') {
            $fieldErrors['password'] = 'La contraseña debe tener entre 12 y 72 bytes y no estar vacía.';
        }
        if (!SpanishDataValidator::isSpanishTaxId($cifNormalizado) || mb_strlen($cifNormalizado) > 20) {
            $fieldErrors['cif'] = 'Introduce un NIF (Persona Jurídica) válido de hasta 20 caracteres.';
        }
        if ($razonSocial === '' || mb_strlen($razonSocial) > 150) {
            $fieldErrors['razon_social'] = 'La razón social es obligatoria y no puede superar 150 caracteres.';
        }
        if ($nombreComercial === '' || mb_strlen($nombreComercial) > 150) {
            $fieldErrors['nombre_comercial'] = 'El nombre comercial es obligatorio y no puede superar 150 caracteres.';
        }
        if ($sector === '' || mb_strlen($sector) > 100) {
            $fieldErrors['sector'] = 'Indica un sector de hasta 100 caracteres.';
        }
        if (!SpanishDataValidator::isPersonName($representante, 150)) {
            $fieldErrors['representante_legal'] = 'Introduce un nombre válido para el representante legal.';
        }
        if ($dniRepresentante === '' || !SpanishDataValidator::isDniNie($dniRepresentante)) {
            $fieldErrors['dni_representante'] = 'Introduce un DNI/NIE válido para el representante.';
        }
        if ($telefono === '' || mb_strlen($telefono) > 20) {
            $fieldErrors['telefono'] = 'El teléfono es obligatorio y no puede superar 20 caracteres.';
        }
        if ($direccion === '' || mb_strlen($direccion) > 255) {
            $fieldErrors['direccion'] = 'La dirección es obligatoria y no puede superar 255 caracteres.';
        }
        if ($fieldErrors !== []) {
            $this->fallarRegistro(
                'Revisa el correo, el NIF (Persona Jurídica), la razón social y los datos del representante legal.',
                $values,
                422,
                $fieldErrors
            );
            return;
        }

        if ($this->registroDuplicado($email, $cifNormalizado)) {
            $this->fallarRegistro(
                'El correo o el NIF (Persona Jurídica) ya está registrado.',
                $values,
                409,
                [
                    'email' => 'El correo o el NIF (Persona Jurídica) ya está registrado.',
                    'cif' => 'El correo o el NIF (Persona Jurídica) ya está registrado.',
                ]
            );
            return;
        }

        try {
            $this->db->beginTransaction();

            $usuarioId = $this->usuarioModel->crear($email, $password, 'Empresa');
            if ($usuarioId < 1) {
                throw new RuntimeException('No se pudo crear el usuario de la empresa.');
            }

            $empresaId = $this->empresaModel->crear(
                $usuarioId,
                $cifNormalizado,
                $razonSocial,
                $telefono,
                $direccion,
                $representante,
                $nombreComercial,
                $representante,
                $dniRepresentante,
                'Pendiente_Aprobacion',
                $sector
            );
            if ($empresaId < 1) {
                throw new RuntimeException('No se pudo crear el perfil de la empresa.');
            }

            $this->db->commit();
            if (JsonResponse::wantsJson()) {
                JsonResponse::success([
                    'id' => $empresaId,
                    'estado' => 'Pendiente_Aprobacion',
                    'message' => self::MENSAJE_SOLICITUD_ENVIADA,
                ], 201);
                return;
            }

            header('Location: index.php?action=registro-empresa&enviada=1');
            exit;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($this->registroDuplicado($email, $cifNormalizado)) {
                $this->fallarRegistro(
                    'El correo o el NIF (Persona Jurídica) ya está registrado.',
                    $values,
                    409,
                    [
                        'email' => 'El correo o el NIF (Persona Jurídica) ya está registrado.',
                        'cif' => 'El correo o el NIF (Persona Jurídica) ya está registrado.',
                    ]
                );
                return;
            }
            error_log('Error al registrar empresa: ' . $e->getMessage());
            $this->fallarRegistro('No se pudo completar la solicitud de alta.', $values, 500);
        }
    }

    public function solicitudes(): void
    {
        $estado = $_GET['estado'] ?? 'Pendiente_Aprobacion';
        if (!is_string($estado) || !in_array($estado, ['Pendiente_Aprobacion', 'Aprobada', 'Rechazada'], true)) {
            $estado = 'Pendiente_Aprobacion';
        }

        View::render('empresas/solicitudes', [
            'empresas' => $this->empresaModel->listarSolicitudes($estado),
            'estado' => $estado,
            'csrf' => CsrfMiddleware::token(),
            'aprobada' => ($_GET['aprobada'] ?? null) === '1',
        ]);
    }

    public function estadoSolicitud(): void
    {
        $estado = $_SESSION['empresa_solicitud_estado'] ?? null;
        if (!is_string($estado) || !in_array($estado, ['Pendiente_Aprobacion', 'Rechazada', 'SinSolicitud'], true)) {
            header('Location: index.php?action=login');
            exit;
        }

        View::render('empresas/solicitud-pendiente', ['estado' => $estado]);
    }

    public function formularioAprobacion(): void
    {
        $empresaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $empresa = $empresaId === false ? null : $this->empresaModel->obtenerSolicitudPorId((int)$empresaId);
        if ($empresa === null) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }

        $error = ($empresa['estado'] ?? null) === 'Pendiente_Aprobacion'
            ? ''
            : 'Esta solicitud ya no está pendiente de aprobación.';
        if ($error !== '') {
            http_response_code(409);
        }
        $this->mostrarFormularioAprobacion($empresa, $error);
    }

    public function aprobar(): void
    {
        $empresaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($empresaId === false) {
            http_response_code(404);
            View::render('errors/404');
            return;
        }
        $empresaId = (int)$empresaId;
        try {
            $this->aprobarSolicitud(
                $empresaId,
                $_POST['numero_convenio'] ?? null,
                $_FILES['pdf_convenio'] ?? null,
                (int)$_SESSION['usuario_id']
            );
            header('Location: index.php?action=solicitudes-empresa&estado=Aprobada&aprobada=1');
            exit;
        } catch (OutOfBoundsException $exception) {
            http_response_code(404);
            View::render('errors/404');
        } catch (DomainException $exception) {
            http_response_code(409);
            $this->mostrarFormularioAprobacion(
                $this->empresaModel->obtenerSolicitudPorId($empresaId) ?? [],
                $exception->getMessage()
            );
        } catch (InvalidArgumentException $exception) {
            http_response_code(422);
            $this->mostrarFormularioAprobacion(
                $this->empresaModel->obtenerSolicitudPorId($empresaId) ?? [],
                $exception->getMessage()
            );
        } catch (FileScannerUnavailableException $exception) {
            error_log('Análisis antivirus no disponible al aprobar empresa: ' . $exception->getMessage());
            http_response_code(503);
            $this->mostrarFormularioAprobacion(
                $this->empresaModel->obtenerSolicitudPorId($empresaId) ?? [],
                'No se puede aprobar la empresa porque el análisis antivirus no está disponible.'
            );
        } catch (Throwable $exception) {
            error_log('Error al aprobar empresa: ' . $exception->getMessage());
            http_response_code(500);
            $this->mostrarFormularioAprobacion(
                $this->empresaModel->obtenerSolicitudPorId($empresaId) ?? [],
                'No se pudo completar la aprobación.'
            );
        }
    }

    public function aprobarApi(): void
    {
        $empresaId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($empresaId === false) {
            JsonResponse::failure('La solicitud de empresa no existe.', 404);
            return;
        }

        try {
            $this->aprobarSolicitud(
                (int)$empresaId,
                $_POST['numero_convenio'] ?? null,
                $_FILES['pdf_convenio'] ?? null,
                (int)$_SESSION['usuario_id']
            );
            JsonResponse::success([
                'id' => (int)$empresaId,
                'estado' => 'Aprobada',
                'message' => 'La empresa se ha aprobado correctamente.',
            ]);
        } catch (OutOfBoundsException $exception) {
            JsonResponse::failure($exception->getMessage(), 404);
        } catch (DomainException $exception) {
            JsonResponse::failure($exception->getMessage(), 409);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::failure($exception->getMessage(), 422);
        } catch (FileScannerUnavailableException $exception) {
            error_log('Análisis antivirus no disponible al aprobar empresa: ' . $exception->getMessage());
            JsonResponse::failure('No se puede aprobar la empresa porque el análisis antivirus no está disponible.', 503);
        } catch (Throwable $exception) {
            error_log('Error al aprobar empresa por API: ' . $exception->getMessage());
            JsonResponse::failure('No se pudo completar la aprobación.', 500);
        }
    }

    private function aprobarSolicitud(int $empresaId, mixed $numeroConvenio, mixed $upload, int $actorId): void
    {
        if (!is_string($numeroConvenio) || trim($numeroConvenio) === '' || mb_strlen(trim($numeroConvenio)) > 100) {
            throw new InvalidArgumentException('Indica un número de convenio de hasta 100 caracteres.');
        }
        $empresa = $this->empresaModel->obtenerSolicitudPorId($empresaId);
        if ($empresa === null) {
            throw new OutOfBoundsException('La solicitud de empresa no existe.');
        }
        if ($empresa['estado'] !== 'Pendiente_Aprobacion') {
            throw new DomainException('Esta solicitud ya no está pendiente de aprobación.');
        }
        if (!is_array($upload)) {
            throw new InvalidArgumentException('Adjunta el convenio firmado en formato PDF.');
        }

        $storedFile = PrivatePdfStorage::store($upload, 'uploads/convenios-marco');
        try {
            $this->db->beginTransaction();
            if (!$this->empresaModel->aprobarSolicitud(
                $empresaId,
                trim($numeroConvenio),
                $storedFile['relative_path']
            )) {
                throw new DomainException('Esta solicitud ya no está pendiente de aprobación.');
            }
            (new Auditoria($this->db))->registrar(
                $actorId,
                'empresa.aprobada',
                'empresas',
                $empresaId,
                'numero_convenio=' . trim($numeroConvenio)
            );
            $adminQuery = $this->db->prepare(
                "SELECT COALESCE(NULLIF(TRIM(CONCAT_WS(' ', c.nombre, c.apellidos)), ''), u.email) AS nombre_completo
                 FROM usuarios u
                 LEFT JOIN coordinadores c ON c.usuario_id = u.id
                 WHERE u.id = :id"
            );
            $adminQuery->execute(['id' => $actorId]);
            $nombreCompleto = $adminQuery->fetchColumn();
            if (!is_string($nombreCompleto) || $nombreCompleto === '') {
                throw new RuntimeException('No se pudo identificar al administrador que aprobó la empresa.');
            }
            $auditLog = $this->db->prepare(
                "INSERT INTO audit_logs
                    (id_usuario_admin, nombre_completo, accion, entidad_afectada, id_registro_modificado, ip_address)
                 VALUES
                    (:admin_id, :nombre_completo, 'APROBAR_EMPRESA', 'empresa', :registro_id, :ip_address)"
            );
            $auditLog->execute([
                'admin_id' => $actorId,
                'nombre_completo' => $nombreCompleto,
                'registro_id' => $empresaId,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_file($storedFile['path']) && !unlink($storedFile['path'])) {
                error_log('No se pudo retirar el PDF de convenio tras fallar la aprobación.');
            }
            throw $exception;
        }
    }

    private function registroDuplicado(string $email, string $cif): bool
    {
        $statement = $this->db->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM usuarios WHERE email = :email)
                OR EXISTS(SELECT 1 FROM empresas WHERE cif_empresa = :cif)'
        );
        $statement->execute(['email' => $email, 'cif' => $cif]);
        return (bool)$statement->fetchColumn();
    }

    private function fallarRegistro(string $error, array $values, int $status, array $fieldErrors = []): void
    {
        if (JsonResponse::wantsJson()) {
            JsonResponse::failure($error, $status);
            return;
        }
        $this->mostrarFormulario($error, $values, false, $status, $fieldErrors);
    }

    private function mostrarFormulario(
        string $error = '',
        array $values = [],
        bool $success = false,
        int $status = 200,
        array $fieldErrors = []
    ): void {
        http_response_code($status);
        View::render('empresas/registro', [
            'error' => $error,
            'success' => $success,
            'values' => $values,
            'fieldErrors' => $fieldErrors,
            'csrf' => CsrfMiddleware::token(),
        ]);
    }

    private function mostrarFormularioAprobacion(array $empresa, string $error = ''): void
    {
        View::render('empresas/aprobar', [
            'empresa' => $empresa,
            'error' => $error,
            'csrf' => CsrfMiddleware::token(),
        ]);
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
        $values = ['nombre' => '', 'direccion' => '', 'telefono' => ''];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nombre = $_POST['nombre'] ?? '';
            $direccion = $_POST['direccion'] ?? '';
            $telefono = $_POST['telefono'] ?? '';
            foreach (['nombre' => $nombre, 'direccion' => $direccion, 'telefono' => $telefono] as $field => $value) {
                if (is_string($value)) {
                    $values[$field] = $value;
                }
            }
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
            'values' => $values,
        ]);
    }
}
