<?php
// app/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';

class AuthController {
    private Usuario $usuarioModel;

    public function __construct() {
        $this->usuarioModel = new Usuario();
    }

    public function login(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $email    = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if (empty($email) || empty($password)) {
                echo "<p style='color:red;'>Por favor completa todos los campos.</p>";
                return;
            }

            $usuario = $this->usuarioModel->obtenerPorEmail($email);

            if ($usuario && password_verify($password, $usuario['password'])) {
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                $_SESSION['usuario_id'] = $usuario['id'];
                $_SESSION['email']      = $usuario['email'];
                $_SESSION['rol']        = $usuario['rol'];

                echo "<p style='color:green;'>Inicio de sesión exitoso. Bienvenido (" . htmlspecialchars($usuario['rol']) . ")</p>";
                return;
            }

            echo "<p style='color:red;'>Credenciales incorrectas.</p>";
        }
    }

    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();
        header('Location: index.php');
        exit;
    }
}