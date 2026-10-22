<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

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

        $fields = ['email', 'password', 'cif', 'nombre_comercial', 'telefono', 'direccion', 'persona_contacto'];
        $input = [];
        foreach ($fields as $field) {
            $value = $_POST[$field] ?? '';
            if (!is_string($value)) {
                $this->mostrarFormulario('Los datos del registro deben ser valores de texto válidos.');
                return;
            }
            // La contraseña no se recorta: los espacios cuentan.
            $input[$field] = $field === 'password' ? $value : trim($value);
        }
        $email = $input['email'];
        $password = $input['password'];
        $cif = SpanishDataValidator::normalizeFiscalIdentifier($input['cif']);
        $nombreComercial = $input['nombre_comercial'];
        $telefono = $input['telefono'];
        $direccion = $input['direccion'];
        $contacto = $input['persona_contacto'];

        if (
            !filter_var($email, FILTER_VALIDATE_EMAIL)
            || strlen($email) > 100
            || strlen($password) < 12
            || strlen($password) > 72
            || trim($password) === ''
            || !SpanishDataValidator::isCifFormat($cif)
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
            $this->db->beginTransaction();
            $this->usuarioModel->assertIdentificadorFiscalDisponible($cif);

            $usuarioId = $this->usuarioModel->crear($email, $password, 'Empresa');
            if ($usuarioId < 1) {
                throw new RuntimeException('No se pudo crear el usuario de la empresa.');
            }

            $empresaId = $this->empresaModel->crear(
                $usuarioId,
                $cif,
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
            $this->mostrarFormulario('No se pudo completar el registro. Comprueba que el correo y el CIF no estén ya registrados.');
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
