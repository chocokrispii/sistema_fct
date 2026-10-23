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
            'cif',
            'representante_legal',
            'dni_representante',
            'telefono',
            'direccion',
        ];
        $input = [];
        $values = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->fallarRegistro('Los datos del registro deben ser valores de texto válidos.', $values, 422);
                return;
            }
            $input[$field] = $field === 'password' ? $value : trim($value);
            if ($field !== 'password' && $field !== 'dni_representante') {
                $values[$field] = $input[$field];
            }
        }
        $email = $input['email'];
        $password = $input['password'];
        $cif = $input['cif'];
        $razonSocial = $input['razon_social'];
        $nombreComercial = $input['nombre_comercial'] !== '' ? $input['nombre_comercial'] : $razonSocial;
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $representante = $input['representante_legal'];
        $dniRepresentante = SpanishDataValidator::normalizeDniNie($input['dni_representante']);
        $cifNormalizado = strtoupper((string)preg_replace('/[\s-]+/u', '', $cif));

        if (
            !SpanishDataValidator::isEmail($email)
            || strlen($password) < 12
            || strlen($password) > 72
            || trim($password) === ''
            || !SpanishDataValidator::isSpanishTaxId($cifNormalizado)
            || mb_strlen($cifNormalizado) > 20
            || $razonSocial === ''
            || mb_strlen($razonSocial) > 150
            || mb_strlen($nombreComercial) > 150
            || !SpanishDataValidator::isPersonName($representante, 150)
            || ($dniRepresentante !== '' && !SpanishDataValidator::isDniNie($dniRepresentante))
            || mb_strlen($telefono) > 20
            || $direccion === ''
            || mb_strlen($direccion) > 255
        ) {
            $this->fallarRegistro(
                'Revisa el correo, el CIF/NIF, la razón social y los datos del representante legal.',
                $values,
                422
            );
            return;
        }

        if ($this->registroDuplicado($email, $cifNormalizado)) {
            $this->fallarRegistro('El correo o el CIF/NIF ya está registrado.', $values, 409);
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
                $dniRepresentante
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
                $this->fallarRegistro('El correo o el CIF/NIF ya está registrado.', $values, 409);
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

    private function fallarRegistro(string $error, array $values, int $status): void
    {
        if (JsonResponse::wantsJson()) {
            JsonResponse::failure($error, $status);
            return;
        }
        $this->mostrarFormulario($error, $values, false, $status);
    }

    private function mostrarFormulario(
        string $error = '',
        array $values = [],
        bool $success = false,
        int $status = 200
    ): void {
        http_response_code($status);
        View::render('empresas/registro', [
            'error' => $error,
            'success' => $success,
            'values' => $values,
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
