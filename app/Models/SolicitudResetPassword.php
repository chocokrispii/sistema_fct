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
        $this->db->beginTransaction();
        try {
            $usuario = $this->db->prepare(
                'SELECT id, email FROM usuarios WHERE email = :email LIMIT 1 FOR UPDATE'
            );
            $usuario->execute(['email' => $email]);
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
                   AND (estado = 'Pendiente' OR (estado = 'Aprobada' AND expira_en > :ahora))
                 LIMIT 1"
            );
            $activa->execute([
                'usuario_id' => $usuarioId,
                'ahora' => $ahora->format('Y-m-d H:i:s'),
            ]);
            if (!$activa->fetchColumn()) {
                $insert = $this->db->prepare(
                    "INSERT INTO solicitudes_reset_password (usuario_id, email, estado)
                     VALUES (:usuario_id, :email, 'Pendiente')"
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
             WHERE estado = 'Pendiente'
             ORDER BY solicitado_en, id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aprobar(int $id, int $adminId): ?array
    {
        $this->db->beginTransaction();
        try {
            $request = $this->db->prepare(
                "SELECT id, usuario_id, email
                 FROM solicitudes_reset_password
                 WHERE id = :id AND estado = 'Pendiente'
                 FOR UPDATE"
            );
            $request->execute(['id' => $id]);
            $row = $request->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->db->commit();
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $update = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Aprobada',
                     token = :token,
                     expira_en = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR),
                     aprobado_en = CURRENT_TIMESTAMP,
                     aprobado_por = :admin_id
                 WHERE id = :id AND estado = 'Pendiente'"
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
             SET estado = 'Pendiente', token = NULL, expira_en = NULL,
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

    public function rechazar(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE solicitudes_reset_password
             SET estado = 'Rechazada', token = NULL, expira_en = NULL,
                 aprobado_en = NULL, aprobado_por = NULL
             WHERE id = :id AND estado = 'Pendiente'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() === 1;
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

            (new Usuario($this->db))->cambiarContrasena((int)$row['usuario_id'], $password);
            $invalidateOthers = $this->db->prepare(
                "UPDATE solicitudes_reset_password
                 SET estado = 'Expirada', token = NULL, expira_en = NULL
                 WHERE usuario_id = :usuario_id AND id <> :id
                   AND estado IN ('Pendiente', 'Aprobada')"
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
}
