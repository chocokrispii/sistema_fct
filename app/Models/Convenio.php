<?php

require_once __DIR__ . '/../../config/database.php';

class Convenio
{
    public function __construct(private PDO $db)
    {
    }

    public function crear(
        int $empresaId,
        int $especialidadId,
        ?int $sedeId,
        string $numero,
        string $firma,
        string $inicio,
        string $fin,
        string $ruta
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO convenios (empresa_id, especialidad_id, sede_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path)
             VALUES (:empresa, :especialidad, :sede, :numero, :firma, :inicio, :fin, :ruta)'
        );
        $stmt->execute([
            'empresa' => $empresaId,
            'especialidad' => $especialidadId,
            'sede' => $sedeId,
            'numero' => $numero,
            'firma' => $firma,
            'inicio' => $inicio,
            'fin' => $fin,
            'ruta' => $ruta,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function listar(?int $empresaId = null, ?string $estado = null): array
    {
        $sql = 'SELECT c.*, e.nombre_empresa, s.nombre AS nombre_sede,
                       es.nombre_especialidad
                FROM convenios c
                INNER JOIN empresas e ON e.id = c.empresa_id
                LEFT JOIN empresa_sedes s ON s.id = c.sede_id
                LEFT JOIN especialidades es ON es.id = c.especialidad_id';
        $where = [];
        $params = [];
        if ($empresaId !== null) {
            $where[] = 'c.empresa_id = :empresa_id';
            $params['empresa_id'] = $empresaId;
        }
        if ($estado !== null) {
            $where[] = 'c.estado = :estado';
            $params['estado'] = $estado;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.creado_en DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarParaObservador(): array
    {
        $stmt = $this->db->query(
            'SELECT e.nombre_empresa, es.nombre_especialidad, c.fecha_firma, c.fecha_inicio,
                    c.fecha_fin, c.estado
             FROM convenios c
             INNER JOIN empresas e ON e.id = c.empresa_id
             LEFT JOIN especialidades es ON es.id = c.especialidad_id
             ORDER BY c.creado_en DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM convenios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function resolver(int $id, string $estado, string $motivo, int $usuarioId): bool
    {
        if (!in_array($estado, ['Aprobado', 'Rechazado'], true)) {
            throw new InvalidArgumentException('Estado de revisión de convenio no válido.');
        }
        $stmt = $this->db->prepare(
            "UPDATE convenios SET estado = :estado, motivo_revision = :motivo,
                 revisado_por = :usuario, revisado_en = NOW()
             WHERE id = :id AND estado = 'Pendiente'"
        );
        $stmt->execute([
            'estado' => $estado,
            'motivo' => $motivo !== '' ? $motivo : null,
            'usuario' => $usuarioId,
            'id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }
}
