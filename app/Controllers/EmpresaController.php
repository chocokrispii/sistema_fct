<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/../Core/ActivationUrl.php';

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

        if (!SpanishDataValidator::isNifPersonaJuridica($nif)) {
            $this->mostrarFormulario('Introduce un NIF (Persona Jurídica) con un carácter de control válido.');
            return;
        }

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 100
            || $nombreComercial === ''
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
            $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
            if (!ActivationUrl::isAllowedBaseUrl($appUrl)) {
                throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible para la empresa.');
            }
            $activationToken = bin2hex(random_bytes(32));
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

            $activationInsert = $this->db->prepare(
                'INSERT INTO company_activation_tokens (usuario_id, token_hash, expires_at)
                 VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $activationInsert->execute([
                'usuario_id' => $usuarioId,
                'token_hash' => hash('sha256', $activationToken),
            ]);
            $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($activationToken);
            (new AccountActivationMailer())->send($email, $nombreComercial, $activationUrl);
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
