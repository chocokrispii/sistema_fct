<?php

declare(strict_types=1);

final class ApiToken
{
    public static function issue(): array
    {
        $plainText = 'fct_' . bin2hex(random_bytes(32));

        return [
            'plainText' => $plainText,
            'hash' => hash('sha256', $plainText),
        ];
    }

    public static function authenticate(PDO $db, ?string $authorization): ?array
    {
        if (!is_string($authorization) || !preg_match('/\ABearer (fct_[a-f0-9]{64})\z/', $authorization, $matches)) {
            return null;
        }

        $stmt = $db->prepare(
            'SELECT t.id AS token_id, t.user_id, u.rol
             FROM api_access_tokens t
             INNER JOIN usuarios u ON u.id = t.user_id
             WHERE t.token_hash = :token_hash
               AND t.revoked_at IS NULL AND u.activo = 1
             LIMIT 1'
        );
        $stmt->execute(['token_hash' => hash('sha256', $matches[1])]);
        $identity = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$identity) {
            return null;
        }

        $update = $db->prepare('UPDATE api_access_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = :id');
        $update->execute(['id' => $identity['token_id']]);

        return [
            'tokenId' => (int)$identity['token_id'],
            'userId' => (int)$identity['user_id'],
            'role' => (string)$identity['rol'],
        ];
    }
}
