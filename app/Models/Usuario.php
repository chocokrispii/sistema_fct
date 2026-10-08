<?php
// app/Models/Usuario.php

require_once __DIR__ . '/../../config/database.php';

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
            'SELECT id, email, rol, activo, fecha_creacion FROM usuarios ORDER BY rol, email'
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
}
