<?php
// app/Controllers/EmpresaController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/Empresa.php';

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
            $this->mostrarFormulario();
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
            echo "<p style='color:green;'>Empresa registrada con éxito. <a href='index.php?action=login'>Inicia sesión</a> para publicar ofertas.</p>";
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('Error al registrar empresa: ' . $e->getMessage());
            echo "<p style='color:red;'>No se pudo completar el registro de la empresa.</p>";
            $this->mostrarFormulario();
        }
    }

    private function mostrarFormulario(): void {
        echo '
        <h2>Registro de empresa</h2>
        <form method="POST" action="index.php?action=registro-empresa">
            <input type="email" name="email" placeholder="Correo de acceso" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <input type="text" name="nombre_comercial" placeholder="Nombre de la empresa" required>
            <input type="text" name="cif" placeholder="CIF" required>
            <input type="text" name="persona_contacto" placeholder="Persona de contacto">
            <input type="text" name="telefono" placeholder="Teléfono">
            <input type="text" name="direccion" placeholder="Dirección" required>
            <button type="submit">Registrar empresa</button>
        </form>';
    }
}
