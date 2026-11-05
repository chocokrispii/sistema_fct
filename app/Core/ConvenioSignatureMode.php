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

        if (!in_array($mode, ['autofirma', 'test', 'simple', 'disabled'], true)) {
            throw new RuntimeException('FCT_CONVENIO_SIGNATURE_MODE debe ser autofirma, test, simple o disabled.');
        }
        if (in_array($mode, ['test', 'simple'], true) && !in_array($environment, ['development', 'testing'], true)) {
            throw new RuntimeException('Los modos de prueba y sello simple solo se permiten en desarrollo o pruebas.');
        }

        return $mode;
    }

    public static function canUseAutoFirma(): bool
    {
        return self::current() === 'autofirma';
    }

    public static function canUseSimpleStamp(): bool
    {
        return self::current() === 'simple';
    }
}
