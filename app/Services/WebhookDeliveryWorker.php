<?php

declare(strict_types=1);

final class WebhookDeliveryWorker
{
    private const MAX_ATTEMPTS = 4;
    private const RETRY_DELAYS_SECONDS = [60, 300, 900];

    public function __construct(private PDO $db, private ?Closure $transport = null)
    {
    }

    public function runOne(): bool
    {
        $delivery = $this->claim();
        if ($delivery === null) {
            return false;
        }

        try {
            $secret = WebhookCrypto::decrypt((string)$delivery['secret_ciphertext']);
            if ($this->transport !== null) {
                $result = ($this->transport)(
                    (string)$delivery['endpoint_url'],
                    (string)$delivery['payload'],
                    $this->headers($delivery, $secret)
                );
            } else {
                $result = $this->send((string)$delivery['endpoint_url'], (string)$delivery['payload'], $this->headers($delivery, $secret));
            }
            if (!is_array($result) || !is_int($result['status'] ?? null)) {
                throw new RuntimeException('El transporte webhook devolvió un resultado no válido.');
            }
            if ($result['status'] >= 200 && $result['status'] < 300) {
                $this->markDelivered($delivery, $result['status']);
                return true;
            }
            $status = $result['status'];
            $error = is_string($result['error'] ?? null) ? $result['error'] : 'El endpoint respondió con HTTP ' . $status . '.';
        } catch (Throwable $exception) {
            $status = null;
            $error = $exception->getMessage();
        }

        $this->markFailure($delivery, $status, $error);
        return true;
    }

    private function claim(): ?array
    {
        $this->db->beginTransaction();
        try {
            $expireFinalAttempt = $this->db->prepare(
                "UPDATE webhook_deliveries d
                 INNER JOIN webhook_subscriptions s ON s.id = d.subscription_id
                 SET d.state = 'FAILED', d.locked_at = NULL,
                     d.last_error = 'El worker perdió la entrega durante el último intento.',
                     s.state = 'DEGRADED', s.failure_count = s.failure_count + 1,
                     s.last_failure_at = UTC_TIMESTAMP()
                 WHERE d.state = 'PROCESSING' AND d.attempts >= :max_attempts
                   AND d.locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)
                   AND s.state = 'ACTIVE'"
            );
            $expireFinalAttempt->execute(['max_attempts' => self::MAX_ATTEMPTS]);
            $stmt = $this->db->query(
                "SELECT d.id, d.subscription_id, d.event_id, d.event_type, d.payload, d.attempts,
                        s.endpoint_url, s.secret_ciphertext
                 FROM webhook_deliveries d
                 INNER JOIN webhook_subscriptions s ON s.id = d.subscription_id
                 WHERE s.state = 'ACTIVE'
                   AND ((d.state = 'PENDING' AND d.available_at <= UTC_TIMESTAMP())
                     OR (d.state = 'PROCESSING' AND d.locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)))
                 ORDER BY d.available_at, d.id
                 LIMIT 1
                 FOR UPDATE"
            );
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($delivery)) {
                $this->db->commit();
                return null;
            }
            $update = $this->db->prepare(
                "UPDATE webhook_deliveries
                 SET state = 'PROCESSING', attempts = attempts + 1, locked_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $update->execute(['id' => $delivery['id']]);
            $this->db->commit();
            $delivery['attempts'] = (int)$delivery['attempts'] + 1;
            return $delivery;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function markDelivered(array $delivery, int $status): void
    {
        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare(
                "UPDATE webhook_deliveries
                 SET state = 'DELIVERED', http_status = :status, last_error = NULL,
                     locked_at = NULL, delivered_at = UTC_TIMESTAMP()
                 WHERE id = :id"
            );
            $update->execute(['status' => $status, 'id' => $delivery['id']]);
            $reset = $this->db->prepare(
                "UPDATE webhook_subscriptions SET failure_count = 0, last_failure_at = NULL
                 WHERE id = :id AND state = 'ACTIVE'"
            );
            $reset->execute(['id' => $delivery['subscription_id']]);
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function markFailure(array $delivery, ?int $status, string $error): void
    {
        $attempt = (int)$delivery['attempts'];
        $error = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $error) ?? 'Error de transporte webhook.', 0, 1000);
        $this->db->beginTransaction();
        try {
            if ($attempt < self::MAX_ATTEMPTS) {
                $delay = self::RETRY_DELAYS_SECONDS[$attempt - 1];
                $update = $this->db->prepare(
                    "UPDATE webhook_deliveries
                     SET state = 'PENDING', available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$delay} SECOND),
                         locked_at = NULL, http_status = :status, last_error = :error
                     WHERE id = :id"
                );
                $update->execute([
                    'status' => $status,
                    'error' => $error,
                    'id' => $delivery['id'],
                ]);
            } else {
                $update = $this->db->prepare(
                    "UPDATE webhook_deliveries
                     SET state = 'FAILED', locked_at = NULL, http_status = :status, last_error = :error
                     WHERE id = :id"
                );
                $update->execute(['status' => $status, 'error' => $error, 'id' => $delivery['id']]);
                $degrade = $this->db->prepare(
                    "UPDATE webhook_subscriptions
                     SET state = 'DEGRADED', failure_count = failure_count + 1, last_failure_at = UTC_TIMESTAMP()
                     WHERE id = :id AND state = 'ACTIVE'"
                );
                $degrade->execute(['id' => $delivery['subscription_id']]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
        error_log(sprintf(
            'Webhook delivery %s failed (attempt %d/%d, HTTP %s): %s',
            $delivery['event_id'],
            $attempt,
            self::MAX_ATTEMPTS,
            $status === null ? 'n/a' : (string)$status,
            $error
        ));
    }

    private function headers(array $delivery, string $secret): array
    {
        $timestamp = (string)(json_decode((string)$delivery['payload'], true, 512, JSON_THROW_ON_ERROR)['timestamp'] ?? '');
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: Sistema-FCT-Webhooks/1.0',
            'X-FCT-Event: ' . $delivery['event_type'],
            'X-FCT-Delivery: ' . $delivery['event_id'],
            'X-FCT-Timestamp: ' . $timestamp,
            'X-FCT-Signature: sha256=' . hash_hmac('sha256', $timestamp . '.' . $delivery['payload'], $secret),
        ];
    }

    private function send(string $url, string $payload, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('La extensión PHP cURL es necesaria para entregar webhooks.');
        }
        [$host, $ip] = WebhookEndpoint::resolvePublicAddress($url);
        $port = 443;
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('No se pudo iniciar cURL para el webhook.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => strlen($chunk),
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $resolveIp],
            CURLOPT_PROXY => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($response === false) {
            return ['status' => 0, 'error' => $error !== '' ? $error : 'Falló la conexión HTTPS.'];
        }
        return ['status' => $status, 'error' => $status >= 200 && $status < 300 ? null : 'El endpoint respondió con HTTP ' . $status . '.'];
    }
}
