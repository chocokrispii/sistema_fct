<?php

require_once __DIR__ . '/../Models/SolicitudReenvioActivacion.php';

final class SolicitudReenvioActivacionController
{
    private SolicitudReenvioActivacion $solicitudes;

    public function __construct(?PDO $db = null)
    {
        $this->solicitudes = new SolicitudReenvioActivacion($db ?? getPDOConnection());
    }

    public function solicitar(): void
    {
        $email = $_POST['email'] ?? '';
        $identifier = $_POST['identificador_fiscal'] ?? '';
        if (!is_string($email) || !is_string($identifier)) {
            http_response_code(422);
            View::render('auth/activation', [
                'error' => 'Los datos de la solicitud no tienen un formato válido.',
                'token' => '',
                'activated' => false,
                'showResendRequest' => true,
                'requestSent' => false,
            ]);
            return;
        }

        try {
            $this->solicitudes->solicitar($email, $identifier);
        } catch (Throwable $exception) {
            error_log('Error al solicitar reenvío de activación: ' . $exception->getMessage());
            http_response_code(500);
            View::render('auth/activation', [
                'error' => 'No se pudo registrar la solicitud. Inténtalo más tarde o contacta con el Centro.',
                'token' => '',
                'activated' => false,
                'showResendRequest' => true,
                'requestSent' => false,
            ]);
            return;
        }

        header('Location: index.php?action=activar-cuenta&solicitud=1');
        exit;
    }

    public function listar(): void
    {
        View::render('admin/solicitudes-activacion', [
            'solicitudes' => $this->solicitudes->pendientes(),
            'error' => is_string($_GET['error'] ?? null) ? $_GET['error'] : '',
            'reenviada' => isset($_GET['reenviada']),
        ]);
    }

    public function aprobar(): void
    {
        $requestId = filter_var($_POST['solicitud_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($requestId === false) {
            $this->redirectWithError('Selecciona una solicitud válida.');
        }

        try {
            $this->solicitudes->aprobar(
                (int)$requestId,
                (int)$_SESSION['usuario_id'],
                getenv('FCT_APP_URL') ?: ''
            );
        } catch (DomainException $exception) {
            $this->redirectWithError($exception->getMessage());
        } catch (Throwable $exception) {
            error_log('Error al aprobar reenvío de activación: ' . $exception->getMessage());
            $this->redirectWithError('No se pudo reenviar el enlace. La solicitud sigue pendiente.');
        }

        header('Location: index.php?action=solicitudes-activacion&reenviada=1');
        exit;
    }

    private function redirectWithError(string $message): never
    {
        header('Location: index.php?action=solicitudes-activacion&error=' . rawurlencode($message));
        exit;
    }
}
