<?php

require_once __DIR__ . '/Usuario.php';
require_once __DIR__ . '/Auditoria.php';

/**
 * No hay envío de correo. La persona solicita el cambio y un administrador
 * o coordinador verifica la identidad antes de emitir el enlace.
 */
class RecuperacionCuenta
{
    public const VALIDEZ_MINUTOS = 1440;

    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
    }

    public function solicitar(string $email, DateTimeImmutable $ahora): void
    {
        $usuario = (new Usuario($this->db))->obtenerPorEmail($email);
        if ($usuario === null) {
            return;
        }

        $abierta = $this->db->prepare(
            'SELECT id FROM recuperaciones_cuenta
             WHERE usuario_id = :usuario
               AND (estado = \'Solicitada\' OR (estado = \'EnlaceEmitido\' AND expira >= :ahora))
             LIMIT 1'
        );
        $abierta->execute(['usuario' => $usuario['id'], 'ahora' => $ahora->format('Y-m-d H:i:s')]);
        if ($abierta->fetch()) {
            return;
        }

        $insert = $this->db->prepare(
            'INSERT INTO recuperaciones_cuenta (usuario_id, estado, creada) VALUES (:usuario, \'Solicitada\', :creada)'
        );
        $insert->execute(['usuario' => $usuario['id'], 'creada' => $ahora->format('Y-m-d H:i:s')]);
    }

    public function pendientes(): array
    {
        return $this->db->query(
            'SELECT r.id, u.email, r.creada
             FROM recuperaciones_cuenta r
             JOIN usuarios u ON u.id = r.usuario_id
             WHERE r.estado = \'Solicitada\'
             ORDER BY r.creada'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function destinatario(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT u.email, COALESCE(NULLIF(e.nombre_empresa, ''), u.nombre, u.email) AS nombre
             FROM recuperaciones_cuenta r
             INNER JOIN usuarios u ON u.id = r.usuario_id
             LEFT JOIN empresas e ON e.usuario_id = u.id
             WHERE r.id = :id AND r.estado = 'EnlaceEmitido' LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function emitirEnlace(int $id, DateTimeImmutable $ahora, int $actorId): ?string
    {
        $this->db->beginTransaction();
        try {
            $fila = $this->db->prepare(
                'SELECT id FROM recuperaciones_cuenta WHERE id = :id AND estado = \'Solicitada\' FOR UPDATE'
            );
            $fila->execute(['id' => $id]);
            if (!$fila->fetch()) {
                $this->db->commit();
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $update = $this->db->prepare(
                'UPDATE recuperaciones_cuenta
                 SET estado = \'EnlaceEmitido\', token_hash = :hash, expira = :expira
                 WHERE id = :id'
            );
            $update->execute([
                'hash' => hash('sha256', $token),
                'expira' => $ahora->modify('+' . self::VALIDEZ_MINUTOS . ' minutes')->format('Y-m-d H:i:s'),
                'id' => $id,
            ]);
            (new Auditoria($this->db))->registrar(
                $actorId,
                'recuperacion.aprobada',
                'recuperaciones_cuenta',
                $id,
                'enlace de restablecimiento emitido',
                'aprobación'
            );
            $this->db->commit();
            return $token;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function vigente(string $token, DateTimeImmutable $ahora): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM recuperaciones_cuenta
             WHERE token_hash = :hash AND estado = \'EnlaceEmitido\' AND expira >= :ahora
             LIMIT 1'
        );
        $stmt->execute(['hash' => hash('sha256', $token), 'ahora' => $ahora->format('Y-m-d H:i:s')]);
        return (bool)$stmt->fetch();
    }

    public function consumir(string $token, string $password, DateTimeImmutable $ahora): ?string
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'SELECT r.id, r.usuario_id, u.email
                 FROM recuperaciones_cuenta r
                 JOIN usuarios u ON u.id = r.usuario_id
                 WHERE r.token_hash = :hash AND r.estado = \'EnlaceEmitido\' AND r.expira >= :ahora
                 FOR UPDATE'
            );
            $stmt->execute(['hash' => hash('sha256', $token), 'ahora' => $ahora->format('Y-m-d H:i:s')]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$fila) {
                $this->db->commit();
                return null;
            }

            (new Usuario($this->db))->cambiarContrasena((int)$fila['usuario_id'], $password);
            $update = $this->db->prepare(
                'UPDATE recuperaciones_cuenta SET estado = \'Usada\', token_hash = NULL WHERE id = :id'
            );
            $update->execute(['id' => $fila['id']]);
            $this->db->commit();
            return $fila['email'];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
