<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/Webhook.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['error' => 'method_not_allowed']);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (!is_string($contentType) || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
    $respond(415, ['error' => 'content_type_must_be_application_json']);
}

$contentLength = $_SERVER['CONTENT_LENGTH'] ?? '';
if (is_string($contentLength) && ctype_digit($contentLength) && (int)$contentLength > 65536) {
    $respond(413, ['error' => 'payload_too_large']);
}

$body = file_get_contents('php://input', false, null, 0, 65537);
if (!is_string($body) || strlen($body) > 65536) {
    $respond(413, ['error' => 'payload_too_large']);
}

$secret = getenv('FCT_WEBHOOK_INBOUND_SECRET');
if (!is_string($secret) || strlen($secret) < 32) {
    error_log('Webhook de entrada deshabilitado: configura FCT_WEBHOOK_INBOUND_SECRET con al menos 32 caracteres.');
    $respond(503, ['error' => 'webhook_not_configured']);
}

$eventId = $_SERVER['HTTP_X_FCT_EVENT_ID'] ?? null;
$timestamp = $_SERVER['HTTP_X_FCT_TIMESTAMP'] ?? null;
$signature = $_SERVER['HTTP_X_FCT_SIGNATURE'] ?? null;
try {
    $db = getPDOConnection();
    $inserted = Webhook::receive(
        $db,
        $body,
        is_string($eventId) ? $eventId : null,
        is_string($timestamp) ? $timestamp : null,
        is_string($signature) ? $signature : null,
        $secret
    );
    $decoded = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
    $responseStatus = $inserted ? 202 : 200;
    $responseBody = [
        'received' => true,
        'duplicate' => !$inserted,
        'event_id' => $decoded->event_id,
    ];
    Webhook::recordInboundResponse(
        $db,
        $decoded->event_id,
        $responseStatus,
        json_encode($responseBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    );
    $respond($responseStatus, $responseBody);
} catch (WebhookRequestException $exception) {
    $respond($exception->httpStatus, ['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('No se pudo procesar webhook entrante: ' . $exception->getMessage());
    $respond(500, ['error' => 'webhook_processing_failed']);
}
