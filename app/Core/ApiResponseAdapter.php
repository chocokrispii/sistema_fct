<?php

declare(strict_types=1);

final class ApiResponseAdapter
{
    public static function successData(array $legacyBody): array
    {
        if (!isset($legacyBody['data']) || !is_array($legacyBody['data'])) {
            throw new LogicException('La respuesta de API debe contener un campo data de tipo array.');
        }

        if (!array_key_exists('meta', $legacyBody)) {
            return $legacyBody['data'];
        }

        if (!is_array($legacyBody['meta'])) {
            throw new LogicException('Los metadatos de paginación de la API no son válidos.');
        }

        return [
            'items' => $legacyBody['data'],
            'meta' => $legacyBody['meta'],
        ];
    }
}
