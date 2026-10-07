<?php
// app/Models/Empresa.php

require_once __DIR__ . '/../../config/database.php';

class Empresa {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function crear(
        int $usuarioId,
        string $cif,
        string $nombreEmpresa,
        string $telefono,
        string $direccion,
        string $personaContacto
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
                 VALUES (:usuario_id, :nombre, :cif, :contacto)'
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'cif'        => $cif,
                'nombre'     => $nombreEmpresa,
                'contacto'   => $personaContacto,
            ]);

            $empresaId = (int)$this->db->lastInsertId();
            $contacto = $this->db->prepare(
                'INSERT INTO direcciones_telefonos (empresa_id, telefono, direccion_completa)
                 VALUES (:empresa_id, :telefono, :direccion)'
            );
            $contacto->execute([
                'empresa_id' => $empresaId,
                'telefono' => $telefono,
                'direccion' => $direccion,
            ]);

            return $empresaId;
        } catch (PDOException $e) {
            error_log("Error al crear empresa: " . $e->getMessage());
            return 0;
        }
    }

    public function obtenerPorUsuarioId(int $usuarioId): ?array {
        try {
            $stmt = $this->db->prepare('SELECT * FROM empresas WHERE usuario_id = :usuario_id LIMIT 1');
            $stmt->execute(['usuario_id' => $usuarioId]);
            $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
            return $empresa ?: null;
        } catch (PDOException $e) {
            error_log("Error al buscar empresa: " . $e->getMessage());
            return null;
        }
    }
}
