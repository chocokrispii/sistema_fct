<?php

require_once __DIR__ . '/../../config/database.php';

final class AuthMiddleware
{
    public static function requireRoles(array $roles, string $route = ''): void
    {
        $userId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            if ($route === 'ofertas') {
                http_response_code(403);
                throw new RuntimeException('Inicia sesión con una cuenta autorizada para consultar ofertas.');
            }
            if ($route === 'descargar-convenio') {
                self::auditDeniedDownload(null, 'autenticacion_requerida');
            }
            header('Location: index.php?action=login');
            exit;
        }

        $stmt = getPDOConnection()->prepare('SELECT rol, activo FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['activo'] !== 1) {
            if ($route === 'descargar-convenio') {
                self::auditDeniedDownload((int)$userId, 'cuenta_inactiva');
            }
            $_SESSION = [];
            session_destroy();
            header('Location: index.php?action=login');
            exit;
        }
        $_SESSION['rol'] = $user['rol'];
        $role = $user['rol'];

        if (!in_array($role, $roles, true)) {
            if ($route === 'descargar-convenio') {
                self::auditDeniedDownload((int)$userId, 'rol_no_autorizado; rol=' . $role);
            }
            http_response_code(403);
            throw new RuntimeException('No tienes permiso para acceder a esta sección.');
        }
    }

    private static function auditDeniedDownload(?int $userId, string $reason): void
    {
        require_once __DIR__ . '/../Models/Auditoria.php';
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        (new Auditoria(getPDOConnection()))->registrar(
            $userId,
            'convenio.descarga.denegada',
            'convenios',
            $id === false ? null : (int)$id,
            'motivo=' . $reason
        );
    }
}
