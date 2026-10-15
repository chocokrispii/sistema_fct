<?php

declare(strict_types=1);

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$host = $_SERVER['HTTP_HOST'] ?? '';
if (
    !in_array($remoteAddress, ['127.0.0.1', '::1'], true)
    || $host !== '127.0.0.1:8003'
) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/Webhook.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$respondJson = static function (int $status, array $body): never {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_GET['action'] ?? '') === 'send-outbound') {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        $respondJson(405, ['error' => 'method_not_allowed']);
    }

    $requestBody = file_get_contents('php://input', false, null, 0, 4097);
    if (!is_string($requestBody) || strlen($requestBody) > 4096) {
        $respondJson(413, ['error' => 'payload_too_large']);
    }
    try {
        $request = json_decode($requestBody, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $respondJson(400, ['error' => 'invalid_json']);
    }
    if (
        !$request instanceof stdClass
        || ($request->confirm ?? null) !== true
        || !is_string($request->event ?? null)
        || !preg_match('/^[a-z][a-z0-9._-]{0,99}$/', $request->event)
        || !is_string($request->reference ?? null)
        || trim($request->reference) === ''
        || strlen($request->reference) > 80
    ) {
        $respondJson(400, ['error' => 'invalid_test_event']);
    }
    if (getenv('FCT_WEBHOOK_OUTBOUND_URL') !== 'http://127.0.0.1:8001/webhook.php') {
        $respondJson(503, ['error' => 'outbound_test_requires_local_endpoint']);
    }

    try {
        Webhook::secretFromEnvironment('FCT_WEBHOOK_OUTBOUND_SECRET');
        $db = getPDOConnection();
        $pending = (int)$db->query(
            'SELECT COUNT(*) FROM webhook_outbox
             WHERE delivered_at IS NULL AND failed_at IS NULL'
        )->fetchColumn();
        if ($pending !== 0) {
            $respondJson(409, ['error' => 'outbox_has_unprocessed_events']);
        }

        $testId = Webhook::uuid();
        Webhook::enqueue($db, $request->event, [
            'referencia' => trim($request->reference),
            'origen' => 'interfaz-local',
            'prueba_id' => $testId,
        ]);
        $findEvent = $db->prepare(
            "SELECT event_id FROM webhook_outbox
             WHERE JSON_UNQUOTE(JSON_EXTRACT(payload, '$.data.prueba_id')) = :test_id"
        );
        $findEvent->execute(['test_id' => $testId]);
        $eventId = $findEvent->fetchColumn();
        if (!is_string($eventId)) {
            throw new RuntimeException('No se encontró el evento sintético en la cola saliente.');
        }
    } catch (Throwable $exception) {
        error_log('No se pudo preparar el webhook sintético saliente: ' . $exception->getMessage());
        $respondJson(500, ['error' => 'outbound_test_enqueue_failed']);
    }

    $previousEnvironment = [];
    foreach ([
        'FCT_WEBHOOK_DISPATCH_ALLOW' => '1',
        'FCT_WEBHOOK_DISPATCH_LIMIT' => '1',
        'FCT_WEBHOOK_DISPATCH_EVENT_ID' => $eventId,
    ] as $name => $value) {
        $previousEnvironment[$name] = getenv($name);
        putenv($name . '=' . $value);
    }

    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../scripts/dispatch_webhooks.php'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__)
    );
    if (!is_resource($process)) {
        $respondJson(500, ['error' => 'outbound_test_dispatch_start_failed', 'event_id' => $eventId]);
    }
    fclose($pipes[0]);
    $workerOutput = stream_get_contents($pipes[1], 16385);
    $workerError = stream_get_contents($pipes[2], 4097);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $workerStatus = proc_close($process);
    foreach ($previousEnvironment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }

    try {
        $verify = $db->prepare(
            'SELECT o.delivered_at, o.http_status,
                    (SELECT COUNT(*) FROM webhook_inbox i WHERE i.event_id = o.event_id) AS received
             FROM webhook_outbox o WHERE o.event_id = :event_id'
        );
        $verify->execute(['event_id' => $eventId]);
        $result = $verify->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $exception) {
        error_log('No se pudo verificar el webhook sintético saliente: ' . $exception->getMessage());
        $respondJson(500, ['error' => 'outbound_test_verification_failed', 'event_id' => $eventId]);
    }

    if (
        $workerStatus !== 0
        || !is_array($result)
        || $result['delivered_at'] === null
        || (int)$result['http_status'] < 200
        || (int)$result['http_status'] >= 300
        || (int)$result['received'] !== 1
    ) {
        error_log('El despachador local no pudo entregar el evento sintético: ' . trim((string)$workerError));
        $respondJson(502, [
            'error' => 'outbound_test_delivery_failed',
            'event_id' => $eventId,
            'worker' => trim((string)$workerOutput),
        ]);
    }

    $respondJson(200, [
        'sent' => true,
        'event_id' => $eventId,
        'event' => $request->event,
        'http_status' => (int)$result['http_status'],
        'received_locally' => true,
        'worker' => trim((string)$workerOutput),
    ]);
}

