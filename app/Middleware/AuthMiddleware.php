<?php

require_once __DIR__ . '/../../config/database.php';

final class AuthMiddleware
{
    public static function requireRoles(array $roles): void
    {
        $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            header('Location: index.php?action=login');
            exit;
        }

        $stmt = getPDOConnection()->prepare('SELECT rol, activo FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['activo'] !== 1) {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php?action=login');
            exit;
        }
        $_SESSION['rol'] = $user['rol'];
        $role = $user['rol'];

        if (!in_array($role, $roles, true)) {
            http_response_code(403);
            throw new RuntimeException('No tienes permiso para acceder a esta sección.');
        }
    }
}
