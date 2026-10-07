<?php
// app/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';

class AuthController {
    private Usuario $usuarioModel;

    public function __construct(?Usuario $usuarioModel = null) {
        $this->usuarioModel = $usuarioModel ?? new Usuario();
    }

    public function login(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->mostrarFormulario();
            return;
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $this->mostrarFormulario('Por favor completa todos los campos.');
            return;
        }

        $usuario = $this->usuarioModel->obtenerPorEmail($email);

        if (!$usuario || !password_verify($password, $usuario['password_hash'])) {
            $this->mostrarFormulario('Credenciales incorrectas.');
            return;
        }

        session_regenerate_id(true);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['email']      = $usuario['email'];
        $_SESSION['rol']        = $usuario['rol'];

        $destino = $usuario['rol'] === 'Empresa' ? 'publicar-oferta' : 'ofertas';
        header('Location: index.php?action=' . $destino);
        exit;
    }

    public function logout(): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

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
        if ($error !== '') {
            echo '<p style="color:red;">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '
        <h2>Iniciar sesión</h2>
        <form method="POST" action="index.php?action=login">
            <input type="email" name="email" placeholder="Correo" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            <button type="submit">Entrar</button>
        </form>';
    }
}
