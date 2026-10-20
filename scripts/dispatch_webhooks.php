<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/Webhook.php';

function validateWebhookDestination(string $destination): void
{
    $parts = parse_url($destination);
    if (
        filter_var($destination, FILTER_VALIDATE_URL) === false
        || !is_array($parts)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['fragment'])
        || isset($parts['query'])
        || !isset($parts['scheme'], $parts['host'])
    ) {
        throw new RuntimeException('FCT_WEBHOOK_OUTBOUND_URL no es válida o incluye credenciales, query o fragmento.');
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower($parts['host']);
    $localHosts = ['localhost', '127.0.0.1', '::1'];
    if ($scheme !== 'https' && !($scheme === 'http' && in_array($host, $localHosts, true))) {
        throw new RuntimeException('El destino webhook debe usar HTTPS; HTTP solo se permite en localhost.');
    }
}

function resolveWebhookDestination(string $destination): array
{
    $parts = parse_url($destination);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        throw new RuntimeException('FCT_WEBHOOK_OUTBOUND_URL no es válida.');
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower(rtrim(trim($parts['host'], '[]'), '.'));
    $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $localHosts = ['localhost', '127.0.0.1', '::1'];
    if ($scheme === 'http' && in_array($host, $localHosts, true)) {
        return [];
    }

    $isPublicAddress = static function (string $address): bool {
        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            $octets = array_values(unpack('C4', $packed));
            if ($octets[0] === 100 && $octets[1] >= 64 && $octets[1] <= 127) {
                return false;
            }
        }

        return true;
    };

    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        if (!$isPublicAddress($host)) {
            throw new RuntimeException('El destino webhook debe resolver a direcciones IP públicas.');
        }
        return [];
    }

    if (preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*\z/i', $host) === 1) {
        throw new RuntimeException('El destino webhook no puede usar una dirección IP numérica no canónica.');
    }

    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    if (!is_array($records) || $records === []) {
        throw new RuntimeException('No se pudo resolver el destino webhook.');
    }

    $addresses = [];
    foreach ($records as $record) {
        $address = $record['ip'] ?? $record['ipv6'] ?? null;
        if (!is_string($address)) {
            continue;
        }
        if (!$isPublicAddress($address)) {
            throw new RuntimeException('El destino webhook debe resolver únicamente a direcciones IP públicas.');
        }
        $addresses[] = $address;
    }
    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        throw new RuntimeException('El destino webhook no tiene registros públicos A o AAAA.');
    }

    return $addresses;
}

function claimWebhook(PDO $db, ?string $eventId = null): ?array
{
    $db->beginTransaction();
    try {
        $eventFilter = $eventId === null ? '' : ' AND event_id = :event_id';
        $stmt = $db->prepare(
            'SELECT event_id, event_type, payload, attempts
             FROM webhook_outbox
             WHERE delivered_at IS NULL AND failed_at IS NULL
               AND next_attempt_at <= CURRENT_TIMESTAMP()
               AND (locked_until IS NULL OR locked_until < CURRENT_TIMESTAMP())
               ' . $eventFilter . '
             ORDER BY created_at, event_id
             LIMIT 1
             FOR UPDATE'
        );
        if ($eventId !== null) {
            $stmt->bindValue(':event_id', $eventId);
        }
        $stmt->execute();
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($event === false) {
            $db->commit();
            return null;
        }

        $token = Webhook::uuid();
        $lock = $db->prepare(
            'UPDATE webhook_outbox
             SET lock_token = :token, locked_until = DATE_ADD(CURRENT_TIMESTAMP(), INTERVAL 60 SECOND),
                 attempts = attempts + 1
             WHERE event_id = :event_id'
        );
        $lock->execute(['token' => $token, 'event_id' => $event['event_id']]);
        $db->commit();

        $event['attempts'] = (int)$event['attempts'] + 1;
        $event['lock_token'] = $token;
        return $event;
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }
}

