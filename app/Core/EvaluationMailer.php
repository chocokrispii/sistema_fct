<?php

declare(strict_types=1);
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

final class EvaluationMailer
{
    public function send(array $recipients, string $company, string $student, string $dictamen): void
    {
        $host = getenv('FCT_MAIL_HOST') ?: '';
        $port = filter_var(getenv('FCT_MAIL_PORT') ?: '587', FILTER_VALIDATE_INT);
        $transport = strtolower(getenv('FCT_MAIL_TRANSPORT') ?: 'smtp');
        $from = getenv('FCT_MAIL_FROM') ?: '';
        $fromName = getenv('FCT_MAIL_FROM_NAME') ?: 'Sistema FCT';
        $encryption = strtolower(getenv('FCT_MAIL_ENCRYPTION') ?: 'tls');
        $validMailpit = $transport === 'mailpit' && (getenv('APP_ENV') ?: '') === 'development'
            && $host === 'mailpit' && $port === 1025 && $encryption === 'none';
        $recipients = array_values(array_filter($recipients, static fn($email) => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)));
        if ($recipients === [] || !filter_var($from, FILTER_VALIDATE_EMAIL) || $port === false
            || !in_array($transport, ['smtp', 'mailpit'], true)
            || ($transport === 'smtp' && ($host === '' || getenv('FCT_MAIL_USERNAME') === false || getenv('FCT_MAIL_PASSWORD') === false || !in_array($encryption, ['tls', 'ssl'], true)))
            || ($transport === 'mailpit' && !$validMailpit)) {
            throw new RuntimeException('La configuración del correo de evaluación no es válida.');
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host = $host; $mail->Port = $port; $mail->SMTPAuth = $transport === 'smtp';
            if ($mail->SMTPAuth) { $mail->Username = getenv('FCT_MAIL_USERNAME') ?: ''; $mail->Password = getenv('FCT_MAIL_PASSWORD') ?: ''; $mail->SMTPSecure = $encryption === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS; }
            $mail->CharSet = PHPMailer::CHARSET_UTF8; $mail->setFrom($from, $fromName);
            foreach ($recipients as $recipient) { $mail->addAddress($recipient); }
            $mail->isHTML(true); $mail->Subject = 'Nueva evaluación final de prácticas FCT';
            $safeCompany = htmlspecialchars($company, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeStudent = htmlspecialchars($student, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeDictamen = htmlspecialchars($dictamen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $mail->Body = "<p>La empresa <strong>{$safeCompany}</strong> ha entregado la evaluación final de <strong>{$safeStudent}</strong>.</p><p>Dictamen: <strong>{$safeDictamen}</strong>.</p><p>Consulta el expediente desde el panel de seguimiento.</p>";
            $mail->AltBody = "La empresa $company ha entregado la evaluación final de $student. Dictamen: $dictamen.";
            $mail->send();
        } catch (MailException $exception) {
            throw new RuntimeException('No se pudo informar al coordinador sobre la evaluación.', 0, $exception);
        }
    }
}
