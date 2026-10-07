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
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
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
        try {
            $stmt = $this->db->prepare("SELECT * FROM usuarios WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            return $user ?: null;
        } catch (PDOException $e) {
            error_log("Error al buscar usuario: " . $e->getMessage());
            return null;
        }
    }
}
