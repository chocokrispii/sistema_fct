<?php
// app/Models/Usuario.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/../Core/SpanishDataValidator.php';

class Usuario {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function crear(string $email, string $password, string $rol, bool $activo = true): int {
        try {
            $passwordHash = $this->hashPassword($password);
            $stmt = $this->db->prepare(
                "INSERT INTO usuarios (email, password_hash, rol, activo) VALUES (:email, :password, :rol, :activo)"
            );
            $stmt->execute([
                'email'    => $email,
                'password' => $passwordHash,
                'rol'      => $rol,
                'activo'   => $activo ? 1 : 0,
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

    public function obtenerPorIdentificador(string $identificador): ?array
    {
        $identificador = SpanishDataValidator::normalizeFiscalIdentifier($identificador);
        if ($identificador === '') {
            return null;
        }

        $stmt = $this->db->prepare(
            "SELECT u.*
             FROM usuarios u
             LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
             LEFT JOIN empresas e ON e.usuario_id = u.id AND u.rol = 'Empresa'
             WHERE (u.rol IN ('Administrador', 'Coordinador', 'Observador')
                    AND UPPER(REPLACE(REPLACE(REPLACE(u.identificador_fiscal, ' ', ''), '-', ''), '.', '')) = :staff_nif)
                OR (u.rol = 'Alumno'
                    AND UPPER(REPLACE(REPLACE(REPLACE(a.dni, ' ', ''), '-', ''), '.', '')) = :dni)
                OR (u.rol = 'Empresa'
                    AND UPPER(REPLACE(REPLACE(REPLACE(e.cif_empresa, ' ', ''), '-', ''), '.', '')) = :cif)
             LIMIT 2"
        );
        $stmt->execute([
            'staff_nif' => $identificador,
            'dni' => $identificador,
            'cif' => $identificador,
        ]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return count($users) === 1 ? $users[0] : null;
    }

    public function obtenerTodos(): array
    {
        $stmt = $this->db->query(
            "SELECT u.id, u.email, COALESCE(u.identificador_fiscal, a.dni, e.cif_empresa) AS identificador_fiscal,
                    u.rol, u.activo, u.fecha_creacion,
                    COALESCE(
                        NULLIF(CONCAT_WS(' ', u.nombre, u.apellidos), ''),
                        NULLIF(CONCAT_WS(' ', c.nombre, c.apellidos), ''),
                        '—'
                    ) AS nombre_completo
             FROM usuarios u
             LEFT JOIN coordinadores c ON c.usuario_id = u.id
             LEFT JOIN alumnos a ON a.usuario_id = u.id AND u.rol = 'Alumno'
             LEFT JOIN empresas e ON e.usuario_id = u.id AND u.rol = 'Empresa'
             ORDER BY u.rol, u.email"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crearCuentaStaff(string $email, string $rol, string $nif, string $nombre, string $apellidos): int
    {
        if (!in_array($rol, ['Administrador', 'Coordinador', 'Observador'], true)) {
            throw new InvalidArgumentException('Solo se pueden crear cuentas de personal del centro.');
        }
        $email = trim($email);
        $nombre = trim($nombre);
        $apellidos = trim($apellidos);
        if (
            !SpanishDataValidator::isEmail($email)
            || !SpanishDataValidator::isPersonName($nombre, 50)
            || !SpanishDataValidator::isPersonName($apellidos, 100)
        ) {
            throw new InvalidArgumentException('Indica un correo, nombre y apellidos válidos para el personal.');
        }
        $nif = $this->validarNifPersonal($nif);
        $this->assertIdentificadorFiscalDisponible($nif);
        $passwordHash = $this->hashPassword(bin2hex(random_bytes(32)));
        $stmt = $this->db->prepare(
            'INSERT INTO usuarios (email, identificador_fiscal, nombre, apellidos, password_hash, rol, activo)
             VALUES (:email, :nif, :nombre, :apellidos, :hash, :rol, 0)'
        );
        $stmt->execute([
            'email' => $email,
            'nif' => $nif,
            'nombre' => $nombre,
            'apellidos' => $apellidos,
            'hash' => $passwordHash,
            'rol' => $rol,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function asignarNifPersonal(int $usuarioId, string $nif): void
    {
        $nif = $this->validarNifPersonal($nif);
        $user = $this->db->prepare(
            "SELECT rol FROM usuarios WHERE id = :id AND rol IN ('Administrador', 'Coordinador', 'Observador')"
        );
        $user->execute(['id' => $usuarioId]);
        if ($user->fetchColumn() === false) {
            throw new DomainException('Solo se puede asignar un NIF a una cuenta del personal del centro.');
        }

        $this->assertIdentificadorFiscalDisponible($nif, $usuarioId);
        $update = $this->db->prepare(
            "UPDATE usuarios SET identificador_fiscal = :nif
             WHERE id = :id AND rol IN ('Administrador', 'Coordinador', 'Observador')"
        );
        $update->execute(['nif' => $nif, 'id' => $usuarioId]);
    }

    public function actualizarCifEmpresa(int $usuarioId, string $cif): void
    {
        $cif = SpanishDataValidator::normalizeFiscalIdentifier($cif);
        if (!SpanishDataValidator::isCifFormat($cif)) {
            throw new InvalidArgumentException('Introduce un CIF con formato válido.');
        }

        $user = $this->db->prepare(
            "SELECT id FROM usuarios WHERE id = :id AND rol = 'Empresa'"
        );
        $user->execute(['id' => $usuarioId]);
        if ($user->fetchColumn() === false) {
            throw new DomainException('Solo se puede asignar un CIF a una cuenta de empresa.');
        }

        $this->assertIdentificadorFiscalDisponible($cif, $usuarioId);
        $update = $this->db->prepare(
            'UPDATE empresas SET cif_empresa = :cif WHERE usuario_id = :id'
        );
        $update->execute(['cif' => $cif, 'id' => $usuarioId]);
    }

    private function validarNifPersonal(string $nif): string
    {
        $nif = SpanishDataValidator::normalizeDniNie($nif);
        if (!SpanishDataValidator::isDniNie($nif)) {
            throw new InvalidArgumentException('Introduce un NIF, DNI o NIE válido para el personal.');
        }
        return $nif;
    }

    public function assertIdentificadorFiscalDisponible(string $identifier, ?int $excludeUserId = null): void
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM (
                SELECT u.id FROM usuarios u
                WHERE u.identificador_fiscal IS NOT NULL
                  AND UPPER(REPLACE(REPLACE(REPLACE(u.identificador_fiscal, ' ', ''), '-', ''), '.', '')) = :staff_nif
                  AND (:staff_exclude IS NULL OR u.id <> :staff_exclude_id)
                UNION ALL
                SELECT a.usuario_id FROM alumnos a
                WHERE UPPER(REPLACE(REPLACE(REPLACE(a.dni, ' ', ''), '-', ''), '.', '')) = :student_nif
                  AND (:student_exclude IS NULL OR a.usuario_id <> :student_exclude_id)
                UNION ALL
                SELECT e.usuario_id FROM empresas e
                WHERE UPPER(REPLACE(REPLACE(REPLACE(e.cif_empresa, ' ', ''), '-', ''), '.', '')) = :company_cif
                  AND (:company_exclude IS NULL OR e.usuario_id <> :company_exclude_id)
            ) AS identifiers"
        );
        $stmt->execute([
            'staff_nif' => $identifier,
            'staff_exclude' => $excludeUserId,
            'staff_exclude_id' => $excludeUserId,
            'student_nif' => $identifier,
            'student_exclude' => $excludeUserId,
            'student_exclude_id' => $excludeUserId,
            'company_cif' => $identifier,
            'company_exclude' => $excludeUserId,
            'company_exclude_id' => $excludeUserId,
        ]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new DomainException('Ese identificador fiscal ya está asociado a otra cuenta.');
        }
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
