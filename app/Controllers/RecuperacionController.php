<?php

require_once __DIR__ . '/../Models/RecuperacionCuenta.php';
require_once __DIR__ . '/../Models/IntentoLogin.php';
require_once __DIR__ . '/../Core/PasswordResetMailer.php';

class RecuperacionController
{
    private RecuperacionCuenta $recuperaciones;

    public function __construct(?PDO $db = null)
    {
        $this->recuperaciones = new RecuperacionCuenta($db);
    }

    public function solicitar(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            View::render('auth/recuperar', ['error' => '']);
            return;
        }

        $emailRaw = $_POST['email'] ?? '';
        if (is_string($emailRaw)) {
            $email = mb_strtolower(trim($emailRaw));
            if ($email !== '' && mb_strlen($email) <= 100) {
                $this->recuperaciones->solicitar($email, new DateTimeImmutable());
            }
        }

        header('Location: index.php?action=recuperar&enviada=1');
        exit;
    }

    public function listar(): void
    {
        $token = $_GET['token'] ?? '';
        View::render('auth/recuperaciones', [
            'recuperaciones' => $this->recuperaciones->pendientes(),
            'token' => is_string($token) && strlen($token) === 64 ? $token : '',
            'error' => '',
        ]);
    }

    public function hubAdmin(): void
    {
        $pendientesReenvio = (int)getPDOConnection()->query(
            "SELECT COUNT(*) FROM activation_resend_requests WHERE estado = 'Pendiente de Reenvío'"
        )->fetchColumn();

        View::render('admin/gestion-cuentas', ['pendientesReenvio' => $pendientesReenvio]);
    }

    public function verificar(): void
    {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $token = $id === false
            ? null
            : $this->recuperaciones->emitirEnlace(
                (int)$id,
                new DateTimeImmutable(),
                (int)$_SESSION['usuario_id']
            );
        if ($token === null) {
            header('Location: index.php?action=recuperaciones&error=1');
            exit;
        }
        $destinatario = $this->recuperaciones->destinatario((int)$id);
        if ($destinatario === null) {
            header('Location: index.php?action=recuperaciones&error=1');
            exit;
        }
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        (new PasswordResetMailer())->send(
            $destinatario['email'],
            $destinatario['nombre'],
            $appUrl . '/index.php?action=restablecer&token=' . rawurlencode($token)
        );
        header('Location: index.php?action=recuperaciones&enviado=1');
        exit;
    }

    public function restablecer(): void
    {
        $token = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
        if (!is_string($token)) {
            $token = '';
        }
        $ahora = new DateTimeImmutable();
        if ($token === '' || strlen($token) !== 64 || !$this->recuperaciones->vigente($token, $ahora)) {
            View::render('auth/restablecer', ['token' => '', 'error' => 'El enlace no es válido o ha caducado.']);
            return;
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            View::render('auth/restablecer', ['token' => $token, 'error' => '']);
            return;
        }

        $password = $_POST['password'] ?? '';
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72 || trim($password) === '') {
            View::render('auth/restablecer', [
                'token' => $token,
                'error' => 'La contraseña debe tener entre 12 y 72 caracteres y no puede ser solo espacios.',
            ]);
            return;
        }

        $email = $this->recuperaciones->consumir($token, $password, $ahora);
        if ($email === null) {
            View::render('auth/restablecer', ['token' => '', 'error' => 'El enlace no es válido o ha caducado.']);
            return;
        }
        (new IntentoLogin())->limpiar($email);
        header('Location: index.php?action=login&restablecida=1');
        exit;
    }
}
