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
        string $personaContacto,
        string $nombreComercial = '',
        string $representanteLegal = '',
        string $dniRepresentante = '',
        string $estado = 'Pendiente_Aprobacion'
    ): int {
        if (!in_array($estado, ['Pendiente_Aprobacion', 'Aprobada', 'Rechazada'], true)) {
            throw new InvalidArgumentException('El estado de aprobación de la empresa no es válido.');
        }

        try {
            $stmt = $this->db->prepare(
                'INSERT INTO empresas
                    (usuario_id, nombre_empresa, nombre_comercial, cif_empresa, persona_contacto,
                     representante_legal, dni_representante, estado)
                 VALUES
                    (:usuario_id, :nombre, :nombre_comercial, :cif, :contacto,
                     :representante, :dni_representante, :estado)'
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'cif'        => $cif,
                'nombre'     => $nombreEmpresa,
                'contacto'   => $personaContacto,
                'nombre_comercial' => $nombreComercial !== '' ? $nombreComercial : $nombreEmpresa,
                'representante' => $representanteLegal !== '' ? $representanteLegal : null,
                'dni_representante' => $dniRepresentante !== '' ? $dniRepresentante : null,
                'estado' => $estado,
            ]);

            $empresaId = (int)$this->db->lastInsertId();
            $contacto = $this->db->prepare(
                'INSERT INTO direcciones_telefonos (empresa_id, telefono, direccion_completa)
                 VALUES (:empresa_id, :telefono, :direccion)'
            );
            $contacto->execute([
                'empresa_id' => $empresaId,
                'telefono' => $telefono !== '' ? $telefono : null,
                'direccion' => $direccion,
            ]);

            return $empresaId;
        } catch (PDOException $e) {
            error_log("Error al crear empresa: " . $e->getMessage());
            return 0;
        }
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
            'SELECT e.id, e.nombre_empresa, e.nombre_comercial, e.cif_empresa, e.estado, u.email, u.activo
             FROM empresas e
             INNER JOIN usuarios u ON u.id = e.usuario_id AND u.rol = \'Empresa\'
             ORDER BY e.nombre_empresa, e.id'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarSolicitudes(string $estado): array
    {
        if (!in_array($estado, ['Pendiente_Aprobacion', 'Aprobada', 'Rechazada'], true)) {
            throw new InvalidArgumentException('El filtro de estado de la empresa no es válido.');
        }

        $stmt = $this->db->prepare(
            'SELECT e.id, e.usuario_id, e.nombre_empresa, e.nombre_comercial, e.cif_empresa,
                    e.persona_contacto, e.representante_legal, e.dni_representante, e.estado,
                    e.numero_convenio, e.pdf_convenio_path, e.fecha_aprobacion, u.email,
                    d.telefono, d.direccion_completa
             FROM empresas e
             INNER JOIN usuarios u ON u.id = e.usuario_id AND u.rol = \'Empresa\'
             LEFT JOIN direcciones_telefonos d ON d.id = (
                 SELECT MIN(primary_address.id) FROM direcciones_telefonos primary_address
                 WHERE primary_address.empresa_id = e.id AND primary_address.es_principal = 1
             )
             WHERE e.estado = :estado
             ORDER BY e.id DESC'
        );
        $stmt->execute(['estado' => $estado]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerSolicitudPorId(int $empresaId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT e.id, e.usuario_id, e.nombre_empresa, e.nombre_comercial, e.cif_empresa,
                    e.representante_legal, e.dni_representante, e.estado, e.numero_convenio,
                    e.pdf_convenio_path, e.fecha_aprobacion, u.email
             FROM empresas e
             INNER JOIN usuarios u ON u.id = e.usuario_id AND u.rol = \'Empresa\'
             WHERE e.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $empresaId]);
        $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
        return $empresa ?: null;
    }

    public function aprobarSolicitud(int $empresaId, string $numeroConvenio, string $pdfPath): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE empresas
             SET numero_convenio = :numero, pdf_convenio_path = :pdf_path,
                 estado = 'Aprobada', fecha_aprobacion = CURRENT_TIMESTAMP
             WHERE id = :id AND estado = 'Pendiente_Aprobacion'"
        );
        $stmt->execute([
            'numero' => $numeroConvenio,
            'pdf_path' => $pdfPath,
            'id' => $empresaId,
        ]);
        return $stmt->rowCount() === 1;
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
