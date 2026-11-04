<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/WebhookCrypto.php';
require_once __DIR__ . '/../app/Core/WebhookEndpoint.php';
require_once __DIR__ . '/../app/Services/WebhookDeliveryWorker.php';

if (!WebhookCrypto::configured()) {
    fwrite(STDERR, "FCT_WEBHOOK_ENCRYPTION_KEY debe ser una clave hexadecimal de 64 caracteres.\n");
    exit(1);
}

$db = getPDOConnection();
$worker = new WebhookDeliveryWorker($db);
$once = in_array('--once', $argv, true);

do {
    try {
        $processed = $worker->runOne();
        if (!$processed && !$once) {
            usleep(1_000_000);
        }
    } catch (Throwable $exception) {
        error_log('Webhook worker: ' . $exception->getMessage());
        if (!$once) {
            sleep(5);
        }
    }
} while (!$once);
