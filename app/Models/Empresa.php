<?php
// app/Models/Empresa.php

require_once __DIR__ . '/../../config/database.php';

class Empresa {
    private PDO $db;

    public function __construct() {
        $this->db = getPDOConnection();
    }

    public function crear(int $usuarioId, string $cif, string $nombreComercial, string $telefono, string $direccion, string $personaContacto): int {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO empresas (usuario_id, cif, nombre_comercial, telefono, direccion, persona_contacto) 
                 VALUES (:usuario_id, :cif, :nombre, :telefono, :direccion, :contacto)"
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'cif'        => $cif,
                'nombre'     => $nombreComercial,
                'telefono'   => $telefono,
                'direccion'  => $direccion,
                'contacto'   => $personaContacto
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al crear empresa: " . $e->getMessage());
            return 0;
        }
    }
}