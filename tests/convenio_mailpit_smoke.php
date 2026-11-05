<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/../app/Core/ConvenioApprovalMailer.php';
require_once __DIR__ . '/../app/Services/AutoFirmaMockStrategy.php';

if (!ConvenioSignatureMode::canUseMockSignature()) {
    throw new RuntimeException('Este test requiere el modo mock local y Mailpit.');
}

$sourcePath = getenv('FCT_CONVENIO_MOCK_TEST_PDF') ?: '/var/lib/fct/storage/autofirma-dev/convenio-prueba.pdf';
$signedPath = tempnam(dirname($sourcePath), '.mailpit-convenio-smoke-');
if (!is_string($signedPath)) {
    throw new RuntimeException('No se pudo preparar el PDF de prueba para el correo.');
}

try {
    $strategy = new AutoFirmaMockStrategy();
    $strategy->sign($sourcePath, $signedPath);
    $strategy->verify($signedPath);
    $number = 'US04-SMOKE-' . bin2hex(random_bytes(4));
    (new ConvenioApprovalMailer())->send(
        'fct-smoke@example.test',
        'Empresa de pruebas',
        $number,
        'http://localhost:8080/index.php?action=descargar-convenio-firmado&id=1',
        $signedPath
    );
    echo "convenio_mailpit_smoke: OK ($number)\n";
} finally {
    if (is_file($signedPath) && !unlink($signedPath)) {
        error_log('No se pudo retirar el PDF temporal del test de correo de convenio.');
    }
}
