<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

final class PasswordResetMailer
{
    public function send(string $recipient, string $name, string $resetUrl): void
    {
        $host = getenv('FCT_MAIL_HOST') ?: '';
        $port = filter_var(getenv('FCT_MAIL_PORT') ?: '587', FILTER_VALIDATE_INT);
        $transport = strtolower(getenv('FCT_MAIL_TRANSPORT') ?: 'smtp');
        $from = getenv('FCT_MAIL_FROM') ?: '';
        $fromName = getenv('FCT_MAIL_FROM_NAME') ?: 'Sistema FCT';
        $encryption = strtolower(getenv('FCT_MAIL_ENCRYPTION') ?: 'tls');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($resetUrl, FILTER_VALIDATE_URL) || $port === false) {
            throw new RuntimeException('La configuración del correo de recuperación no es válida.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->SMTPAuth = $transport === 'smtp';
        if ($mail->SMTPAuth) {
            $mail->Username = getenv('FCT_MAIL_USERNAME') ?: '';
            $mail->Password = getenv('FCT_MAIL_PASSWORD') ?: '';
            $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }
        $mail->Port = $port;
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom($from, $fromName);
        $mail->addAddress($recipient, $name);
        $mail->isHTML(true);
        $mail->Subject = 'Regeneración de contraseña del Sistema FCT';
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($resetUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $mail->Body = '<p>Hola ' . $safeName . ':</p><p>Un administrador ha aprobado tu solicitud de regeneración.</p>'
            . '<p><a href="' . $safeUrl . '">Regenerar contraseña</a></p><p>El enlace caduca en 24 horas y solo puede utilizarse una vez.</p>';
        $mail->AltBody = "Hola $name:\n\nRegenera tu contraseña aquí (válido 24 horas):\n$resetUrl";
        $mail->send();
    }
}
