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

            if (empty($email) || empty($password) || empty($cif) || empty($nombreComercial)) {
                echo "<p style='color:red;'>Error: Todos los campos obligatorios deben completarse.</p>";
                return;
            }

            // 1. Crear usuario con rol 'empresa'
            $usuarioId = $this->usuarioModel->crear($email, $password, 'empresa');

            if ($usuarioId > 0) {
                // 2. Crear datos especificos de la empresa
                $empresaId = $this->empresaModel->crear($usuarioId, $cif, $nombreComercial, $telefono, $direccion, $contacto);
                
                if ($empresaId > 0) {
                    echo "<p style='color:green;'>Empresa registrada con éxito.</p>";
                    return;
                }
            }

            echo "<p style='color:red;'>Error al procesar el registro de la empresa.</p>";
        }
    }
}