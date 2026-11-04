<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/ActivationUrl.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class ConvenioApprovalMailer
{
    public function send(string $recipient, string $companyName, string $agreementNumber, string $downloadUrl): void
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
        $appUrl = rtrim(getenv('FCT_APP_URL') ?: '', '/');
        $localMailpit = $transport === 'mailpit'
            && (getenv('APP_ENV') ?: '') === 'development'
            && $host === 'mailpit'
            && $port === 1025
            && $encryption === 'none';

        if (
            !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || !filter_var($from, FILTER_VALIDATE_EMAIL)
            || !in_array($transport, ['smtp', 'mailpit'], true)
            || ($transport === 'smtp' && ($host === '' || $username === '' || $password === '' || !in_array($encryption, ['tls', 'ssl'], true)))
            || ($transport === 'mailpit' && !$localMailpit)
            || $port === false
            || !ActivationUrl::isAllowedBaseUrl($appUrl)
            || preg_match('/[\r\n]/', $fromName) === 1
        ) {
            throw new RuntimeException('La configuración SMTP o la URL pública no es válida para notificar la aprobación.');
        }

        $mail = new PHPMailer(true);
        $displayName = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $companyName);
        if (!is_string($displayName) || trim($displayName) === '') {
            throw new RuntimeException('El nombre de la empresa no es válido para la notificación.');
        }
        $displayName = trim($displayName);
        $safeName = htmlspecialchars($displayName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeNumber = htmlspecialchars($agreementNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUrl = htmlspecialchars($downloadUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
            $mail->addAddress($recipient, $displayName);
            $mail->isHTML(true);
            $mail->Subject = 'Convenio aprobado en Sistema FCT';
            $mail->Body = '<p>Hola ' . $safeName . ':</p>'
                . '<p>El convenio ' . $safeNumber . ' ha sido aprobado mediante un sello electrónico simple.</p>'
                . '<p><a href="' . $safeUrl . '">Acceder al convenio aprobado</a></p>'
                . '<p>El enlace requiere iniciar sesión con la cuenta de empresa asociada. El sello no es una firma digital cualificada.</p>';
            $mail->AltBody = "Hola $displayName:\n\n"
                . "El convenio $agreementNumber ha sido aprobado mediante un sello electrónico simple.\n"
                . "Accede al documento iniciando sesión con la cuenta de empresa asociada:\n$downloadUrl\n\n"
                . 'El sello no es una firma digital cualificada.';
            $mail->send();
        } catch (MailException $exception) {
            throw new RuntimeException('No se pudo enviar el aviso de aprobación a la empresa.', 0, $exception);
        }
    }
}
