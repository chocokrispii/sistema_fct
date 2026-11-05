<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/../app/Services/AutoFirmaMockStrategy.php';

if (!ConvenioSignatureMode::canUseMockSignature()) {
    throw new RuntimeException('Este test requiere FCT_CONVENIO_SIGNATURE_MODE=mock en desarrollo.');
}

$sourcePath = getenv('FCT_CONVENIO_MOCK_TEST_PDF') ?: '/var/lib/fct/storage/autofirma-dev/convenio-prueba.pdf';
$sourceHash = hash_file('sha256', $sourcePath);
if (!is_string($sourceHash)) {
    throw new RuntimeException('No se encuentra el PDF de prueba para la firma mock.');
}
$signedPath = tempnam(dirname($sourcePath), '.autofirma-mock-test-');
if (!is_string($signedPath)) {
    throw new RuntimeException('No se pudo crear un PDF temporal de prueba.');
}

$retainOnFailure = false;
try {
    $strategy = new AutoFirmaMockStrategy();
    $strategy->sign($sourcePath, $signedPath);
    $validation = $strategy->verify($signedPath);
    if (
        ($validation['signature_count'] ?? 0) !== 1
        || !is_string($validation['signer'] ?? null)
        || $validation['signer'] === ''
        || ($validation['certificate_trusted'] ?? true) !== false
        || !is_string(hash_file('sha256', $signedPath))
        || hash_equals($sourceHash, (string)hash_file('sha256', $signedPath))
        || !hash_equals($sourceHash, (string)hash_file('sha256', $sourcePath))
    ) {
        throw new RuntimeException('La firma PAdES local no superó las comprobaciones esperadas.');
    }

    echo "autofirma_mock_pades: OK ({$validation['signer']})\n";
} catch (Throwable $exception) {
    $retainOnFailure = true;
    fwrite(STDERR, "Generated PDF retained for diagnostics: $signedPath\n");
    throw $exception;
} finally {
    if (!$retainOnFailure && is_file($signedPath) && !unlink($signedPath)) {
        error_log('No se pudo retirar el PDF temporal del test PAdES mock.');
    }
}
