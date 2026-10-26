<?php

final class AuditLogService
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
    ): void {
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
            $safeDetail = $this->sanearDetalle($detalle);

            $legacyEntry = $this->db->prepare(
                'INSERT INTO auditoria (usuario_id, evento, entidad, entidad_id, detalle)
                 VALUES (:usuario, :evento, :entidad, :id, :detalle)'
            );
            $legacyEntry->execute([
                'usuario' => $usuarioId,
                'evento' => $evento,
                'entidad' => $entidad,
                'id' => $entidadId,
                'detalle' => $safeDetail,
            ]);

            $auditEntry = $this->db->prepare(
                'INSERT INTO audit_logs
                    (id_usuario_admin, nombre_completo, accion, entidad_afectada,
                     id_registro_modificado, ip_address, evento, detalle)
                 VALUES
                    (:usuario, :nombre, :accion, :entidad, :id, :ip, :evento, :detalle)'
            );
            $auditEntry->execute([
                'usuario' => $usuarioId,
                'nombre' => $actorName,
                'accion' => $accion ?? $this->inferirAccion($evento, $detalle),
                'entidad' => $entidad,
                'id' => $entidadId,
                'ip' => $ipAddress,
                'evento' => $evento,
                'detalle' => $safeDetail,
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
        if ($evento === 'usuario.estado_cambiado') {
            return 'cambio de estado';
        }
        if ($evento === 'ciclo.retirado') {
            return 'retirada de ciclo';
        }
        if ($evento === 'ciclo.reactivado') {
            return 'reactivación de ciclo';
        }
        if (str_contains($evento, 'activacion_reenviada') || str_contains($evento, 'activación_reenviada')) {
            return 'reenvío de activación';
        }
        if (str_contains($evento, 'revisad')) {
            if (preg_match('/(?:estado|decision)\s*=\s*(Rechazada|Rechazado)\b/ui', $detalle) === 1) {
                return 'rechazo';
            }
            if (preg_match('/(?:estado|decision)\s*=\s*(Aprobada|Aprobado)\b/ui', $detalle) === 1) {
                return 'aprobación';
            }
            return 'revisión';
        }
        if (str_contains($evento, 'eliminado')) {
            return 'borrado';
        }
        if (str_contains($evento, 'cread')) {
            return 'creación';
        }
        if (str_contains($evento, 'denegada')) {
            return 'acceso denegado';
        }
        return 'edición';
    }

    private function sanearDetalle(string $detalle): ?string
    {
        if ($detalle === '') {
            return null;
        }

        $detalle = preg_replace(
            '/\b(?:email|correo|nif|dni|nie|cif|password|contrase(?:ñ|n)a|token|secret|secreto|credential|credencial|nombre)\s*=\s*[^;,\s]*/iu',
            '[dato omitido]',
            $detalle
        ) ?? '';
        $detalle = preg_replace('/\bhttps?:\/\/\S+/iu', '[url omitida]', $detalle) ?? '';
        $detalle = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/iu', '[dato omitido]', $detalle) ?? '';
        if (preg_match('/\A.{0,500}/us', $detalle, $matches) === 1) {
            $detalle = $matches[0];
        } else {
            $detalle = '';
        }
        $detalle = trim($detalle);

        return $detalle !== '' ? $detalle : null;
    }
}
