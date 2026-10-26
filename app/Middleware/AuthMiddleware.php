<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/JsonResponse.php';

final class CompanyApprovalRequiredException extends RuntimeException
{
}

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
            if (JsonResponse::wantsJson()) {
                http_response_code(401);
                throw new RuntimeException('Se requiere autenticación para acceder a esta sección.');
            }
            header('Location: index.php?action=login');
            exit;
        }

        $stmt = getPDOConnection()->prepare(
            'SELECT u.rol, u.activo, e.estado AS estado_empresa
             FROM usuarios u
             LEFT JOIN empresas e ON e.usuario_id = u.id
             WHERE u.id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['activo'] !== 1) {
            if ($route === 'descargar-convenio') {
                self::auditDeniedDownload((int)$userId, 'cuenta_inactiva');
            }
            $_SESSION = [];
            session_destroy();
            if (JsonResponse::wantsJson()) {
                http_response_code(401);
                throw new RuntimeException('La cuenta no está activa.');
            }
            header('Location: index.php?action=login');
            exit;
        }
        $_SESSION['rol'] = $user['rol'];
        $role = $user['rol'];

        if ($role === 'Empresa' && ($user['estado_empresa'] ?? null) !== 'Aprobada') {
            $estadoSolicitud = $user['estado_empresa'] ?? 'SinSolicitud';
            $message = match ($estadoSolicitud) {
                'Pendiente_Aprobacion' => 'La solicitud de empresa está pendiente de aprobación; todavía no puedes acceder a los módulos privados.',
                'Rechazada' => 'La solicitud de empresa ha sido rechazada. Contacta con el centro educativo.',
                default => 'No se encontró un perfil de empresa aprobado para esta cuenta.',
            };
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['empresa_solicitud_estado'] = in_array(
                $estadoSolicitud,
                ['Pendiente_Aprobacion', 'Rechazada'],
                true
            ) ? $estadoSolicitud : 'SinSolicitud';
            if (JsonResponse::wantsJson()) {
                throw new CompanyApprovalRequiredException($message);
            }
            header('Location: index.php?action=solicitud-pendiente');
            exit;
        }

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
