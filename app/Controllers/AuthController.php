<?php
// app/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/IntentoLogin.php';

class AuthController {
    /** Mismo coste que password_hash por defecto: el correo inexistente tarda igual que uno real. */
    private const HASH_FICTICIO = '$2y$10$VIVNueDI3jrUbHmTwaNXk.ESaHwieDE0R6ob.kpP.SqhQQO/pgOiO';

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

        if ($email === '' || $password === '') {
            $this->mostrarFormulario('Por favor completa todos los campos.');
            return;
        }

        $intentos = new IntentoLogin();
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ahora = new DateTimeImmutable();
        if ($intentos->estaBloqueado($email, $ip, $ahora)) {
            http_response_code(429);
            $this->mostrarFormulario('Demasiados intentos fallidos. Espera ' . IntentoLogin::VENTANA_MINUTOS . ' minutos y vuelve a intentarlo.');
            return;
        }
        if (strlen($password) > 72) {
            $intentos->registrarFallo($email, $ip, $ahora);
            $this->mostrarFormulario('La contraseña no puede superar 72 caracteres.');
            return;
        }

        $usuario = $this->usuarioModel->obtenerPorEmail($email);
        $correcta = password_verify($password, $usuario['password_hash'] ?? self::HASH_FICTICIO);

        if (!$usuario || !$correcta) {
            $intentos->registrarFallo($email, $ip, $ahora);
            $this->mostrarFormulario('Credenciales incorrectas.');
            return;
        }
        $intentos->limpiar($email);
        if (isset($usuario['activo']) && (int)$usuario['activo'] !== 1) {
            $this->mostrarFormulario('La cuenta está desactivada. Contacta con el centro educativo.');
            return;
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['email']      = $usuario['email'];
        $_SESSION['rol']        = $usuario['rol'];

        $destino = match ($usuario['rol']) {
            'Empresa' => 'publicar-oferta',
            'Alumno' => 'portal-alumno',
            'Observador' => 'asignaciones',
            default => 'inicio',
        };
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
