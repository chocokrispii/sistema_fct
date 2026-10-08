<?php

final class CsrfMiddleware
{
    public static function token(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    public static function verify(): void
    {
        $submitted = $_POST['_csrf'] ?? '';
        $expected = $_SESSION['csrf_token'] ?? '';

        if (!is_string($submitted) || !is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
            http_response_code(403);
            throw new RuntimeException('La sesión del formulario ha caducado. Recarga la página e inténtalo de nuevo.');
        }
    }
}
