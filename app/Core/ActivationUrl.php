<?php

/** Validates the public base URL used to generate account activation links. */
final class ActivationUrl
{
    public static function isAllowedBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
        ) {
            return false;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }

        $host = strtolower($parts['host']);
        $isLoopback = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        $environment = strtolower((string)(getenv('APP_ENV') ?: 'production'));

        // HTTP is permitted only for the documented local-development Mailpit flow.
        return $scheme === 'http'
            && $isLoopback
            && $environment === 'development';
    }
}
