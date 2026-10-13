<?php
// app/Models/Usuario.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Auditoria.php';

class Usuario {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function crear(string $email, string $password, string $rol): int {
        try {
            $passwordHash = $this->hashPassword($password);
            $stmt = $this->db->prepare(
                "INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :password, :rol)"
            );
            $stmt->execute([
                'email'    => $email,
                'password' => $passwordHash,
                'rol'      => $rol
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al crear usuario: " . $e->getMessage());
            return 0;
        }
    }

    public function obtenerPorEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function obtenerTodos(): array
    {
        $stmt = $this->db->query(
            "SELECT u.id, u.email, u.rol, u.activo, u.fecha_creacion,
                    COALESCE(NULLIF(CONCAT_WS(' ', c.nombre, c.apellidos), ''), '—') AS nombre_completo
             FROM usuarios u
             LEFT JOIN coordinadores c ON c.usuario_id = u.id
             ORDER BY u.rol, u.email"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crearCuentaStaff(string $email, string $password, string $rol): int
    {
        if (!in_array($rol, ['Administrador', 'Coordinador', 'Observador'], true)) {
            throw new InvalidArgumentException('Solo se pueden crear cuentas de personal del centro.');
        }
        $passwordHash = $this->hashPassword($password);
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :rol)'
        );
        $stmt->execute([
            'email' => $email,
            'hash' => $passwordHash,
            'rol' => $rol,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function cambiarContrasena(int $id, string $password): void
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET password_hash = :hash WHERE id = :id');
        $stmt->execute(['hash' => $this->hashPassword($password), 'id' => $id]);
    }

    private function hashPassword(string $password): string
    {
        if (trim($password) === '') {
            throw new InvalidArgumentException('La contraseña no puede estar formada solo por espacios.');
        }
        if (strlen($password) < 12 || strlen($password) > 72) {
            throw new InvalidArgumentException('La contraseña debe tener entre 12 y 72 caracteres.');
        }
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public function cambiarActivo(int $id, bool $activo): bool
    {
        $stmt = $this->db->prepare('UPDATE usuarios SET activo = :activo WHERE id = :id AND activo <> :estado');
        $stmt->execute(['activo' => $activo ? 1 : 0, 'estado' => $activo ? 1 : 0, 'id' => $id]);
        return $stmt->rowCount() === 1;
    }

    public function eliminarPermanentemente(int $id, int $actorId): void
    {
        if ($id === $actorId) {
            throw new DomainException('No puedes eliminar tu propia cuenta.');
        }

        $this->db->beginTransaction();
        try {
            $actor = $this->db->prepare(
                "SELECT id FROM usuarios WHERE id = :id AND rol = 'Administrador' AND activo = 1 FOR UPDATE"
            );
            $actor->execute(['id' => $actorId]);
            if ($actor->fetchColumn() === false) {
                throw new DomainException('Solo un administrador activo puede eliminar usuarios.');
            }
            $select = $this->db->prepare('SELECT id, email, rol, activo FROM usuarios WHERE id = :id FOR UPDATE');
            $select->execute(['id' => $id]);
            $user = $select->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new DomainException('La cuenta ya no existe.');
            }
            if ($user['rol'] === 'Administrador' && (int)$user['activo'] === 1) {
                $admins = $this->db->query(
                    "SELECT id FROM usuarios WHERE rol = 'Administrador' AND activo = 1 FOR UPDATE"
                )->fetchAll(PDO::FETCH_COLUMN);
                if (count($admins) <= 1) {
                    throw new DomainException('No se puede eliminar el último administrador activo.');
                }
            }

            if ($user['rol'] === 'Alumno') {
                $usage = $this->db->prepare(
                    'SELECT COUNT(*) FROM alumnos a
                     INNER JOIN asignaciones_fct af ON af.alumno_id = a.id
                     WHERE a.usuario_id = :id'
                );
                $usage->execute(['id' => $id]);
                if ((int)$usage->fetchColumn() > 0) {
                    throw new DomainException('No se puede eliminar un alumno que tenga prácticas registradas. Desactiva la cuenta para conservar su historial.');
                }
            } elseif ($user['rol'] === 'Empresa') {
                $usage = $this->db->prepare(
                    'SELECT
                        (SELECT COUNT(*) FROM empresas e
                         INNER JOIN empresa_especialidades_plazas p ON p.empresa_id = e.id
                         WHERE e.usuario_id = :ofertas)
                        +
                        (SELECT COUNT(*) FROM empresas e
                         INNER JOIN convenios c ON c.empresa_id = e.id
                         WHERE e.usuario_id = :convenios) AS total'
                );
                $usage->execute(['ofertas' => $id, 'convenios' => $id]);
                if ((int)$usage->fetchColumn() > 0) {
                    throw new DomainException('No se puede eliminar una empresa con ofertas o convenios. Desactiva la cuenta para conservar el historial.');
                }
            }

            $assignedActivities = $this->db->prepare('SELECT COUNT(*) FROM asignaciones_fct WHERE asignado_por = :id');
            $assignedActivities->execute(['id' => $id]);
            if ((int)$assignedActivities->fetchColumn() > 0) {
                throw new DomainException('No se puede eliminar una cuenta que ha asignado prácticas. Desactívala para conservar la autoría.');
            }

            $graded = $this->db->prepare('SELECT COUNT(*) FROM calificaciones_fct WHERE calificador_id = :id');
            $graded->execute(['id' => $id]);
            if ((int)$graded->fetchColumn() > 0) {
                throw new DomainException('No se puede eliminar una cuenta que ha registrado calificaciones oficiales. Desactívala para conservar la autoría.');
            }

            $auditEntries = $this->db->prepare('SELECT COUNT(*) FROM auditoria WHERE usuario_id = :id');
            $auditEntries->execute(['id' => $id]);
            if ((int)$auditEntries->fetchColumn() > 0) {
                throw new DomainException('No se puede eliminar una cuenta con operaciones auditadas. Desactívala para conservar la trazabilidad.');
            }

            (new Auditoria($this->db))->registrar(
                $actorId,
                'usuario.eliminado',
                'usuarios',
                $id,
                'email=' . $user['email'] . '; rol=' . $user['rol']
            );
            $delete = $this->db->prepare('DELETE FROM usuarios WHERE id = :id');
            $delete->execute(['id' => $id]);
            if ($delete->rowCount() !== 1) {
                throw new DomainException('La cuenta no pudo eliminarse.');
            }
            $this->db->commit();
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
