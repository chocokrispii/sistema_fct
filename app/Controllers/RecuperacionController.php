<?php

require_once __DIR__ . '/../Models/SolicitudResetPassword.php';
require_once __DIR__ . '/../Models/IntentoLogin.php';
require_once __DIR__ . '/../Core/PasswordResetMailer.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class RecuperacionController
{
    private const MENSAJE_SOLICITUD = 'Su solicitud de restablecimiento ha sido enviada al Administrador. Recibirá un correo electrónico con las instrucciones una vez que sea aprobada.';
    private const MENSAJE_ENLACE_INVALIDO = 'El enlace no es válido o ha caducado.';
    private const SESSION_TOKEN_KEY = 'password_reset_token';

    private SolicitudResetPassword $solicitudes;

    public function __construct(?PDO $db = null)
    {
        $this->solicitudes = new SolicitudResetPassword($db ?? getPDOConnection());
    }

    public function solicitar(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            View::render('auth/recuperar', [
                'error' => '',
                'csrf' => CsrfMiddleware::token(),
            ]);
            return;
        }

        $email = $_POST['email'] ?? '';
        if (is_string($email) && SpanishDataValidator::isEmail(trim($email))) {
            $this->solicitudes->solicitar(mb_strtolower(trim($email)), new DateTimeImmutable());
        }
        header('Location: index.php?action=recuperar&enviada=1');
        exit;
    }

    public function solicitarApi(): void
    {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            JsonResponse::failure('El cuerpo JSON de la solicitud no es válido.', 400);
            return;
        }
        $email = $input['email'] ?? null;
        if (!is_string($email) || !SpanishDataValidator::isEmail(trim($email))) {
            JsonResponse::failure('Introduce un correo electrónico válido.', 422);
            return;
        }

        $this->solicitudes->solicitar(mb_strtolower(trim($email)), new DateTimeImmutable());
        JsonResponse::success(['message' => self::MENSAJE_SOLICITUD], 202);
    }

    public function listar(): void
    {
        View::render('auth/recuperaciones', [
            'recuperaciones' => $this->solicitudes->pendientes(new DateTimeImmutable()),
            'csrf' => CsrfMiddleware::token(),
            'aprobada' => ($_GET['aprobada'] ?? null) === '1',
            'rechazada' => ($_GET['rechazada'] ?? null) === '1',
            'error' => ($_GET['error'] ?? null) === '1',
        ]);
    }

    public function aprobar(): void
    {
        $id = $this->requestId();
        if ($id === false) {
            $this->respondAdminError(404, 'La solicitud ya no está pendiente.');
            return;
        }

        $adminId = (int)($_SESSION['usuario_id'] ?? 0);
        $approval = $this->solicitudes->aprobar($id, $adminId);
        if ($approval === null) {
            $this->respondAdminError(409, 'La solicitud ya no está pendiente.');
            return;
        }

        try {
            PasswordResetMailer::send($approval['email'], $approval['token']);
        } catch (Throwable $exception) {
            $reopened = $this->solicitudes->reabrirSiNoSeEnvio(
                $id,
                $approval['token'],
                $adminId
            );
            if (!$reopened) {
                error_log('No se pudo volver a pendiente una solicitud de restablecimiento tras fallar el correo.');
            }
            error_log('No se pudo enviar el correo de restablecimiento aprobado.');
            $this->respondAdminError(503, 'No se pudo enviar el correo. La solicitud permanece pendiente para volver a intentarlo.');
            return;
        }

        if (JsonResponse::wantsJson()) {
            JsonResponse::success([
                'id' => $id,
                'estado' => 'Aprobada',
                'message' => 'La solicitud se aprobó y se envió el enlace por correo.',
            ]);
            return;
        }
        header('Location: index.php?action=recuperaciones&aprobada=1');
        exit;
    }

    public function rechazar(): void
    {
        $id = $this->requestId();
        if ($id === false) {
            $this->respondAdminError(404, 'La solicitud ya no está pendiente.');
            return;
        }
        if (!$this->solicitudes->rechazar($id)) {
            $this->respondAdminError(409, 'La solicitud ya no está pendiente.');
            return;
        }

        if (JsonResponse::wantsJson()) {
            JsonResponse::success([
                'id' => $id,
                'estado' => 'Rechazada',
                'message' => 'La solicitud se rechazó correctamente.',
            ]);
            return;
        }
        header('Location: index.php?action=recuperaciones&rechazada=1');
        exit;
    }

    public function verificar(): void
    {
        $this->aprobar();
    }

    public function restablecer(): void
    {
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $token = $isPost
            ? ($_POST['token'] ?? ($_SESSION[self::SESSION_TOKEN_KEY] ?? ''))
            : ($_GET['token'] ?? '');
        if (!is_string($token)) {
            $token = '';
        }

        $now = new DateTimeImmutable();
        if ($token === '' || strlen($token) !== 64 || !$this->solicitudes->vigente($token, $now)) {
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
            http_response_code(410);
            View::render('auth/restablecer', [
                'canReset' => false,
                'token' => '',
                'csrf' => CsrfMiddleware::token(),
                'error' => self::MENSAJE_ENLACE_INVALIDO,
            ]);
            return;
        }

        $_SESSION[self::SESSION_TOKEN_KEY] = $token;
        if (!$isPost) {
            View::render('auth/restablecer', [
                'canReset' => true,
                'token' => $token,
                'csrf' => CsrfMiddleware::token(),
                'error' => '',
            ]);
            return;
        }

        $password = $_POST['password'] ?? null;
        $confirmation = $_POST['confirm_password'] ?? null;
        $error = $this->passwordError($password);
        if ($error === null && (!is_string($confirmation) || !hash_equals((string)$password, $confirmation))) {
            $error = 'Las contraseñas no coinciden.';
        }
        if ($error !== null) {
            http_response_code(422);
            View::render('auth/restablecer', [
                'canReset' => true,
                'token' => $token,
                'csrf' => CsrfMiddleware::token(),
                'error' => $error,
            ]);
            return;
        }

        $email = $this->solicitudes->consumir($token, (string)$password, $now);
        if ($email === null) {
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
            http_response_code(410);
            View::render('auth/restablecer', [
                'canReset' => false,
                'token' => '',
                'csrf' => CsrfMiddleware::token(),
                'error' => self::MENSAJE_ENLACE_INVALIDO,
            ]);
            return;
        }

        unset($_SESSION[self::SESSION_TOKEN_KEY]);
        (new IntentoLogin())->limpiar($email);
        header('Location: index.php?action=login&restablecida=1');
        exit;
    }

    public function confirmarApi(): void
    {
        $input = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($input)) {
            JsonResponse::failure('El cuerpo JSON de confirmación no es válido.', 400);
            return;
        }
        $token = $input['token'] ?? null;
        $password = $input['password'] ?? null;
        $confirmation = $input['confirm_password'] ?? null;
        if (!is_string($token) || strlen($token) !== 64) {
            JsonResponse::failure(self::MENSAJE_ENLACE_INVALIDO, 410);
            return;
        }

        $error = $this->passwordError($password);
        if ($error === null && (!is_string($confirmation) || !hash_equals((string)$password, $confirmation))) {
            $error = 'Las contraseñas no coinciden.';
        }
        if ($error !== null) {
            JsonResponse::failure($error, 422);
            return;
        }

        $email = $this->solicitudes->consumir($token, (string)$password, new DateTimeImmutable());
        if ($email === null) {
            JsonResponse::failure(self::MENSAJE_ENLACE_INVALIDO, 410);
            return;
        }
        (new IntentoLogin())->limpiar($email);
        JsonResponse::success(['message' => 'La contraseña se actualizó correctamente.']);
    }

    private function requestId(): int|false
    {
        return filter_var(
            $_GET['id'] ?? $_POST['id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );
    }

    private function respondAdminError(int $status, string $message): void
    {
        if (JsonResponse::wantsJson()) {
            JsonResponse::failure($message, $status);
            return;
        }
        header('Location: index.php?action=recuperaciones&error=1');
        exit;
    }

    private function passwordError(mixed $password): ?string
    {
        if (
            !is_string($password)
            || strlen($password) < 12
            || strlen($password) > 72
            || trim($password) === ''
        ) {
            return 'La contraseña debe tener entre 12 y 72 bytes y no puede ser solo espacios.';
        }
        if (
            preg_match('/[a-z]/', $password) !== 1
            || preg_match('/[A-Z]/', $password) !== 1
            || preg_match('/[0-9]/', $password) !== 1
        ) {
            return 'La contraseña debe incluir al menos una minúscula, una mayúscula y un número.';
        }
        return null;
    }
}
