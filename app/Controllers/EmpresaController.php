<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';

class EmpresaController {
    private Usuario $usuarioModel;
    private Empresa $empresaModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
        $this->empresaModel = new Empresa();
    }

    public function registrar(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email           = trim($_POST['email'] ?? '');
            $password        = $_POST['password'] ?? '';
            $cif             = trim($_POST['cif'] ?? '');
            $nombreComercial = trim($_POST['nombre_comercial'] ?? '');
            $telefono        = trim($_POST['telefono'] ?? '');
            $direccion       = trim($_POST['direccion'] ?? '');
            $contacto        = trim($_POST['persona_contacto'] ?? '');

            if (
                !filter_var($email, FILTER_VALIDATE_EMAIL)
                || $password === ''
                || $cif === ''
                || $nombreComercial === ''
                || $direccion === ''
            ) {
                echo "<p style='color:red;'>Error: Todos los campos obligatorios deben completarse.</p>";
                return;
            }

            $db = getPDOConnection();
            try {
                $db->beginTransaction();

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

                $db->commit();
                echo "<p style='color:green;'>Empresa registrada con éxito.</p>";
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                error_log('Error al registrar empresa: ' . $e->getMessage());
                echo "<p style='color:red;'>No se pudo completar el registro de la empresa.</p>";
            }
        }
    }
}