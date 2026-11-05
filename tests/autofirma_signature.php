<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/../app/Services/PadesSignatureVerifier.php';

function assertAutoFirma(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$validPdfSigOutput = <<<'OUTPUT'
Digital Signature Info of: convenio.pdf
Signature #1:
  - Signer Certificate Common Name: Coordinador de pruebas
  - Signing Hash Algorithm: SHA-256
  - Signature Type: ETSI.CAdES.detached
  - Signed Ranges: [0 - 100], [200 - 400]
  - Total document signed
  - Signature Validation: Signature is Valid.
  - Certificate Validation: Certificate is Trusted.
OUTPUT;
$validation = PadesSignatureVerifier::parseOutput($validPdfSigOutput);
assertAutoFirma(
    $validation['signer'] === 'Coordinador de pruebas' && $validation['signature_count'] === 1,
    'La validación debe extraer la identidad de una firma PAdES confiable.'
);

$invalidOutput = str_replace(
    'Certificate is Trusted.',
    'Certificate issuer is not Trusted.',
    $validPdfSigOutput
);
$rejected = false;
try {
    PadesSignatureVerifier::parseOutput($invalidOutput);
} catch (DomainException) {
    $rejected = true;
}
assertAutoFirma($rejected, 'Una firma emitida por un certificado no confiable debe rechazarse.');

$testEnvironment = getenv('APP_ENV');
$testMode = getenv('FCT_CONVENIO_SIGNATURE_MODE');
try {
    putenv('APP_ENV=development');
    putenv('FCT_CONVENIO_SIGNATURE_MODE=');
    assertAutoFirma(ConvenioSignatureMode::current() === 'test', 'Desarrollo debe usar por defecto el modo de prueba.');

    putenv('APP_ENV=production');
    putenv('FCT_CONVENIO_SIGNATURE_MODE=test');
    $productionTestRejected = false;
    try {
        ConvenioSignatureMode::current();
    } catch (RuntimeException) {
        $productionTestRejected = true;
    }
    assertAutoFirma($productionTestRejected, 'Producción debe rechazar el modo de prueba.');
} finally {
    $testEnvironment === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $testEnvironment);
    $testMode === false ? putenv('FCT_CONVENIO_SIGNATURE_MODE') : putenv('FCT_CONVENIO_SIGNATURE_MODE=' . $testMode);
}

echo "autofirma_signature: OK\n";