if (($_GET['action'] ?? '') === 'sign') {
    header('Content-Type: application/json; charset=UTF-8');
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        echo json_encode(['error' => 'method_not_allowed']);
        exit;
    }

    $body = file_get_contents('php://input', false, null, 0, 65537);
    if (!is_string($body) || strlen($body) > 65536) {
        http_response_code(413);
        echo json_encode(['error' => 'payload_too_large']);
        exit;
    }

    try {
        $payload = json_decode($body, false, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json']);
        exit;
    }

    if (
        !$payload instanceof stdClass
        || !is_string($payload->event_id ?? null)
        || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $payload->event_id)
    ) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_event_id']);
        exit;
    }

    $secret = getenv('FCT_WEBHOOK_INBOUND_SECRET');
    if (!is_string($secret) || strlen($secret) < 32) {
        http_response_code(503);
        echo json_encode(['error' => 'webhook_not_configured']);
        exit;
    }

    $timestamp = (string)time();
    echo json_encode([
        'event_id' => $payload->event_id,
        'timestamp' => $timestamp,
        'signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}

$secret = getenv('FCT_WEBHOOK_INBOUND_SECRET');
if (!is_string($secret) || strlen($secret) < 32) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Configura FCT_WEBHOOK_INBOUND_SECRET para usar la prueba local.';
    exit;
}

$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; script-src 'nonce-$nonce'; connect-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prueba local de webhooks · Sistema FCT</title>
    <style nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
        :root {
            color-scheme: light;
            font-family: "Segoe UI", Arial, sans-serif;
            color: #172b4d;
            background: #f3f7fc;
        }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 18px; }
        main { max-width: 820px; margin: 0 auto; }
        h1 { margin: 0 0 8px; font-size: 28px; }
        p { line-height: 1.55; }
        .card {
            margin-top: 20px;
            padding: 24px;
            border: 1px solid #d9e3f0;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 16px #173b6310;
        }
        .notice {
            padding: 12px 14px;
            border-left: 4px solid #d97706;
            background: #fff7ed;
        }
        label { display: block; margin: 18px 0 6px; font-weight: 600; }
        input[type="text"] {
            width: 100%;
            padding: 11px 12px;
            border: 1px solid #bac9dc;
            border-radius: 7px;
            font: inherit;
        }
        .confirm { display: flex; gap: 10px; align-items: flex-start; font-weight: 400; }
        .confirm input { margin-top: 5px; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 20px; }
        button {
            padding: 11px 16px;
            border: 0;
            border-radius: 7px;
            background: #1768d2;
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }
        button.secondary { background: #385574; }
        button:disabled { cursor: not-allowed; opacity: .55; }
        #status { min-height: 1.5em; font-weight: 600; }
        pre {
            overflow-wrap: anywhere;
            white-space: pre-wrap;
            padding: 14px;
            border-radius: 7px;
            background: #f1f4f8;
        }
        a { color: #1768a5; }
        :focus-visible { outline: 3px solid #f0a000; outline-offset: 2px; }
        @media (max-width: 520px) {
            body { padding: 20px 12px; }
            .card { padding: 18px; }
            .actions button { width: 100%; }
        }
    </style>
</head>
<body>
<main>
    <p><a href="/">← Volver a Sistema FCT</a></p>
    <h1>Probar webhooks</h1>
    <p>Prueba la recepción de eventos firmados y el envío saliente desde Sistema FCT. Todo el recorrido permanece en este equipo; no tienes que copiar ni escribir secretos.</p>

    <section class="card" aria-labelledby="test-title">
        <h2 id="test-title">Evento sintético</h2>
        <p class="notice"><strong>Solo para pruebas locales.</strong> Cada evento nuevo se guarda en <code>webhook_inbox</code>. Usa exclusivamente datos ficticios; no se modifican alumnos, empresas, ofertas ni convenios.</p>

        <label for="event-type">Tipo de evento</label>
        <input id="event-type" type="text" value="prueba.integracion" maxlength="100" autocomplete="off" required>

        <label for="reference">Referencia ficticia</label>
        <input id="reference" type="text" value="dato-sintetico" maxlength="80" autocomplete="off" required>

        <label class="confirm">
            <input id="confirm" type="checkbox">
            <span>Entiendo que al enviar se registrará un evento de prueba en la bandeja local de entrada.</span>
        </label>

        <div class="actions">
            <button id="send" type="button" disabled>Enviar evento de prueba</button>
            <button id="repeat" class="secondary" type="button" disabled>Repetir el mismo envío</button>
        </div>
        <p id="status" role="status" aria-live="polite">Confirma la prueba para habilitar el envío.</p>
        <h3>Respuesta del endpoint</h3>
        <pre id="response">Todavía no se ha enviado ningún evento.</pre>
    </section>

    <section class="card" aria-labelledby="outbound-title">
        <h2 id="outbound-title">Probar envío saliente</h2>
        <p>Encola un evento sintético con el mismo formato que usa Sistema FCT, ejecuta el despachador y lo entrega al endpoint local. No se envía a servicios externos ni modifica datos académicos.</p>
        <label class="confirm">
            <input id="outbound-confirm" type="checkbox">
            <span>Confirmo que se creará un evento sintético en <code>webhook_outbox</code> y se enviará al receptor local.</span>
        </label>
        <div class="actions">
            <button id="send-outbound" type="button" disabled>Enviar webhook de prueba</button>
        </div>
        <p id="outbound-status" role="status" aria-live="polite">Confirma la prueba para habilitar el envío.</p>
        <h3>Resultado del envío</h3>
        <pre id="outbound-response">Todavía no se ha encolado ningún webhook.</pre>
    </section>
</main>
<script nonce="<?= htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') ?>">
    const confirmation = document.querySelector('#confirm');
    const eventType = document.querySelector('#event-type');
    const reference = document.querySelector('#reference');
    const sendButton = document.querySelector('#send');
    const repeatButton = document.querySelector('#repeat');
    const status = document.querySelector('#status');
    const response = document.querySelector('#response');
    const outboundConfirmation = document.querySelector('#outbound-confirm');
    const outboundButton = document.querySelector('#send-outbound');
    const outboundStatus = document.querySelector('#outbound-status');
    const outboundResponse = document.querySelector('#outbound-response');
    let lastRequest = null;

    function updateControls() {
        const validEventType = /^[a-z][a-z0-9._-]{0,99}$/.test(eventType.value.trim());
        sendButton.disabled = !confirmation.checked || !validEventType || !reference.value.trim();
        repeatButton.disabled = !confirmation.checked || lastRequest === null;
        outboundButton.disabled = !outboundConfirmation.checked || !validEventType || !reference.value.trim();
    }

    async function sendRequest(request) {
        status.textContent = 'Enviando al endpoint local...';
        try {
            const result = await fetch('webhook.php', {
                method: 'POST',
                headers: request.headers,
                body: request.body,
                cache: 'no-store'
            });
            const text = await result.text();
            let formatted = text;
            try {
                formatted = JSON.stringify(JSON.parse(text), null, 2);
            } catch {
                formatted = text || '(respuesta vacía)';
            }
            status.textContent = `Respuesta HTTP ${result.status}${result.ok ? ' · correcta' : ' · revisa el error'}`;
            response.textContent = `Evento: ${request.eventId}\nHTTP: ${result.status}\n\n${formatted}`;
        } catch (error) {
            status.textContent = 'No se pudo conectar con el endpoint local.';
            response.textContent = `${request.eventId}\n${error instanceof Error ? error.message : String(error)}`;
        }
    }

    confirmation.addEventListener('change', updateControls);
    outboundConfirmation.addEventListener('change', updateControls);
    eventType.addEventListener('input', updateControls);
    reference.addEventListener('input', updateControls);

    sendButton.addEventListener('click', async () => {
        if (sendButton.disabled) {
            return;
        }

        const eventId = `web-prueba-${crypto.randomUUID()}`;
        const body = JSON.stringify({
            event_id: eventId,
            event: eventType.value.trim(),
            version: 1,
            occurred_at: new Date().toISOString(),
            data: {
                referencia: reference.value.trim(),
                origen: 'interfaz-local'
            }
        });

        sendButton.disabled = true;
        lastRequest = null;
        updateControls();
        try {
            const signingResponse = await fetch('?action=sign', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body,
                cache: 'no-store'
            });
            const signingResult = await signingResponse.json();
            if (!signingResponse.ok) {
                throw new Error(signingResult.error || `Error de firma HTTP ${signingResponse.status}`);
            }

            lastRequest = {
                eventId,
                body,
                headers: {
                    'Content-Type': 'application/json',
                    'X-FCT-Event-Id': signingResult.event_id,
                    'X-FCT-Timestamp': signingResult.timestamp,
                    'X-FCT-Signature': signingResult.signature
                }
            };
            await sendRequest(lastRequest);
        } catch (error) {
            status.textContent = 'No se pudo preparar el envío firmado.';
            response.textContent = error instanceof Error ? error.message : String(error);
        } finally {
            sendButton.disabled = !confirmation.checked
                || !/^[a-z][a-z0-9._-]{0,99}$/.test(eventType.value.trim())
                || !reference.value.trim();
            updateControls();
        }
    });

    repeatButton.addEventListener('click', async () => {
        if (!repeatButton.disabled && lastRequest !== null) {
            await sendRequest(lastRequest);
        }
    });

    outboundButton.addEventListener('click', async () => {
        if (outboundButton.disabled) {
            return;
        }
        outboundButton.disabled = true;
        outboundStatus.textContent = 'Encolando y enviando al endpoint local...';
        try {
            const result = await fetch('?action=send-outbound', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    confirm: true,
                    event: eventType.value.trim(),
                    reference: reference.value.trim()
                }),
                cache: 'no-store'
            });
            const text = await result.text();
            let formatted = text;
            try {
                formatted = JSON.stringify(JSON.parse(text), null, 2);
            } catch {
                formatted = text || '(respuesta vacía)';
            }
            outboundStatus.textContent = `Resultado HTTP ${result.status}${result.ok ? ' · entregado' : ' · revisa el error'}`;
            outboundResponse.textContent = formatted;
        } catch (error) {
            outboundStatus.textContent = 'No se pudo completar el envío local.';
            outboundResponse.textContent = error instanceof Error ? error.message : String(error);
        } finally {
            updateControls();
        }
    });

    updateControls();
</script>
</body>
</html>
