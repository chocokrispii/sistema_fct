<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/Core/ActivationUrl.php';

function assertActivationUrl(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$originalEnvironment = getenv('APP_ENV');
try {
    putenv('APP_ENV=production');
    assertActivationUrl(
        ActivationUrl::isAllowedBaseUrl('https://fct.example'),
        'HTTPS activation URLs must be accepted.'
    );
    assertActivationUrl(
        !ActivationUrl::isAllowedBaseUrl('http://fct.example'),
        'Public HTTP activation URLs must be rejected.'
    );
    assertActivationUrl(
        !ActivationUrl::isAllowedBaseUrl('http://localhost:8084'),
        'Loopback HTTP activation URLs must be rejected outside development.'
    );

    putenv('APP_ENV=development');
    assertActivationUrl(
        ActivationUrl::isAllowedBaseUrl('http://localhost:8084'),
        'The documented development loopback activation URL must be accepted.'
    );
    assertActivationUrl(
        ActivationUrl::isAllowedBaseUrl('http://127.0.0.1:8084'),
        'IPv4 loopback activation URLs must be accepted in development.'
    );
    assertActivationUrl(
        ActivationUrl::isAllowedBaseUrl('http://[::1]:8084'),
        'IPv6 loopback activation URLs must be accepted in development.'
    );
    assertActivationUrl(
        !ActivationUrl::isAllowedBaseUrl('http://192.168.1.20:8084'),
        'Non-loopback HTTP activation URLs must be rejected in development.'
    );
    assertActivationUrl(
        !ActivationUrl::isAllowedBaseUrl('https://user:pass@fct.example'),
        'Activation URLs with embedded credentials must be rejected.'
    );
    assertActivationUrl(
        !ActivationUrl::isAllowedBaseUrl('https://fct.example?token=secret'),
        'Activation base URLs with a query string must be rejected.'
    );
} finally {
    putenv($originalEnvironment === false ? 'APP_ENV' : 'APP_ENV=' . $originalEnvironment);
}

echo "ActivationUrl tests passed.\n";
