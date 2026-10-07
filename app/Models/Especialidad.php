<?php
// app/Models/Especialidad.php

require_once __DIR__ . '/../../config/database.php';

class Especialidad {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function obtenerTodas(): array {
        try {
            $stmt = $this->db->query(
                'SELECT id, nombre_especialidad FROM especialidades ORDER BY nombre_especialidad'
            );
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error al listar especialidades: " . $e->getMessage());
            return [];
        }
    }

    public function existe(int $id): bool {
        try {
            $stmt = $this->db->prepare('SELECT 1 FROM especialidades WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Error al comprobar especialidad: " . $e->getMessage());
            return false;
        }
    }
}
