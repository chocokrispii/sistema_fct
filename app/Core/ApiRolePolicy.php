<?php

declare(strict_types=1);

final class ApiRolePolicy
{
    private const REVIEW_ROLES = [
        'ofertas' => ['Administrador'],
        'convenios' => ['Administrador'],
    ];

    public static function reviewRoles(string $resource): array
    {
        return self::REVIEW_ROLES[$resource] ?? [];
    }
}
