<?php

declare(strict_types=1);

require_once __DIR__ . '/../Core/ConvenioSignatureMode.php';
require_once __DIR__ . '/AutoFirmaNativeStrategy.php';
require_once __DIR__ . '/AutoFirmaMockStrategy.php';

final class SignatureProviderFactory
{
    public static function create(): SignatureProviderService
    {
        return match (ConvenioSignatureMode::current()) {
            'autofirma' => new AutoFirmaNativeStrategy(),
            'mock' => new AutoFirmaMockStrategy(),
            default => throw new RuntimeException('No hay un proveedor de firma PAdES habilitado.'),
        };
    }
}
