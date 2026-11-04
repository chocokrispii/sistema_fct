<?php

declare(strict_types=1);

final class WebhookCrypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function configured(): bool
    {
        return self::key() !== null;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new RuntimeException('Configura FCT_WEBHOOK_ENCRYPTION_KEY antes de crear webhooks.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
        if (!is_string($ciphertext)) {
            throw new RuntimeException('No se pudo cifrar el secreto del webhook.');
        }

        return base64_encode($nonce . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        $key = self::key();
        $payload = base64_decode($encoded, true);
        if ($key === null || !is_string($payload) || strlen($payload) < 29) {
            throw new RuntimeException('El secreto del webhook no se puede descifrar; comprueba la clave de cifrado configurada.');
        }

        $plaintext = openssl_decrypt(
            substr($payload, 28),
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );
        if (!is_string($plaintext)) {
            throw new RuntimeException('El secreto del webhook no se puede descifrar; comprueba la clave de cifrado configurada.');
        }

        return $plaintext;
    }

    private static function key(): ?string
    {
        $value = getenv('FCT_WEBHOOK_ENCRYPTION_KEY');
        if (!is_string($value) || preg_match('/\A[a-fA-F0-9]{64}\z/', $value) !== 1) {
            return null;
        }
        $key = hex2bin($value);
        return is_string($key) && strlen($key) === 32 ? $key : null;
    }
}
