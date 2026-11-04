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
                "INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto, estado_solicitud)
                 VALUES (:usuario_id, :nombre, :cif, :contacto, 'Pendiente_Aprobacion')"
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

    public function crearImportada(int $usuarioId, string $cif, string $nombreEmpresa, string $personaContacto, int $adminId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto, estado_solicitud, aprobada_por, aprobada_en)
             VALUES (:usuario_id, :nombre, :cif, :contacto, 'Aprobada', :admin, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            'usuario_id' => $usuarioId,
            'nombre' => $nombreEmpresa,
            'cif' => $cif,
            'contacto' => $personaContacto,
            'admin' => $adminId,
        ]);
        $empresaId = (int)$this->db->lastInsertId();
        $direccion = $this->db->prepare(
            'INSERT INTO direcciones_telefonos (empresa_id, direccion_completa) VALUES (:empresa_id, :direccion)'
        );
        $direccion->execute(['empresa_id' => $empresaId, 'direccion' => 'Pendiente de completar por la empresa']);
        return $empresaId;
    }

    public function obtenerPorUsuarioId(int $usuarioId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM empresas WHERE usuario_id = :usuario_id LIMIT 1');
        $stmt->execute(['usuario_id' => $usuarioId]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
        return $empresa ?: null;
    }

    public function obtenerTodasParaCentro(): array
    {
        $stmt = $this->db->query(
            'SELECT e.id, e.nombre_empresa, e.cif_empresa, u.email, u.activo
             FROM empresas e
             INNER JOIN usuarios u ON u.id = e.usuario_id AND u.rol = \'Empresa\'
             ORDER BY e.nombre_empresa, e.id'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tieneConvenioParaFechas(int $empresaId, int $especialidadId, string $inicio, string $fin): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM convenios
             WHERE empresa_id = :empresa_id AND especialidad_id = :especialidad_id
               AND estado = 'Aprobado'
               AND fecha_inicio <= :inicio AND fecha_fin >= :fin
             LIMIT 1"
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'especialidad_id' => $especialidadId,
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

    public function obtenerSedeActiva(int $empresaId, int $sedeId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, nombre, direccion, telefono FROM empresa_sedes
             WHERE id = :id AND empresa_id = :empresa_id AND activa = 1'
        );
        $stmt->execute(['id' => $sedeId, 'empresa_id' => $empresaId]);
        $sede = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($sede) ? $sede : null;
    }

    public function obtenerContactoPrincipal(int $empresaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT telefono, direccion_completa
             FROM direcciones_telefonos
             WHERE empresa_id = :empresa_id
             ORDER BY es_principal DESC, id
             LIMIT 1'
        );
        $stmt->execute(['empresa_id' => $empresaId]);
        $contacto = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($contacto) ? $contacto : ['telefono' => '', 'direccion_completa' => ''];
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
