<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/Webhook.php';

final class IntegrationTestController
{
    public function __construct(private ?PDO $db = null)
    {
        $this->db ??= getPDOConnection();
    }

    public function gestionar(): void
    {
        $result = null;
        $error = '';
        $formValues = [
            'event_type' => 'prueba.integracion',
            'event_data' => '{"referencia":"dato-sintetico"}',
        ];

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            foreach ($formValues as $field => $default) {
                if (isset($_POST[$field]) && is_string($_POST[$field])) {
                    $formValues[$field] = $_POST[$field];
                }
            }
            $action = $_POST['test_action'] ?? '';
            try {
                if ($action === 'receive') {
                    $result = $this->receiveSyntheticEvent();
                } elseif ($action === 'send') {
                    $result = $this->sendSyntheticEvent();
                } else {
                    throw new InvalidArgumentException('Selecciona una prueba de webhook válida.');
                }
            } catch (Throwable $exception) {
                error_log('Falló una prueba manual de integración: ' . $exception->getMessage());
                $error = $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'La prueba no se completó. Comprueba la configuración y los logs del servidor.';
            }
        }

        View::render('admin/integration_test', [
            'csrfToken' => CsrfMiddleware::token(),
            'error' => $error,
            'formValues' => $formValues,
            'result' => $result,
        ]);
    }

    private function receiveSyntheticEvent(): array
    {
        [$eventType, $data] = $this->validatedSyntheticInput();
        $secret = Webhook::secretFromEnvironment('FCT_WEBHOOK_INBOUND_SECRET');
        $eventId = 'ui-' . Webhook::uuid();
        $body = json_encode([
            'event_id' => $eventId,
            'event' => $eventType,
            'version' => 1,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $curl = curl_init($this->localReceiverUrl());
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar el cliente HTTP local.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'X-FCT-Event-Id: ' . $eventId,
                'X-FCT-Timestamp: ' . $timestamp,
                'X-FCT-Signature: ' . $signature,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        ]);
        $responseBody = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);
        if (!is_string($responseBody)) {
            throw new RuntimeException('No se pudo enviar la prueba al receptor local: ' . $curlError);
        }

        $response = json_decode($responseBody, true);
        $result = [
            'direction' => 'Recepción HTTP',
            'event_id' => $eventId,
            'event' => $eventType,
            'status' => $status,
            'request' => json_decode($body, true, 32, JSON_THROW_ON_ERROR),
            'response' => is_array($response) ? $response : ['raw' => $responseBody],
        ];
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('El receptor local respondió HTTP ' . $status . '.');
        }

        return $result;
    }

    private function sendSyntheticEvent(): array
    {
        [$eventType, $data] = $this->validatedSyntheticInput();
        $inboundSecret = Webhook::secretFromEnvironment('FCT_WEBHOOK_INBOUND_SECRET');
        $receiverUrl = $this->localReceiverUrl();
        if (!function_exists('proc_open')) {
            throw new RuntimeException('proc_open debe estar habilitado para ejecutar el despachador local.');
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('La extensión PHP cURL es necesaria para ejecutar el despachador.');
        }

        $testId = Webhook::uuid();
        $eventId = null;
        $this->withEnvironment([
            'FCT_WEBHOOK_OUTBOUND_URL' => $receiverUrl,
        ], function () use ($eventType, $data, $testId, &$eventId): void {
            Webhook::enqueue($this->db, $eventType, [
                ...$data,
                'origen' => 'panel-pruebas-integracion',
                'prueba_id' => $testId,
            ]);
            $findEvent = $this->db->prepare(
                "SELECT event_id FROM webhook_outbox
                 WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.prueba_id')) = :test_id"
            );
            $findEvent->execute(['test_id' => $testId]);
            $storedEventId = $findEvent->fetchColumn();
            if (!is_string($storedEventId)) {
                throw new RuntimeException('No se encontró el evento sintético en la cola de salida.');
            }
            $eventId = $storedEventId;
        });

        if (!is_string($eventId)) {
            throw new RuntimeException('No se pudo identificar el evento sintético.');
        }

        try {
            $workerOutput = $this->withEnvironment([
                'FCT_WEBHOOK_OUTBOUND_URL' => $receiverUrl,
                'FCT_WEBHOOK_OUTBOUND_SECRET' => $inboundSecret,
                'FCT_WEBHOOK_DISPATCH_ALLOW' => '1',
                'FCT_WEBHOOK_DISPATCH_LIMIT' => '1',
                'FCT_WEBHOOK_DISPATCH_EVENT_ID' => $eventId,
            ], fn(): string => $this->runDispatcher());
        } catch (Throwable $exception) {
            $this->markSyntheticEventFailed($eventId);
            throw $exception;
        }

        $verify = $this->db->prepare(
            'SELECT o.delivered_at, o.http_status,
                    (SELECT COUNT(*) FROM webhook_inbox i WHERE i.event_id = o.event_id) AS received
             FROM webhook_outbox o
             WHERE o.event_id = :event_id'
        );
        $verify->execute(['event_id' => $eventId]);
        $delivery = $verify->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($delivery)
            || $delivery['delivered_at'] === null
            || (int)$delivery['http_status'] < 200
            || (int)$delivery['http_status'] >= 300
            || (int)$delivery['received'] !== 1
        ) {
            $this->markSyntheticEventFailed($eventId);
            throw new RuntimeException('El evento no se confirmó en las bandejas locales de entrada y salida.');
        }

        return [
            'direction' => 'Envío saliente local',
            'event_id' => $eventId,
            'event' => $eventType,
            'status' => (int)$delivery['http_status'],
            'response' => [
                'sent_to' => 'Receptor local del contenedor',
                'received_locally' => true,
                'worker' => $workerOutput,
            ],
        ];
    }

    private function localReceiverUrl(): string
    {
        $serverPort = $_SERVER['SERVER_PORT'] ?? '';
        if (in_array($serverPort, ['8002', '8003'], true)) {
            return 'http://127.0.0.1:8001/webhook.php';
        }

        return 'http://127.0.0.1/webhook.php';
    }

    private function validatedSyntheticInput(): array
    {
        $eventType = $_POST['event_type'] ?? '';
        $dataRaw = $_POST['event_data'] ?? '';
        if (
            !is_string($eventType)
            || !preg_match('/^[a-z][a-z0-9._-]{0,99}$/', $eventType)
            || !is_string($dataRaw)
            || trim($dataRaw) === ''
            || strlen($dataRaw) > 8192
        ) {
            throw new InvalidArgumentException('Indica un evento válido y datos JSON de hasta 8 KiB.');
        }

        try {
            $data = json_decode($dataRaw, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Los datos del evento deben ser JSON válido.');
        }
        if (!$data instanceof stdClass) {
            throw new InvalidArgumentException('Los datos del evento deben ser un objeto JSON, por ejemplo {"referencia":"prueba"}.');
        }

        return [$eventType, (array)$data];
    }

    private function runDispatcher(): string
    {
        $script = dirname(__DIR__, 2) . '/scripts/dispatch_webhooks.php';
        if (!is_file($script)) {
            throw new RuntimeException('No se encontró el despachador local de webhooks.');
        }
        $phpCli = PHP_BINARY;
        if (!is_file($phpCli)) {
            throw new RuntimeException('No se encontró el intérprete PHP CLI para ejecutar el despachador.');
        }

        $pipes = [];
        $process = proc_open(
            [$phpCli, $script],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2)
        );
        if (!is_resource($process)) {
            throw new RuntimeException('No se pudo iniciar el despachador local.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1], 8193);
        $stderr = stream_get_contents($pipes[2], 4097);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($stdout)) {
            throw new RuntimeException(
                'El despachador local terminó con código ' . $exitCode
                . '. Salida: ' . trim((string)$stdout)
                . '. Error: ' . trim((string)$stderr)
            );
        }

        return trim($stdout);
    }

    private function markSyntheticEventFailed(string $eventId): void
    {
        $failed = $this->db->prepare(
            "UPDATE webhook_outbox
             SET failed_at = CURRENT_TIMESTAMP(), last_error = 'Fallo en prueba manual local',
                 locked_until = NULL, lock_token = NULL
             WHERE event_id = :event_id AND delivered_at IS NULL AND failed_at IS NULL"
        );
        $failed->execute(['event_id' => $eventId]);
    }

    private function withEnvironment(array $variables, callable $operation): mixed
    {
        $previous = [];
        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            return $operation();
        } finally {
            foreach ($previous as $name => $value) {
                putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }
}
