<?php

require_once __DIR__ . '/../../config/database.php';

class Auditoria
{
    public function __construct(private PDO $db)
    {
    }

    public function registrar(
        ?int $usuarioId,
        string $evento,
        string $entidad,
        ?int $entidadId,
        string $detalle = '',
        ?string $accion = null
    ): void
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $actorName = $this->obtenerNombreCompleto($usuarioId);
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
            if (!is_string($ipAddress) || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                $ipAddress = 'unknown';
            }

            $stmt = $this->db->prepare(
                'INSERT INTO auditoria (usuario_id, evento, entidad, entidad_id, detalle)
                 VALUES (:usuario, :evento, :entidad, :id, :detalle)'
            );
            $stmt->execute([
                'usuario' => $usuarioId,
                'evento' => $evento,
                'entidad' => $entidad,
                'id' => $entidadId,
                'detalle' => $detalle !== '' ? $detalle : null,
            ]);

            $stmt = $this->db->prepare(
                'INSERT INTO audit_logs
                    (id_usuario_admin, nombre_completo, accion, entidad_afectada,
                     id_registro_modificado, ip_address, evento, detalle)
                 VALUES
                    (:usuario, :nombre, :accion, :entidad, :id, :ip, :evento, :detalle)'
            );
            $stmt->execute([
                'usuario' => $usuarioId,
                'nombre' => $actorName,
                'accion' => $accion ?? $this->inferirAccion($evento, $detalle),
                'entidad' => $entidad,
                'id' => $entidadId,
                'ip' => $ipAddress,
                'evento' => $evento,
                'detalle' => $detalle !== '' ? $detalle : null,
            ]);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function obtenerNombreCompleto(?int $usuarioId): string
    {
        if ($usuarioId === null) {
            return 'Usuario no identificado';
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', NULLIF(u.nombre, ''), NULLIF(u.apellidos, ''))), ''),
                NULLIF(TRIM(CONCAT_WS(' ', NULLIF(c.nombre, ''), NULLIF(c.apellidos, ''))), ''),
                NULLIF(TRIM(CONCAT_WS(' ', NULLIF(a.nombre, ''), NULLIF(a.apellidos, ''))), ''),
                NULLIF(e.nombre_empresa, ''),
                u.email,
                CONCAT('Usuario #', u.id)
             )
             FROM usuarios u
             LEFT JOIN coordinadores c ON c.usuario_id = u.id
             LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
             LEFT JOIN empresas e ON e.usuario_id = u.id AND u.rol = 'Empresa'
             WHERE u.id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $usuarioId]);
        $name = $stmt->fetchColumn();

        return is_string($name) && $name !== '' ? $name : 'Usuario #' . $usuarioId;
    }

    private function inferirAccion(string $evento, string $detalle): string
    {
        if (str_contains($evento, 'eliminado')) {
            return 'borrado';
        }
        if (str_contains($evento, 'revisada') || str_contains($evento, 'reenviada') || str_contains($evento, 'aprobada')) {
            return preg_match('/estado=(Rechazada|Rechazado)/u', $detalle) === 1 ? 'rechazo' : 'aprobación';
        }
        if (str_contains($evento, 'cread')) {
            return 'creación';
        }
        if (str_contains($evento, 'denegada')) {
            return 'acceso denegado';
        }
        return 'edición';
    }
}
