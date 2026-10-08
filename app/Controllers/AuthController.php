<?php
// app/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';

class AuthController {
    private Usuario $usuarioModel;

    public function __construct(?Usuario $usuarioModel = null) {
        $this->usuarioModel = $usuarioModel ?? new Usuario();
    }

    public function login(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->mostrarFormulario();
            return;
        }

        $emailRaw = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        if (!is_string($emailRaw) || !is_string($password)) {
            $this->mostrarFormulario('Los datos de acceso no tienen un formato válido.');
            return;
        }
        $email = trim($emailRaw);

        if ($email === '' || $password === '' || strlen($password) > 72) {
            $this->mostrarFormulario('Por favor completa todos los campos.');
            return;
        }

        $usuario = $this->usuarioModel->obtenerPorEmail($email);

        if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
            $this->mostrarFormulario('Credenciales incorrectas.');
            return;
        }
        if (isset($usuario['activo']) && (int)$usuario['activo'] !== 1) {
            $this->mostrarFormulario('La cuenta está desactivada. Contacta con el centro educativo.');
            return;
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['email']      = $usuario['email'];
        $_SESSION['rol']        = $usuario['rol'];

        $destino = $usuario['rol'] === 'Empresa' ? 'publicar-oferta' : 'ofertas';
        header('Location: index.php?action=' . $destino);
        exit;
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        header('Location: index.php?action=login');
        exit;
    }

    private function mostrarFormulario(string $error = ''): void {
        View::render('auth/login', ['error' => $error]);
    }
}
