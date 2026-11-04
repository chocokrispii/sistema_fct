<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/WebhookCrypto.php';
require_once __DIR__ . '/../app/Core/WebhookEndpoint.php';
require_once __DIR__ . '/../app/Services/WebhookPublisher.php';
require_once __DIR__ . '/../app/Services/WebhookDeliveryWorker.php';

function assertWebhook(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (!is_string($testDatabase)
    || preg_match('/\Afct_test_[a-zA-Z0-9_]+\z/', $testDatabase) !== 1
    || getenv('FCT_DB_NAME') !== $testDatabase) {
    fwrite(STDERR, "Define FCT_DB_NAME y FCT_TEST_DB_NAME con la misma base fct_test_*.\n");
    exit(1);
}

putenv('FCT_WEBHOOK_ENCRYPTION_KEY=' . str_repeat('a5', 32));
$db = getPDOConnection();
$fixture = bin2hex(random_bytes(6));
$secret = bin2hex(random_bytes(32));
$subscriptionId = null;
$deliveryId = null;

try {
    assertWebhook(WebhookCrypto::decrypt(WebhookCrypto::encrypt($secret)) === $secret, 'El cifrado/descifrado del secreto no coincide.');
    try {
        WebhookEndpoint::resolvePublicAddress('http://127.0.0.1/hook');
        throw new RuntimeException('Se aceptó un endpoint HTTP local.');
    } catch (InvalidArgumentException) {
    }
    try {
        WebhookEndpoint::resolvePublicAddress('https://127.0.0.1/hook');
        throw new RuntimeException('Se aceptó un endpoint HTTPS privado.');
    } catch (InvalidArgumentException) {
    }
    try {
        WebhookEndpoint::resolvePublicAddress('https://example.com./hook');
        throw new RuntimeException('Se aceptó un endpoint con punto final en el host.');
    } catch (InvalidArgumentException) {
    }

    $insert = $db->prepare(
        'INSERT INTO webhook_subscriptions (name, endpoint_url, event_types, secret_ciphertext)
         VALUES (:name, :endpoint, :events, :secret)'
    );
    $insert->execute([
        'name' => 'webhook-test-' . $fixture,
        'endpoint' => 'https://receiver.example.test/webhook',
        'events' => 'alumno.alta',
        'secret' => WebhookCrypto::encrypt($secret),
    ]);
    $subscriptionId = (int)$db->lastInsertId();

    $db->beginTransaction();
    $rolledBackEvent = WebhookPublisher::publish($db, 'alumno.alta', ['alumno_id' => 999]);
    $db->rollBack();
    $checkRollback = $db->prepare('SELECT COUNT(*) FROM webhook_deliveries WHERE subscription_id = :id AND event_id = :event');
    $checkRollback->execute(['id' => $subscriptionId, 'event' => $rolledBackEvent]);
    assertWebhook((int)$checkRollback->fetchColumn() === 0, 'Un evento webhook sobrevivió al rollback de negocio.');

    $db->beginTransaction();
    $eventId = WebhookPublisher::publish($db, 'alumno.alta', ['alumno_id' => 123]);
    $db->commit();
    $delivery = $db->prepare('SELECT id, payload FROM webhook_deliveries WHERE subscription_id = :id AND event_id = :event');
    $delivery->execute(['id' => $subscriptionId, 'event' => $eventId]);
    $row = $delivery->fetch(PDO::FETCH_ASSOC);
    assertWebhook(is_array($row), 'El publisher no creó una entrega en outbox.');
    $deliveryId = (int)$row['id'];
    $payload = json_decode((string)$row['payload'], true, 512, JSON_THROW_ON_ERROR);
    assertWebhook(
        $payload['event'] === 'alumno.alta'
            && $payload['event_id'] === $eventId
            && isset($payload['timestamp'], $payload['data']['alumno_id'])
            && $payload['data']['alumno_id'] === 123,
        'El payload no cumple el contrato del evento.'
    );

    $attempts = 0;
    $headersSeen = [];
    $failingWorker = new WebhookDeliveryWorker($db, static function (string $url, string $body, array $headers) use (&$attempts, &$headersSeen): array {
        $attempts++;
        $headersSeen = $headers;
        return ['status' => 503, 'error' => 'endpoint unavailable'];
    });
    for ($attempt = 0; $attempt < 4; $attempt++) {
        assertWebhook($failingWorker->runOne(), 'El worker no reclamó una entrega disponible.');
        if ($attempt < 3) {
            $retryDelay = $db->prepare(
                'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), available_at)
                 FROM webhook_deliveries WHERE id = :id'
            );
            $retryDelay->execute(['id' => $deliveryId]);
            $delaySeconds = (int)$retryDelay->fetchColumn();
            $expectedDelay = [60, 300, 900][$attempt];
            assertWebhook(
                $delaySeconds <= $expectedDelay && $delaySeconds >= $expectedDelay - 10,
                'El reintento no respetó el intervalo exponencial esperado.'
            );
            $makeDue = $db->prepare("UPDATE webhook_deliveries SET available_at = UTC_TIMESTAMP() WHERE id = :id AND state = 'PENDING'");
            $makeDue->execute(['id' => $deliveryId]);
        }
    }
    assertWebhook($attempts === 4, 'El worker no ejecutó el máximo de 4 intentos totales.');
    assertWebhook(in_array('Content-Type: application/json', $headersSeen, true), 'La entrega no indicó Content-Type JSON.');
    $signature = '';
    foreach ($headersSeen as $header) {
        if (str_starts_with($header, 'X-FCT-Signature: ')) {
            $signature = substr($header, strlen('X-FCT-Signature: '));
        }
    }
    assertWebhook(
        hash_equals('sha256=' . hash_hmac('sha256', $payload['timestamp'] . '.' . $row['payload'], $secret), $signature),
        'La firma HMAC del webhook no valida.'
    );
    $state = $db->prepare('SELECT d.state, d.attempts, s.state AS subscription_state FROM webhook_deliveries d JOIN webhook_subscriptions s ON s.id = d.subscription_id WHERE d.id = :id');
    $state->execute(['id' => $deliveryId]);
    $result = $state->fetch(PDO::FETCH_ASSOC);
    assertWebhook(
        $result['state'] === 'FAILED'
            && (int)$result['attempts'] === 4
            && $result['subscription_state'] === 'DEGRADED',
        'Agotar intentos no dejó la entrega fallida y la suscripción degradada.'
    );

    $db->exec("UPDATE webhook_subscriptions SET state = 'ACTIVE' WHERE id = " . $subscriptionId);
    $db->exec("UPDATE webhook_deliveries SET state = 'PENDING', attempts = 0, available_at = UTC_TIMESTAMP() WHERE id = " . $deliveryId);
    $successWorker = new WebhookDeliveryWorker($db, static fn(): array => ['status' => 204]);
    assertWebhook($successWorker->runOne(), 'El worker no procesó la entrega de éxito.');
    $delivered = $db->prepare('SELECT state FROM webhook_deliveries WHERE id = :id');
    $delivered->execute(['id' => $deliveryId]);
    assertWebhook($delivered->fetchColumn() === 'DELIVERED', 'La respuesta 2xx no marcó la entrega como completada.');
} finally {
    if ($subscriptionId !== null) {
        $delete = $db->prepare('DELETE FROM webhook_subscriptions WHERE id = :id');
        $delete->execute(['id' => $subscriptionId]);
    }
}

echo "Webhook tests passed.\n";
