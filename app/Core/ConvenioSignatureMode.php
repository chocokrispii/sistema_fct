<?php

declare(strict_types=1);

final class ConvenioSignatureMode
{
    public static function current(): string
    {
        $configured = strtolower(trim((string)(getenv('FCT_CONVENIO_SIGNATURE_MODE') ?: '')));
        $environment = strtolower((string)(getenv('APP_ENV') ?: 'production'));
        $mode = $configured !== ''
            ? $configured
            : (in_array($environment, ['development', 'testing'], true) ? 'test' : 'disabled');

        if (!in_array($mode, ['autofirma', 'mock', 'test', 'disabled'], true)) {
            throw new RuntimeException('FCT_CONVENIO_SIGNATURE_MODE debe ser autofirma, mock, test o disabled.');
        }
        if (in_array($mode, ['mock', 'test'], true) && !in_array($environment, ['development', 'testing'], true)) {
            throw new RuntimeException('Los modos mock y test solo se permiten en desarrollo o pruebas.');
        }

        return $mode;
    }

    public static function canUseAutoFirma(): bool
    {
        return self::current() === 'autofirma';
    }

    public static function canUseMockSignature(): bool
    {
        return self::current() === 'mock';
    }
}
