<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';
require_once __DIR__ . '/../Core/AccountActivationMailer.php';
require_once __DIR__ . '/Auditoria.php';

final class SolicitudReenvioActivacion
{
    public function __construct(private PDO $db)
    {
    }

    public function solicitar(string $email, string $identifier): void
    {
        $email = mb_strtolower(trim($email));
        $identifier = SpanishDataValidator::normalizeDniNie($identifier);
        if (!SpanishDataValidator::isEmail($email) || !SpanishDataValidator::isDniNie($identifier)) {
            return;
        }

        $this->db->beginTransaction();
        try {
            $userQuery = $this->db->prepare(
                "SELECT u.id
                 FROM usuarios u
                 LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
                 WHERE u.email = :email
                   AND COALESCE(u.identificador_fiscal, a.dni) = :identifier
                   AND u.activo = 0
                   AND u.rol IN ('Alumno', 'Administrador', 'Coordinador', 'Observador')
                   AND (
                        (u.rol = 'Alumno' AND EXISTS (
                            SELECT 1 FROM student_activation_tokens t
                            WHERE t.usuario_id = u.id AND t.used_at IS NULL
                        ))
                        OR (u.rol IN ('Administrador', 'Coordinador', 'Observador') AND EXISTS (
                            SELECT 1 FROM staff_activation_tokens t
                            WHERE t.usuario_id = u.id AND t.used_at IS NULL
                        ))
                   )
                 LIMIT 1
                 FOR UPDATE"
            );
            $userQuery->execute(['email' => $email, 'identifier' => $identifier]);
            $userId = $userQuery->fetchColumn();
            if ($userId !== false) {
                $pendingQuery = $this->db->prepare(
                    "SELECT id FROM activation_resend_requests
                     WHERE usuario_id = :usuario_id
                       AND (estado = 'Pendiente de Reenvío'
                            OR creada >= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))
                     LIMIT 1 FOR UPDATE"
                );
                $pendingQuery->execute(['usuario_id' => $userId]);
                if ($pendingQuery->fetchColumn() === false) {
                    $insert = $this->db->prepare(
                        "INSERT INTO activation_resend_requests (usuario_id, estado)
                         VALUES (:usuario_id, 'Pendiente de Reenvío')"
                    );
                    $insert->execute(['usuario_id' => $userId]);
                }
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    public function pendientes(): array
    {
        return $this->db->query(
            "SELECT r.id AS solicitud_id, r.creada, u.id AS usuario_id, u.email, u.rol,
                    COALESCE(u.identificador_fiscal, a.dni) AS identificador_fiscal,
                    COALESCE(
                        NULLIF(CONCAT_WS(' ', u.nombre, u.apellidos), ''),
                        NULLIF(CONCAT_WS(' ', c.nombre, c.apellidos), ''),
                        NULLIF(CONCAT_WS(' ', a.nombre, a.apellidos), ''),
                        '—'
                    ) AS nombre_completo
             FROM activation_resend_requests r
             INNER JOIN usuarios u ON u.id = r.usuario_id
             LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
             LEFT JOIN coordinadores c ON c.usuario_id = u.id
             WHERE r.estado = 'Pendiente de Reenvío'
             ORDER BY r.creada, r.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function aprobar(int $requestId, int $adminId, string $appUrl): void
    {
        $appUrl = rtrim($appUrl, '/');
        if (filter_var($appUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('FCT_APP_URL debe contener una URL válida y accesible.');
        }

        $this->db->beginTransaction();
        try {
            $requestQuery = $this->db->prepare(
                "SELECT usuario_id FROM activation_resend_requests
                 WHERE id = :id AND estado = 'Pendiente de Reenvío'
                 FOR UPDATE"
            );
            $requestQuery->execute(['id' => $requestId]);
            $userId = $requestQuery->fetchColumn();
            if ($userId === false) {
                throw new DomainException('La solicitud ya se atendió o no existe.');
            }

            $userQuery = $this->db->prepare(
                "SELECT u.email, u.rol, u.activo,
                        COALESCE(
                            NULLIF(CONCAT_WS(' ', u.nombre, u.apellidos), ''),
                            NULLIF(CONCAT_WS(' ', c.nombre, c.apellidos), ''),
                            NULLIF(CONCAT_WS(' ', a.nombre, a.apellidos), ''),
                            'Usuario'
                        ) AS nombre_completo
                 FROM usuarios u
                 LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
                 LEFT JOIN coordinadores c ON c.usuario_id = u.id
                 WHERE u.id = :id
                 FOR UPDATE"
            );
            $userQuery->execute(['id' => $userId]);
            $user = $userQuery->fetch(PDO::FETCH_ASSOC);
            if (
                !is_array($user)
                || (int)$user['activo'] !== 0
                || !in_array($user['rol'], ['Alumno', 'Administrador', 'Coordinador', 'Observador'], true)
            ) {
                throw new DomainException('La cuenta ya está activada o no está disponible para reenvío.');
            }

            $table = $user['rol'] === 'Alumno' ? 'student_activation_tokens' : 'staff_activation_tokens';
            $pendingToken = $this->db->prepare(
                'SELECT id FROM ' . $table . '
                 WHERE usuario_id = :usuario_id AND used_at IS NULL
                 LIMIT 1 FOR UPDATE'
            );
            $pendingToken->execute(['usuario_id' => $userId]);
            if ($pendingToken->fetchColumn() === false) {
                throw new DomainException('La cuenta ya está activada o no tiene una activación pendiente.');
            }

            $invalidate = $this->db->prepare(
                'UPDATE ' . $table . '
                 SET used_at = CURRENT_TIMESTAMP
                 WHERE usuario_id = :usuario_id AND used_at IS NULL'
            );
            $invalidate->execute(['usuario_id' => $userId]);

            $token = bin2hex(random_bytes(32));
            $insertToken = $this->db->prepare(
                'INSERT INTO ' . $table . ' (usuario_id, token_hash, expires_at)
                 VALUES (:usuario_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
            );
            $insertToken->execute([
                'usuario_id' => $userId,
                'token_hash' => hash('sha256', $token),
            ]);

            $activationUrl = $appUrl . '/index.php?action=activar-cuenta&token=' . rawurlencode($token);
            (new AccountActivationMailer())->send($user['email'], $user['nombre_completo'], $activationUrl);

            $updateRequest = $this->db->prepare(
                "UPDATE activation_resend_requests SET estado = 'Reenviada', resuelta = CURRENT_TIMESTAMP
                 WHERE id = :id AND estado = 'Pendiente de Reenvío'"
            );
            $updateRequest->execute(['id' => $requestId]);
            if ($updateRequest->rowCount() !== 1) {
                throw new RuntimeException('No se pudo completar la aprobación de la solicitud.');
            }

            (new Auditoria($this->db))->registrar(
                $adminId,
                'cuenta.activacion_reenviada',
                'activation_resend_requests',
                $requestId,
                'usuario_id=' . (int)$userId
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