function recordWebhookResult(
    PDO $db,
    array $event,
    ?int $status,
    ?string $responseBody,
    string $error,
    bool $delivered,
    bool $permanentFailure
): void {
    $attempts = (int)$event['attempts'];
    $permanentFailure = $permanentFailure || $attempts >= 12;
    $retrySeconds = min(86400, 30 * (2 ** min($attempts - 1, 11)));
    $statement = $db->prepare(
        'UPDATE webhook_outbox
         SET delivered_at = IF(:delivered = 1, CURRENT_TIMESTAMP(), delivered_at),
             failed_at = IF(:failed = 1, CURRENT_TIMESTAMP(), failed_at),
             next_attempt_at = IF(:retry = 1, TIMESTAMPADD(SECOND, :delay, CURRENT_TIMESTAMP()), next_attempt_at),
             http_status = :status,
             response_body = :response_body,
             last_error = :error,
             locked_until = NULL,
             lock_token = NULL
         WHERE event_id = :event_id AND lock_token = :token'
    );
    $statement->execute([
        'delivered' => $delivered ? 1 : 0,
        'failed' => $permanentFailure ? 1 : 0,
        'retry' => (!$delivered && !$permanentFailure) ? 1 : 0,
        'delay' => $retrySeconds,
        'status' => $status,
        'response_body' => $responseBody,
        'error' => $error === '' ? null : substr($error, 0, 500),
        'event_id' => $event['event_id'],
        'token' => $event['lock_token'],
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('La concesión del evento webhook expiró antes de guardar el resultado.');
    }
}

try {
    if (PHP_SAPI !== 'cli' || getenv('FCT_WEBHOOK_DISPATCH_ALLOW') !== '1') {
        throw new RuntimeException('Ejecuta este script por CLI y define FCT_WEBHOOK_DISPATCH_ALLOW=1.');
    }
    $destination = getenv('FCT_WEBHOOK_OUTBOUND_URL');
    if (!is_string($destination) || trim($destination) === '') {
        throw new RuntimeException('Configura FCT_WEBHOOK_OUTBOUND_URL antes de enviar eventos.');
    }
    validateWebhookDestination($destination);
    $resolvedAddresses = resolveWebhookDestination($destination);
    $secret = Webhook::secretFromEnvironment('FCT_WEBHOOK_OUTBOUND_SECRET');
    if (!extension_loaded('curl')) {
        throw new RuntimeException('La extensión PHP cURL es necesaria para enviar webhooks.');
    }

    $limitRaw = getenv('FCT_WEBHOOK_DISPATCH_LIMIT');
    $limit = $limitRaw === false || $limitRaw === '' ? 25 : filter_var(
        $limitRaw,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 100]]
    );
    if ($limit === false) {
        throw new InvalidArgumentException('FCT_WEBHOOK_DISPATCH_LIMIT debe estar entre 1 y 100.');
    }
    $eventId = getenv('FCT_WEBHOOK_DISPATCH_EVENT_ID');
    if ($eventId !== false && !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $eventId)) {
        throw new InvalidArgumentException('FCT_WEBHOOK_DISPATCH_EVENT_ID no es válido.');
    }

    $db = getPDOConnection();
    $processed = 0;
    $delivered = 0;
    $retrying = 0;
    $failed = 0;

    while ($processed < $limit && ($event = claimWebhook($db, $eventId === false ? null : $eventId)) !== null) {
        $timestamp = (string)time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $event['payload'], $secret);
        $curl = curl_init($destination);
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar el cliente HTTP cURL.');
        }
        $responseBytes = 0;
        $responseTooLarge = false;
        $responseBody = '';
        $curlOptions = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $event['payload'],
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-FCT-Event-Id: ' . $event['event_id'],
                'X-FCT-Timestamp: ' . $timestamp,
                'X-FCT-Signature: ' . $signature,
            ],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$responseBytes, &$responseTooLarge, &$responseBody): int {
                $responseBytes += strlen($chunk);
                if ($responseBytes > 16384) {
                    $responseTooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
        ];
        if ($resolvedAddresses !== []) {
            $destinationParts = parse_url($destination);
            if (!is_array($destinationParts) || !isset($destinationParts['host'])) {
                throw new RuntimeException('FCT_WEBHOOK_OUTBOUND_URL no es válida.');
            }
            $host = strtolower(rtrim(trim($destinationParts['host'], '[]'), '.'));
            $port = (int)($destinationParts['port'] ?? 443);
            $curlOptions[CURLOPT_RESOLVE] = array_map(
                static fn(string $address): string => $host . ':' . $port . ':'
                    . (str_contains($address, ':') ? '[' . $address . ']' : $address),
                $resolvedAddresses
            );
        }
        curl_setopt_array($curl, $curlOptions);
        $response = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $networkError = $response === false;
        curl_close($curl);

        $success = !$networkError && $status >= 200 && $status < 300;
        $permanent = $responseTooLarge
            || (!$networkError
                && (($status >= 300 && $status < 400)
                    || ($status >= 400 && $status < 500 && !in_array($status, [408, 425, 429], true))));
        $error = $success
            ? ''
            : ($responseTooLarge ? 'response_too_large' : ($networkError ? 'network_error' : 'HTTP ' . $status));
        recordWebhookResult(
            $db,
            $event,
            $networkError || $status === 0 ? null : $status,
            $networkError ? null : $responseBody,
            $error,
            $success,
            $permanent
        );

        $processed++;
        if ($success) {
            $delivered++;
            echo 'Entregado ' . $event['event_id'] . PHP_EOL;
        } elseif ($permanent || (int)$event['attempts'] >= 12) {
            $failed++;
            echo 'Fallido permanentemente ' . $event['event_id'] . ': ' . $error . PHP_EOL;
        } else {
            $retrying++;
            echo 'Pendiente de reintento ' . $event['event_id'] . ': ' . $error . PHP_EOL;
        }
    }

    echo json_encode([
        'procesados' => $processed,
        'entregados' => $delivered,
        'para_reintento' => $retrying,
        'fallidos_permanentes' => $failed,
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
