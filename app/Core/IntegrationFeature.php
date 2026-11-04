<?php

declare(strict_types=1);

final class IntegrationFeature
{
    public static function isEnabled(): bool
    {
        return strtolower(trim((string)getenv('FCT_INTEGRATIONS_ENABLED'))) === 'true';
    }
}
