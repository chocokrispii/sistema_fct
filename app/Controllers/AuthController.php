<?php
// app/Controllers/AuthController.php

require_once __DIR__ . '/../Models/Usuario.php';
require_once __DIR__ . '/../Models/IntentoLogin.php';
require_once __DIR__ . '/../Core/JsonResponse.php';

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

        if (JsonResponse::wantsJson()) {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                JsonResponse::failure('El cuerpo JSON de inicio de sesión no es válido.', 400);
                return;
            }
        } else {
            $input = $_POST;
        }

        $emailRaw = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        if (!is_string($emailRaw) || !is_string($password)) {
            $this->mostrarFormulario(
                'Los datos de acceso no tienen un formato válido.',
                422,
                is_string($emailRaw) ? trim($emailRaw) : ''
            );
            return;
        }
        $email = trim($emailRaw);

        if ($email === '' || $password === '') {
            $this->mostrarFormulario('Por favor completa todos los campos.', 422, $email);
            return;
        }

        $intentos = new IntentoLogin();
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ahora = new DateTimeImmutable();
        if ($intentos->estaBloqueado($email, $ip, $ahora)) {
            $this->mostrarFormulario(
                'Demasiados intentos fallidos. Espera ' . IntentoLogin::VENTANA_MINUTOS . ' minutos y vuelve a intentarlo.',
                429,
                $email
            );
            return;
        }
        if (strlen($password) > 72) {
            $intentos->registrarFallo($email, $ip, $ahora);
            $this->mostrarFormulario('La contraseña no puede superar 72 bytes.', 422, $email);
            return;
        }

        $usuario = $this->usuarioModel->obtenerPorEmail($email);
        $correcta = password_verify($password, $usuario['password_hash'] ?? self::HASH_FICTICIO);

        if (!$usuario || !$correcta) {
            $intentos->registrarFallo($email, $ip, $ahora);
            $this->mostrarFormulario('Credenciales incorrectas.', 401, $email);
            return;
        }
        $intentos->limpiar($email);
        if (isset($usuario['activo']) && (int)$usuario['activo'] !== 1) {
            $this->mostrarFormulario('La cuenta está desactivada. Contacta con el centro educativo.', 403, $email);
            return;
        }
        if ($usuario['rol'] === 'Empresa') {
            $approvalState = $this->empresaApprovalState((int)$usuario['id']);
            if ($approvalState !== 'Aprobada') {
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['empresa_solicitud_estado'] = in_array(
                    $approvalState,
                    ['Pendiente_Aprobacion', 'Rechazada'],
                    true
                ) ? $approvalState : 'SinSolicitud';
                $approvalError = $this->empresaApprovalMessage($approvalState);
                if (JsonResponse::wantsJson()) {
                    JsonResponse::failure($approvalError, 403);
                    return;
                }
                header('Location: index.php?action=solicitud-pendiente');
                exit;
            }
        }

        session_regenerate_id(true);
        unset($_SESSION['csrf_token']);
        unset($_SESSION['empresa_solicitud_estado']);
        $_SESSION['usuario_id'] = (int)$usuario['id'];
        $_SESSION['email']      = $usuario['email'];
        $_SESSION['rol']        = $usuario['rol'];
        $_SESSION['session_version'] = (int)($usuario['session_version'] ?? 0);

        if (JsonResponse::wantsJson()) {
            JsonResponse::success([
                'user' => $this->sessionUser($usuario),
                'csrfToken' => CsrfMiddleware::token(),
            ]);
            return;
        }

        $destino = match ($usuario['rol']) {
            'Empresa' => 'publicar-oferta',
            'Alumno' => 'portal-alumno',
            'Observador' => 'asignaciones',
            default => 'inicio',
        };
        header('Location: index.php?action=' . $destino);
        exit;
    }

    public function session(): void
    {
        $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user = null;

        if ($userId !== false) {
            $stmt = getPDOConnection()->prepare(
                'SELECT id, email, rol, activo, session_version FROM usuarios WHERE id = :id'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (
                is_array($row)
                && (int)$row['activo'] === 1
                && (int)($row['session_version'] ?? 0) === (int)($_SESSION['session_version'] ?? -1)
            ) {
                if ($row['rol'] === 'Empresa') {
                    $approvalState = $this->empresaApprovalState((int)$row['id']);
                    if ($approvalState !== 'Aprobada') {
                        $_SESSION = [];
                        session_regenerate_id(true);
                        $_SESSION['empresa_solicitud_estado'] = in_array(
                            $approvalState,
                            ['Pendiente_Aprobacion', 'Rechazada'],
                            true
                        ) ? $approvalState : 'SinSolicitud';
                        JsonResponse::failure($this->empresaApprovalMessage($approvalState), 403);
                        return;
                    }
                }
                $_SESSION['email'] = $row['email'];
                $_SESSION['rol'] = $row['rol'];
                $_SESSION['session_version'] = (int)$row['session_version'];
                $user = $this->sessionUser($row);
            } else {
                $_SESSION = [];
                session_regenerate_id(true);
            }
        }

        JsonResponse::success([
            'user' => $user,
            'csrfToken' => CsrfMiddleware::token(),
        ]);
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();

        if (JsonResponse::wantsJson()) {
            JsonResponse::success(['user' => null]);
            return;
        }

        header('Location: index.php?action=login');
        exit;
    }

    private function sessionUser(array $user): array
    {
        return [
            'id' => (int)$user['id'],
            'email' => (string)$user['email'],
            'rol' => (string)$user['rol'],
            'activo' => (bool)$user['activo'],
        ];
    }

    private function empresaApprovalState(int $userId): ?string
    {
        $stmt = getPDOConnection()->prepare(
            'SELECT estado FROM empresas WHERE usuario_id = :usuario_id LIMIT 1'
        );
        $stmt->execute(['usuario_id' => $userId]);
        $estado = $stmt->fetchColumn();

        return is_string($estado) ? $estado : null;
    }

    private function empresaApprovalMessage(?string $estado): string
    {
        return match ($estado) {
            'Pendiente_Aprobacion' => 'La solicitud de empresa está pendiente de aprobación; todavía no puedes acceder a los módulos privados.',
            'Rechazada' => 'La solicitud de empresa ha sido rechazada. Contacta con el centro educativo.',
            default => 'No se encontró un perfil de empresa aprobado para esta cuenta.',
        };
    }

    private function mostrarFormulario(string $error = '', int $status = 200, string $email = ''): void {
        if (JsonResponse::wantsJson()) {
            JsonResponse::failure($error, $status);
            return;
        }

        http_response_code($status);
        View::render('auth/login', ['error' => $error, 'email' => $email]);
    }
}
