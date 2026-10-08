<?php

final class View
{
    public static function render(string $template, array $data = []): void
    {
        $path = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($path)) {
            throw new RuntimeException('No se encontró la vista solicitada.');
        }

        extract($data, EXTR_SKIP);
        require $path;
    }
}
