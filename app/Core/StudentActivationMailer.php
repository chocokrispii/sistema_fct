<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class StudentActivationMailer
{
    public function send(string $recipient, string $name, string $activationUrl): void
    {
        $host = getenv('FCT_MAIL_HOST') ?: '';
        $portRaw = getenv('FCT_MAIL_PORT') ?: '587';
        $transport = strtolower(getenv('FCT_MAIL_TRANSPORT') ?: 'smtp');
        $username = getenv('FCT_MAIL_USERNAME') ?: '';
        $password = getenv('FCT_MAIL_PASSWORD') ?: '';
        $from = getenv('FCT_MAIL_FROM') ?: '';
        $fromName = getenv('FCT_MAIL_FROM_NAME') ?: 'Sistema FCT';
        $encryption = strtolower(getenv('FCT_MAIL_ENCRYPTION') ?: 'tls');
        $port = filter_var($portRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        $urlParts = parse_url($activationUrl);
        $isHttpsUrl = is_array($urlParts)
            && strtolower((string)($urlParts['scheme'] ?? '')) === 'https';
        $isLocalDevelopmentUrl = is_array($urlParts)
            && (getenv('APP_ENV') ?: '') === 'development'
            && strtolower((string)($urlParts['scheme'] ?? '')) === 'http'
            && in_array(strtolower((string)($urlParts['host'] ?? '')), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        $isLocalMailpit = $transport === 'mailpit'
            && (getenv('APP_ENV') ?: '') === 'development'
            && $host === 'mailpit'
            && $port === 1025
            && $encryption === 'none';

        if (
            !in_array($transport, ['smtp', 'mailpit'], true)
            || ($transport === 'smtp' && ($host === '' || $username === '' || $password === '' || !in_array($encryption, ['tls', 'ssl'], true)))
            || ($transport === 'mailpit' && !$isLocalMailpit)
            || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || $port === false
            || filter_var($activationUrl, FILTER_VALIDATE_URL) === false
            || (!$isHttpsUrl && !$isLocalDevelopmentUrl)
            || preg_match('/[\r\n]/', $fromName) === 1
        ) {
            throw new RuntimeException('La configuración SMTP del correo de activación está incompleta o no es válida.');
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->SMTPAuth = $transport === 'smtp';
            if ($transport === 'smtp') {
                $mail->Username = $username;
                $mail->Password = $password;
                $mail->SMTPSecure = $encryption === 'ssl'
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
            }
            $mail->Port = $port;
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($from, $fromName);
            $mail->addAddress($recipient, $name);
            $mail->isHTML(true);
            $mail->Subject = 'Activa tu cuenta del Sistema FCT';
            $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeUrl = htmlspecialchars($activationUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mail->Body = '<p>Hola ' . $safeName . ':</p>'
                . '<p>El centro ha creado tu cuenta del Sistema FCT. Para elegir tu contraseña y activar el acceso, utiliza este enlace:</p>'
                . '<p><a href="' . $safeUrl . '">Activar mi cuenta</a></p>'
                . '<p>Por seguridad, el enlace caduca en 24 horas y solo se puede utilizar una vez. Si no esperabas este mensaje, contacta con tu centro.</p>';
            $mail->AltBody = "Hola $name:\n\nEl centro ha creado tu cuenta del Sistema FCT. "
                . "Para elegir tu contraseña y activar el acceso, visita:\n$activationUrl\n\n"
                . 'El enlace caduca en 24 horas y solo se puede utilizar una vez.';
            $mail->send();
        } catch (MailException $exception) {
            throw new RuntimeException('No se pudo enviar el correo de activación.', 0, $exception);
        }
    }
}
