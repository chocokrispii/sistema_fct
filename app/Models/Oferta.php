<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';

class Oferta {
    private PDO $db;

    private const SELECT_BASE =
        'SELECT p.id, p.empresa_id, p.especialidad_id, p.plazas_cuantas, p.fecha_inicio, p.fecha_fin,
                e.nombre_empresa, es.nombre_especialidad
         FROM empresa_especialidades_plazas p
         INNER JOIN empresas e ON e.id = p.empresa_id
         INNER JOIN especialidades es ON es.id = p.especialidad_id';

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function crearOferta(int $empresaId, int $especialidadId, int $plazas, string $fechaInicio, string $fechaFin): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO empresa_especialidades_plazas (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin)
                 VALUES (:empresa_id, :especialidad_id, :plazas, :fecha_inicio, :fecha_fin)'
            );
            $stmt->execute([
                'empresa_id'      => $empresaId,
                'especialidad_id' => $especialidadId,
                'plazas'          => $plazas,
                'fecha_inicio'    => $fechaInicio,
                'fecha_fin'       => $fechaFin,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al crear oferta: " . $e->getMessage());
            return 0;
        }
    }

    public function obtenerPorEmpresa(int $empresaId): array {
        try {
            $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE p.empresa_id = :empresa_id ORDER BY p.fecha_inicio DESC');
            $stmt->execute(['empresa_id' => $empresaId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error al listar ofertas de la empresa: " . $e->getMessage());
            return [];
        }
    }

    public function obtenerTodas(bool $soloVigentes = false): array {
        try {
            $sql = self::SELECT_BASE
                . ($soloVigentes ? ' WHERE p.fecha_fin >= CURDATE()' : '')
                . ' ORDER BY p.fecha_inicio';
            return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error al listar ofertas: " . $e->getMessage());
            return [];
        }
    }
}
