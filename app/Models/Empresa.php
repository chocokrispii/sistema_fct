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

    public function tieneConvenioVigente(int $empresaId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM convenios
             WHERE empresa_id = :empresa_id AND estado = 'Aprobado'
               AND fecha_inicio <= CURDATE() AND fecha_fin >= CURDATE()
             LIMIT 1"
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        return (bool)$stmt->fetchColumn();
    }

    public function tieneConvenioParaFechas(int $empresaId, string $inicio, string $fin): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM convenios
             WHERE empresa_id = :empresa_id AND estado = 'Aprobado'
               AND fecha_inicio <= :inicio AND fecha_fin >= :fin
             LIMIT 1"
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'inicio' => $inicio,
            'fin' => $fin,
        ]);
        return (bool)$stmt->fetchColumn();
    }

    public function obtenerSedes(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, direccion, telefono FROM empresa_sedes
             WHERE empresa_id = :empresa_id AND activa = 1 ORDER BY nombre'
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crearSede(int $empresaId, string $nombre, string $direccion, string $telefono): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO empresa_sedes (empresa_id, nombre, direccion, telefono)
             VALUES (:empresa_id, :nombre, :direccion, :telefono)'
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'nombre' => $nombre,
            'direccion' => $direccion,
            'telefono' => $telefono !== '' ? $telefono : null,
        ]);
        return (int)$this->db->lastInsertId();
    }
}
