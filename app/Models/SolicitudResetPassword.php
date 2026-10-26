<?php

require_once __DIR__ . '/Usuario.php';

final class SolicitudResetPassword
{
    public const VALIDEZ_HORAS = 24;
    public const RETENCION_DIAS = 90;

    public function __construct(private PDO $db)
    {
    }

    public function solicitar(string $email, DateTimeImmutable $ahora): void
    {
        $this->limpiarAntiguas($ahora);
        $desde = $ahora->modify('-15 minutes')->format('Y-m-d H:i:s');
        $key = getenv('FCT_RESET_RATE_LIMIT_KEY');
        if (!is_string($key) || $key === '') {
            $key = DB_PASS !== '' ? DB_PASS : DB_HOST . '|' . DB_NAME;
        }
        $identificadorHash = hash_hmac('sha256', $email, $key);
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $ipHash = hash_hmac('sha256', $ip, $key);
        $ipLock = 'fctri-' . substr($ipHash, 0, 58);
        $identifierLock = 'fctru-' . substr($identificadorHash, 0, 58);
        if (!$this->adquirirBloqueo($ipLock)) {
            return;
        }
        if (!$this->adquirirBloqueo($identifierLock)) {
            $this->liberarBloqueo($ipLock);
            return;
        }
        try {
            $this->db->beginTransaction();
            $counts = $this->db->prepare(
                'SELECT
                    (SELECT COUNT(*) FROM intentos_reset_password
                     WHERE identificador_hash = :identificador AND solicitado_en >= :desde_id) AS por_identificador,
                    (SELECT COUNT(*) FROM intentos_reset_password
                     WHERE ip_hash = :ip AND solicitado_en >= :desde_ip) AS por_ip'
            );
            $counts->execute([
                'identificador' => $identificadorHash,
                'desde_id' => $desde,
                'ip' => $ipHash,
                'desde_ip' => $desde,
            ]);
            $limits = $counts->fetch(PDO::FETCH_ASSOC);
            $limited = is_array($limits)
                && ((int)$limits['por_identificador'] >= 5 || (int)$limits['por_ip'] >= 30);
            if ($limited) {
                $this->db->commit();
                $this->liberarBloqueo($identifierLock);
                $this->liberarBloqueo($ipLock);
                return;
            }
            $attempt = $this->db->prepare(
                'INSERT INTO intentos_reset_password (identificador_hash, ip_hash, solicitado_en)
                 VALUES (:identificador, :ip, :fecha)'
            );
            $attempt->execute([
                'identificador' => $identificadorHash,
                'ip' => $ipHash,
                'fecha' => $ahora->format('Y-m-d H:i:s'),
            ]);
            $cleanup = $this->db->prepare(
                'DELETE FROM intentos_reset_password WHERE solicitado_en < :limite'
            );
            $cleanup->execute(['limite' => $ahora->modify('-24 hours')->format('Y-m-d H:i:s')]);
            $this->db->commit();
            $this->liberarBloqueo($identifierLock);
            $this->liberarBloqueo($ipLock);
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->liberarBloqueo($identifierLock);
            $this->liberarBloqueo($ipLock);
            throw $exception;
        }

        $this->db->beginTransaction();
        try {
            $usuario = $this->db->prepare(
                "SELECT u.id, u.email
                 FROM usuarios u
                 LEFT JOIN alumnos a ON a.usuario_id = u.id
                 LEFT JOIN empresas e ON e.usuario_id = u.id
                 WHERE u.activo = 1 AND (
                    u.email = :email
                    OR UPPER(REPLACE(REPLACE(a.dni, '-', ''), ' ', '')) = :documento_alumno
                    OR UPPER(REPLACE(REPLACE(e.cif_empresa, '-', ''), ' ', '')) = :documento_empresa
                    OR UPPER(REPLACE(REPLACE(e.dni_representante, '-', ''), ' ', '')) = :documento_representante
                 )
                 LIMIT 1 FOR UPDATE"
            );
            $isEmail = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            $normalized = strtoupper((string)preg_replace('/[\s-]+/u', '', $email));
            $usuario->execute([
                'email' => $email,
                'documento_alumno' => $isEmail ? '' : $normalized,
                'documento_empresa' => $isEmail ? '' : $normalized,
                'documento_representante' => $isEmail ? '' : $normalized,
            ]);
            $filaUsuario = $usuario->fetch(PDO::FETCH_ASSOC);
            if (!is_array($filaUsuario)) {
                $this->db->commit();
                return;
            }

            $usuarioId = (int)$filaUsuario['id'];
            $this->expirarVencidas($ahora, $usuarioId);
            $activa = $this->db->prepare(
                "SELECT id FROM solicitudes_reset_password
                 WHERE usuario_id = :usuario_id
                   AND (estado = 'Pendiente_Aprobacion' OR (estado = 'Aprobada' AND expira_en > :ahora))
                 LIMIT 1"
            );
            $activa->execute([
                'usuario_id' => $usuarioId,
                'ahora' => $ahora->format('Y-m-d H:i:s'),
            ]);
            if (!$activa->fetchColumn()) {
                $insert = $this->db->prepare(
                    "INSERT INTO solicitudes_reset_password (usuario_id, email, estado)
                     VALUES (:usuario_id, :email, 'Pendiente_Aprobacion')"
                );
                $insert->execute([
                    'usuario_id' => $usuarioId,
                    'email' => $filaUsuario['email'],
                ]);
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function pendientes(DateTimeImmutable $ahora): array
    {
        $this->limpiarAntiguas($ahora);
        return $this->db->query(
            "SELECT id, usuario_id, email, solicitado_en
             FROM solicitudes_reset_password
             WHERE estado = 'Pendiente_Aprobacion'
             ORDER BY solicitado_en, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aprobar(int $id, int $adminId, ?string $ip = null): ?array
    {
        $this->db->beginTransaction();
        try {
            $request = $this->db->prepare(
                "SELECT id, usuario_id, email
                 FROM solicitudes_reset_password
                 WHERE id = :id AND estado = 'Pendiente_Aprobacion'
                 FOR UPDATE"
            );
            $request->execute(['id' => $id]);
            $row = $request->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            $lockUser = $this->db->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
            $lockUser->execute(['id' => $row['usuario_id']]);
            if ($lockUser->fetchColumn() === false) {
                $this->db->rollBack();
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $expiryExpression = 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL '
                . self::VALIDEZ_HORAS . ' HOUR)';
            $invalidatePriorTokens = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Expirada', token = NULL, expira_en = NULL
                 WHERE usuario_id = :usuario_id AND id <> :id AND estado = 'Aprobada'"
            );
            $invalidatePriorTokens->execute([
                'usuario_id' => $row['usuario_id'],
                'id' => $id,
            ]);
            $update = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Aprobada',
                     token = :token,
                     expira_en = {$expiryExpression},
                     aprobado_en = CURRENT_TIMESTAMP,
                     aprobado_por = :admin_id
                 WHERE id = :id AND estado = 'Pendiente_Aprobacion'"
            );
            $update->execute([
                'token' => hash('sha256', $token),
                'admin_id' => $adminId,
                'id' => $id,
            ]);
            if ($update->rowCount() !== 1) {
                $this->db->rollBack();
                return null;
            }

            $this->registrarAuditoria(
                $adminId,
                (int)$row['usuario_id'],
                'APROBAR_RECUPERACION_PASSWORD',
                $ip
            );
            $this->db->commit();
            return [
                'id' => (int)$row['id'],
                'usuario_id' => (int)$row['usuario_id'],
                'email' => (string)$row['email'],
                'token' => $token,
            ];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function reabrirSiNoSeEnvio(int $id, string $token, int $adminId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE solicitudes_reset_password
             SET estado = 'Pendiente_Aprobacion', token = NULL, expira_en = NULL,
                 aprobado_en = NULL, aprobado_por = NULL
             WHERE id = :id AND aprobado_por = :admin_id
               AND estado = 'Aprobada' AND token = :token"
        );
        $stmt->execute([
            'id' => $id,
            'admin_id' => $adminId,
            'token' => hash('sha256', $token),
        ]);
        return $stmt->rowCount() === 1;
    }

    public function rechazar(int $id, int $adminId, ?string $ip = null): bool
    {
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(
                "SELECT usuario_id FROM solicitudes_reset_password
                 WHERE id = :id AND estado = 'Pendiente_Aprobacion' FOR UPDATE"
            );
            $select->execute(['id' => $id]);
            $usuarioId = $select->fetchColumn();
            if ($usuarioId === false) {
                $this->db->commit();
                return false;
            }
            $stmt = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Rechazada', token = NULL, expira_en = NULL,
                     aprobado_en = NULL, aprobado_por = NULL
                 WHERE id = :id AND estado = 'Pendiente_Aprobacion'"
            );
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            $this->registrarAuditoria($adminId, (int)$usuarioId, 'RECHAZAR_RECUPERACION_PASSWORD', $ip);
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function vigente(string $token, DateTimeImmutable $ahora): bool
    {
        $this->expirarVencidas($ahora);
        $stmt = $this->db->prepare(
            "SELECT id FROM solicitudes_reset_password
             WHERE token = :token AND estado = 'Aprobada' AND expira_en > :ahora
             LIMIT 1"
        );
        $stmt->execute([
            'token' => hash('sha256', $token),
            'ahora' => $ahora->format('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function consumir(string $token, string $password, DateTimeImmutable $ahora): ?string
    {
        $this->db->beginTransaction();
        try {
            $this->expirarVencidas($ahora);
            $request = $this->db->prepare(
                "SELECT r.id, r.usuario_id, r.email
                 FROM solicitudes_reset_password r
                 WHERE r.token = :token AND r.estado = 'Aprobada' AND r.expira_en > :ahora
                 LIMIT 1 FOR UPDATE"
            );
            $request->execute([
                'token' => hash('sha256', $token),
                'ahora' => $ahora->format('Y-m-d H:i:s'),
            ]);
            $row = $request->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            (new Usuario($this->db))->cambiarContrasena((int)$row['usuario_id'], $password, true);
            $invalidateOthers = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Expirada', token = NULL, expira_en = NULL
                 WHERE usuario_id = :usuario_id AND id <> :id
                   AND estado IN ('Pendiente_Aprobacion', 'Aprobada')"
            );
            $invalidateOthers->execute([
                'usuario_id' => $row['usuario_id'],
                'id' => $row['id'],
            ]);
            $consume = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Usada', token = NULL
                 WHERE id = :id AND estado = 'Aprobada'"
            );
            $consume->execute(['id' => $row['id']]);
            $this->db->commit();
            return (string)$row['email'];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function limpiarAntiguas(DateTimeImmutable $ahora): void
    {
        $this->expirarVencidas($ahora);
        $limite = $ahora->modify('-' . self::RETENCION_DIAS . ' days')->format('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            "DELETE FROM solicitudes_reset_password
             WHERE solicitado_en < :limite
               AND estado IN ('Rechazada', 'Expirada', 'Usada')"
        );
        $stmt->execute(['limite' => $limite]);
    }

    private function expirarVencidas(DateTimeImmutable $ahora, ?int $usuarioId = null): void
    {
        $sql = "UPDATE solicitudes_reset_password
                SET estado = 'Expirada', token = NULL
                WHERE estado = 'Aprobada' AND expira_en <= :ahora";
        $params = ['ahora' => $ahora->format('Y-m-d H:i:s')];
        if ($usuarioId !== null) {
            $sql .= ' AND usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function registrarAuditoria(
        int $adminId,
        int $usuarioId,
        string $accion,
        ?string $ip = null
    ): void {
        $admin = $this->db->prepare(
            "SELECT COALESCE(
                NULLIF(TRIM(CONCAT_WS(' ', c.nombre, c.apellidos)), ''),
                u.email
             )
             FROM usuarios u
             LEFT JOIN coordinadores c ON c.usuario_id = u.id
             WHERE u.id = :admin_id AND u.rol = 'Administrador' AND u.activo = 1"
        );
        $admin->execute(['admin_id' => $adminId]);
        $nombre = $admin->fetchColumn();
        if (!is_string($nombre) || $nombre === '') {
            throw new DomainException('No se pudo identificar al administrador para auditar la decisión.');
        }
        $audit = $this->db->prepare(
            'INSERT INTO audit_logs
                (id_usuario_admin, nombre_completo, accion, entidad_afectada,
                 id_registro_modificado, ip_address)
             VALUES (:admin_id, :nombre, :accion, \'usuario\', :usuario_id, :ip)'
        );
        $audit->execute([
            'admin_id' => $adminId,
            'nombre' => $nombre,
            'accion' => $accion,
            'usuario_id' => $usuarioId,
            'ip' => $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
        ]);
    }

    private function adquirirBloqueo(string $nombre): bool
    {
        $stmt = $this->db->prepare('SELECT GET_LOCK(:nombre, 5)');
        $stmt->execute(['nombre' => $nombre]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function liberarBloqueo(string $nombre): void
    {
        try {
            $stmt = $this->db->prepare('SELECT RELEASE_LOCK(:nombre)');
            $stmt->execute(['nombre' => $nombre]);
            if ((int)$stmt->fetchColumn() !== 1) {
                error_log('No se pudo liberar el bloqueo de serialización de solicitudes de restablecimiento.');
            }
        } catch (Throwable $exception) {
            error_log(
                'Falló la liberación de un bloqueo de solicitudes de restablecimiento: '
                . get_class($exception)
            );
        }
    }
}
