<?php

declare(strict_types=1);

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

        $resetUrl = rtrim($baseUrl, '/') . '/restablecer-password?token=' . rawurlencode($token);
        $subject = 'Restablecimiento de contrasena - Sistema FCT';
        $body = "El Administrador aprobó tu solicitud de restablecimiento.\n\n"
            . "Abre este enlace antes de 24 horas para elegir una nueva contraseña:\n"
            . $resetUrl . "\n\n"
            . "Si no solicitaste este cambio, contacta con el centro educativo.";

        if (getenv('APP_ENV') === 'test') {
            self::captureForTest($recipient, $subject, $body);
            return;
        }

        $sender = getenv('FCT_MAIL_FROM');
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('FCT_MAIL_FROM debe configurarse para enviar correos de restablecimiento.');
        }
        $headers = [
            'From: ' . $sender,
            'Content-Type: text/plain; charset=UTF-8',
        ];
        if (!mail($recipient, $subject, $body, implode("\r\n", $headers))) {
            throw new RuntimeException('El servicio de correo rechazó el mensaje de restablecimiento.');
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
