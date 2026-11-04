<?php

declare(strict_types=1);

final class WebhookEndpoint
{
    public static function resolvePublicAddress(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int)$parts['port'] !== 443)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || str_contains($url, '\\')
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw new InvalidArgumentException('El endpoint debe ser una URL HTTPS pública, sin credenciales ni fragmentos, en el puerto 443.');
        }

        $rawHost = trim((string)$parts['host'], '[]');
        if (str_ends_with($rawHost, '.')) {
            throw new InvalidArgumentException('El endpoint no puede usar un nombre de dominio con punto final.');
        }
        $host = strtolower($rawHost);
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            if (preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/i', $host) !== 1
                || !str_contains($host, '.')
                || str_ends_with($host, '.local')
                || str_ends_with($host, '.localhost')
                || str_ends_with($host, '.internal')) {
                throw new InvalidArgumentException('El endpoint debe usar un nombre de dominio público.');
            }

            $addresses = gethostbynamel($host) ?: [];
            if (function_exists('dns_get_record')) {
                foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                    if (isset($record['ipv6'])) {
                        $addresses[] = $record['ipv6'];
                    }
                }
            }
        }
        $addresses = array_values(array_unique($addresses));
        if ($addresses === []) {
            throw new InvalidArgumentException('No se pudo resolver el host público del endpoint.');
        }

        foreach ($addresses as $address) {
            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false) {
                throw new InvalidArgumentException('El endpoint resuelve a una dirección IP privada o reservada.');
            }
        }

        return [$host, $addresses[0]];
    }
}
