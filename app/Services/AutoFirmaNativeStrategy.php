<?php

declare(strict_types=1);

require_once __DIR__ . '/SignatureProviderService.php';
require_once __DIR__ . '/PadesSignatureVerifier.php';

final class AutoFirmaNativeStrategy implements SignatureProviderService
{
    public function verify(string $signedPdfPath): array
    {
        $nssDirectory = getenv('FCT_AUTOFIRMA_NSS_DIR') ?: '/var/lib/fct/autofirma-nssdb';
        return (new PadesSignatureVerifier())->verify($signedPdfPath, $nssDirectory);
    }
}
