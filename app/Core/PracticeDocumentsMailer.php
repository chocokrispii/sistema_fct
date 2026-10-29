<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class PracticeDocumentsMailer
{
    public function send(string $recipient, string $name, string $portalUrl): void
    {
        $host = getenv('FCT_MAIL_HOST') ?: '';
        $port = filter_var(getenv('FCT_MAIL_PORT') ?: '587', FILTER_VALIDATE_INT);
        $transport = strtolower(getenv('FCT_MAIL_TRANSPORT') ?: 'smtp');
        $from = getenv('FCT_MAIL_FROM') ?: '';
        $fromName = getenv('FCT_MAIL_FROM_NAME') ?: 'Sistema FCT';
        $encryption = strtolower(getenv('FCT_MAIL_ENCRYPTION') ?: 'tls');
        $validMailpit = $transport === 'mailpit' && (getenv('APP_ENV') ?: '') === 'development'
            && $host === 'mailpit' && $port === 1025 && $encryption === 'none';
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($portalUrl, FILTER_VALIDATE_URL) || $port === false
            || !in_array($transport, ['smtp', 'mailpit'], true)
            || ($transport === 'smtp' && ($host === '' || getenv('FCT_MAIL_USERNAME') === false || getenv('FCT_MAIL_PASSWORD') === false || !in_array($encryption, ['tls', 'ssl'], true)))
            || ($transport === 'mailpit' && !$validMailpit)) {
            throw new RuntimeException('La configuración del correo de documentación no es válida.');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host = $host; $mail->Port = $port; $mail->SMTPAuth = $transport === 'smtp';
            if ($mail->SMTPAuth) { $mail->Username = getenv('FCT_MAIL_USERNAME') ?: ''; $mail->Password = getenv('FCT_MAIL_PASSWORD') ?: ''; $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS; }
            $mail->CharSet = PHPMailer::CHARSET_UTF8; $mail->setFrom($from, $fromName); $mail->addAddress($recipient, $name);
            $mail->isHTML(true); $mail->Subject = 'Documentación de inicio de tus prácticas FCT';
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = htmlspecialchars($portalUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mail->Body = '<p>Hola ' . $safeName . ':</p><p>Tu asignación de prácticas ha sido confirmada. Ya tienes disponible la guía de inicio y el plan formativo en tu área privada.</p><p><a href="' . $safeUrl . '">Abrir documentación de inicio</a></p>';
            $mail->AltBody = "Hola $name:\n\nTu documentación de inicio está disponible en:\n$portalUrl";
            $mail->send();
        } catch (MailException $exception) { throw new RuntimeException('No se pudo enviar la notificación de documentación.', 0, $exception); }
    }
}
