<?php

declare(strict_types=1);

require_once __DIR__ . '/../Models/SolicitudResetPassword.php';

final class PasswordResetMailer
{
    public static function send(string $recipient, string $token): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El destinatario del restablecimiento no es válido.');
        }

        $baseUrl = getenv('FCT_PUBLIC_BASE_URL');
        if (!is_string($baseUrl) || !self::isValidBaseUrl($baseUrl)) {
            throw new RuntimeException('FCT_PUBLIC_BASE_URL debe configurarse para enviar enlaces de restablecimiento.');
        }
        $baseUrlParts = parse_url($baseUrl);
        if (getenv('APP_ENV') === 'production' && ($baseUrlParts['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('Los enlaces de restablecimiento de producción requieren HTTPS.');
        }

        $resetUrl = rtrim($baseUrl, '/') . '/restablecer-password?token=' . rawurlencode($token);
        $subject = 'Restablecimiento de contrasena - Sistema FCT';
        $body = "El Administrador aprobó tu solicitud de restablecimiento.\n\n"
            . "Abre este enlace antes de " . SolicitudResetPassword::VALIDEZ_HORAS
            . " horas para elegir una nueva contraseña:\n"
            . $resetUrl . "\n\n"
            . "Si no solicitaste este cambio, contacta con el centro educativo.";

        if (getenv('APP_ENV') === 'test') {
            self::captureForTest($recipient, $subject, $body);
            return;
        }

        self::sendSmtp($recipient, $subject, $body);
    }

    private static function sendSmtp(string $recipient, string $subject, string $body): void
    {
        $host = getenv('FCT_MAIL_HOST');
        $port = filter_var(getenv('FCT_MAIL_PORT'), FILTER_VALIDATE_INT);
        $sender = getenv('FCT_MAIL_FROM');
        $username = getenv('FCT_MAIL_USERNAME');
        $password = getenv('FCT_MAIL_PASSWORD');
        $encryption = strtolower((string)(getenv('FCT_MAIL_ENCRYPTION') ?: 'starttls'));
        if (
            !is_string($host) || $host === '' || preg_match('/\A[a-zA-Z0-9.-]+\z/', $host) !== 1
            || $port === false || $port < 1 || $port > 65535
            || !is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new RuntimeException('La configuración SMTP de restablecimiento está incompleta.');
        }
        if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
            throw new RuntimeException('FCT_MAIL_ENCRYPTION debe ser none, starttls o ssl.');
        }
        if (getenv('APP_ENV') === 'production' && (
            $encryption === 'none'
            || !is_string($username) || $username === ''
            || !is_string($password) || $password === ''
        )) {
            throw new RuntimeException('SMTP de producción requiere TLS y autenticación.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
            ],
        ]);
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $socket = @stream_socket_client(
            $scheme . $host . ':' . $port,
            $errorCode,
            $errorMessage,
            10,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($socket)) {
            throw new RuntimeException('No se pudo conectar con el servicio SMTP.');
        }
        stream_set_timeout($socket, 10);

        try {
            self::expectReply($socket, [220]);
            self::smtpCommand($socket, 'EHLO sistema-fct', [250]);
            if ($encryption === 'starttls') {
                self::smtpCommand($socket, 'STARTTLS', [220]);
                if (stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new RuntimeException('No se pudo establecer TLS con el servicio SMTP.');
                }
                self::smtpCommand($socket, 'EHLO sistema-fct', [250]);
            }
            if (is_string($username) && $username !== '') {
                if ($encryption === 'none' && getenv('APP_ENV') === 'production') {
                    throw new RuntimeException('No se enviarán credenciales SMTP sin TLS.');
                }
                self::smtpCommand($socket, 'AUTH LOGIN', [334]);
                self::smtpCommand($socket, base64_encode($username), [334]);
                self::smtpCommand($socket, base64_encode((string)$password), [235]);
            }
            self::smtpCommand($socket, 'MAIL FROM:<' . $sender . '>', [250]);
            self::smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            self::smtpCommand($socket, 'DATA', [354]);
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $message = "From: {$sender}\r\n"
                . "To: {$recipient}\r\n"
                . "Subject: {$encodedSubject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($body), 76, "\r\n");
            if (fwrite($socket, $message . ".\r\n") === false) {
                throw new RuntimeException('No se pudo enviar el mensaje SMTP.');
            }
            self::expectReply($socket, [250]);
            fwrite($socket, "QUIT\r\n");
        } finally {
            fclose($socket);
        }
    }

    private static function smtpCommand($socket, string $command, array $allowedCodes): void
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('El servicio SMTP no aceptó la operación.');
        }
        self::expectReply($socket, $allowedCodes);
    }

    private static function expectReply($socket, array $allowedCodes): void
    {
        $code = null;
        for ($lineCount = 0; $lineCount < 20; $lineCount++) {
            $line = fgets($socket, 2048);
            if (!is_string($line) || preg_match('/\A(\d{3})([ -])/', $line, $matches) !== 1) {
                throw new RuntimeException('El servicio SMTP devolvió una respuesta inválida.');
            }
            $code = (int)$matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        }
        if (!in_array($code, $allowedCodes, true)) {
            throw new RuntimeException('El servicio SMTP no pudo completar el envío.');
        }
    }

    private static function isValidBaseUrl(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);
        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['https', 'http'], true)
            && isset($parts['host'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment']);
    }

    private static function captureForTest(string $recipient, string $subject, string $body): void
    {
        $mailbox = getenv('FCT_TEST_MAILBOX');
        if (!is_string($mailbox) || $mailbox === '') {
            throw new RuntimeException('FCT_TEST_MAILBOX debe configurarse en APP_ENV=test.');
        }
        $encoded = json_encode(
            ['to' => $recipient, 'subject' => $subject, 'body' => $body],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ) . "\n";
        if (file_put_contents($mailbox, $encoded, FILE_APPEND | LOCK_EX) === false || !chmod($mailbox, 0600)) {
            throw new RuntimeException('No se pudo registrar el correo de restablecimiento de prueba.');
        }
    }
}
