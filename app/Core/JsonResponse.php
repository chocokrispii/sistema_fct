<?php

declare(strict_types=1);

final class JsonResponse
{
    private static bool $sent = false;

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        return is_string($accept) && str_contains(strtolower($accept), 'application/json')
            || is_string($contentType) && str_contains(strtolower($contentType), 'application/json');
    }

    public static function success(array $data, int $status = 200): void
    {
        self::send(['success' => true, 'data' => $data], $status);
    }

    public static function failure(string $error, int $status): void
    {
        self::send(['success' => false, 'error' => $error], $status);
    }

    public static function hasSent(): bool
    {
        return self::$sent;
    }

    private static function send(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::$sent = true;
    }
}
