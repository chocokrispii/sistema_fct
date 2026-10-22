<?php
// app/Models/Especialidad.php

require_once __DIR__ . '/../../config/database.php';

class Especialidad {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function obtenerTodas(bool $soloActivas = true): array {
        $where = $soloActivas ? ' WHERE activo = 1' : '';
        $stmt = $this->db->query(
            'SELECT id, nombre_especialidad, activo FROM especialidades' . $where . ' ORDER BY nombre_especialidad'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existe(int $id): bool {
        $stmt = $this->db->prepare('SELECT 1 FROM especialidades WHERE id = :id AND activo = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    public function crear(string $nombre): int {
        $nombre = trim($nombre);
        if (
            $nombre === ''
            || mb_strlen($nombre) > 150
            || preg_match('/[\p{Cc}]/u', $nombre) === 1
        ) {
            throw new InvalidArgumentException('El nombre del ciclo debe tener entre 1 y 150 caracteres válidos.');
        }

        $existing = $this->db->prepare(
            'SELECT 1 FROM especialidades WHERE nombre_especialidad = :nombre LIMIT 1'
        );
        $existing->execute(['nombre' => $nombre]);
        if ($existing->fetchColumn()) {
            throw new DomainException('Ya existe un ciclo formativo con ese nombre.');
        }

        $insert = $this->db->prepare(
            'INSERT INTO especialidades (nombre_especialidad) VALUES (:nombre)'
        );
        $insert->execute(['nombre' => $nombre]);

        return (int)$this->db->lastInsertId();
    }

    public function retirar(int $id): bool {
        $stmt = $this->db->prepare(
            'UPDATE especialidades SET activo = 0 WHERE id = :id AND activo = 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    public function reactivar(int $id): bool {
        $stmt = $this->db->prepare(
            'UPDATE especialidades SET activo = 1 WHERE id = :id AND activo = 0'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }
}
