<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';

class Oferta {
    private PDO $db;

    private const SELECT_BASE =
        'SELECT p.id, p.empresa_id, p.especialidad_id, p.plazas_cuantas, p.fecha_inicio, p.fecha_fin,
                p.estado, p.turno, p.motivo_revision,
                e.nombre_empresa, es.nombre_especialidad
         FROM empresa_especialidades_plazas p
         INNER JOIN empresas e ON e.id = p.empresa_id
         INNER JOIN especialidades es ON es.id = p.especialidad_id';

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function crearOferta(
        int $empresaId,
        int $especialidadId,
        int $plazas,
        string $fechaInicio,
        string $fechaFin,
        string $turno
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO empresa_especialidades_plazas (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, turno)
                 VALUES (:empresa_id, :especialidad_id, :plazas, :fecha_inicio, :fecha_fin, :turno)'
            );
            $stmt->execute([
                'empresa_id'      => $empresaId,
                'especialidad_id' => $especialidadId,
                'plazas'          => $plazas,
                'fecha_inicio'    => $fechaInicio,
                'fecha_fin'       => $fechaFin,
                'turno'           => $turno,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error al crear oferta: " . $e->getMessage());
            return 0;
        }
    }

    public function obtenerPorEmpresa(int $empresaId): array {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE p.empresa_id = :empresa_id ORDER BY p.fecha_inicio DESC');
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerTodas(bool $soloVigentes = false): array {
        $sql = self::SELECT_BASE
            . ($soloVigentes ? " WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()" : '')
            . ' ORDER BY p.fecha_inicio, p.id';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVigentesConAsignaciones(): array
    {
        $stmt = $this->db->query(
            "SELECT p.id, p.empresa_id, p.especialidad_id, p.plazas_cuantas, p.fecha_inicio, p.fecha_fin, p.turno,
                    e.nombre_empresa, es.nombre_especialidad,
                    COALESCE(conteo.asignadas, 0) AS asignadas
             FROM empresa_especialidades_plazas p
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             LEFT JOIN (
                 SELECT oferta_id, COUNT(*) AS asignadas
                 FROM asignaciones_fct
                 WHERE estado IN ('Asignada', 'Cerrada')
                 GROUP BY oferta_id
             ) conteo ON conteo.oferta_id = p.id
             WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()
             ORDER BY p.fecha_inicio, p.id"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPendientes(): array
    {
        $stmt = $this->db->query(self::SELECT_BASE . " WHERE p.estado = 'Pendiente' ORDER BY p.fecha_inicio");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function resolver(int $ofertaId, string $estado, string $motivo, int $revisorId): bool
    {
        if (!in_array($estado, ['Aprobada', 'Rechazada'], true)) {
            throw new InvalidArgumentException('Estado de revisión no válido.');
        }

        $stmt = $this->db->prepare(
            "UPDATE empresa_especialidades_plazas
             SET estado = :estado, motivo_revision = :motivo, revisado_por = :revisor, revisado_en = NOW()
             WHERE id = :id AND estado = 'Pendiente'"
        );
        $stmt->execute([
            'estado' => $estado,
            'motivo' => $motivo !== '' ? $motivo : null,
            'revisor' => $revisorId,
            'id' => $ofertaId,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function obtenerCandidatos(int $ofertaId): array
    {
        return $this->consultarCandidatos('p.id = :oferta_id', ['oferta_id' => $ofertaId]);
    }

    public function obtenerCandidatosVigentes(?int $usuarioId = null): array
    {
        $filter = 'p.fecha_fin >= CURDATE()';
        $params = [];
        if ($usuarioId !== null) {
            $filter .= ' AND a.usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $stmt = $this->consultarCandidatos(
            $filter,
            $params,
            true,
            true
        );
        $candidatesByOffer = [];
        foreach ($stmt as $candidate) {
            $offerId = (int)$candidate['oferta_id'];
            unset($candidate['oferta_id']);
            $candidatesByOffer[$offerId][] = $candidate;
        }

        return $candidatesByOffer;
    }

    public function obtenerIdsOfertasVigentesCompatibles(int $usuarioId): array
    {
        $candidates = $this->consultarCandidatos(
            'p.fecha_fin >= CURDATE() AND a.usuario_id = :usuario_id',
            ['usuario_id' => $usuarioId],
            true,
            true,
            true
        );

        return array_map(
            static fn(array $candidate): int => (int)$candidate['oferta_id'],
            $candidates
        );
    }

    private function consultarCandidatos(
        string $filter,
        array $params,
        bool $includeOfferId = false,
        bool $onlyOffersWithCapacity = false,
        bool $offerIdsOnly = false
    ): array
    {
        $conditions = [
            $filter,
            "p.estado = 'Aprobada'",
            "NOT EXISTS (
                SELECT 1 FROM asignaciones_fct af
                WHERE af.alumno_id = a.id AND af.estado = 'Asignada'
            )",
        ];
        if ($onlyOffersWithCapacity) {
            $conditions[] = 'COALESCE(capacidad.asignadas, 0) < p.plazas_cuantas';
        }

        $selectColumns = $offerIdsOnly
            ? 'p.id AS oferta_id'
            : ($includeOfferId ? 'p.id AS oferta_id, ' : '')
                . 'a.id AS alumno_id, a.nombre, a.apellidos, a.correo, d.turno';
        $groupColumns = $offerIdsOnly
            ? 'p.id'
            : ($includeOfferId ? 'p.id, ' : '')
                . 'a.id, a.nombre, a.apellidos, a.correo, d.turno';
        $capacityJoin = $onlyOffersWithCapacity
            ? "LEFT JOIN (
                    SELECT oferta_id, COUNT(*) AS asignadas
                    FROM asignaciones_fct
                    WHERE estado IN ('Asignada', 'Cerrada')
                    GROUP BY oferta_id
                ) capacidad ON capacidad.oferta_id = p.id"
            : '';
        $sql = "SELECT {$selectColumns}
                FROM empresa_especialidades_plazas p
                INNER JOIN alumnos a ON a.especialidad_id = p.especialidad_id
                INNER JOIN alumno_disponibilidad d
                    ON d.alumno_id = a.id
                    AND d.fecha_inicio <= p.fecha_inicio
                    AND d.fecha_fin >= p.fecha_fin
                    AND (d.turno = 'Indiferente' OR p.turno = 'Indiferente'
                         OR CAST(d.turno AS BINARY) = CAST(p.turno AS BINARY))
                    AND EXISTS (
                        SELECT 1
                        FROM alumno_disponibilidad_semanal ds
                        WHERE ds.disponibilidad_id = d.id
                            AND (
                                DATEDIFF(p.fecha_fin, p.fecha_inicio) >= 6
                                OR MOD(ds.dia_semana - 1 - WEEKDAY(p.fecha_inicio) + 7, 7)
                                    <= DATEDIFF(p.fecha_fin, p.fecha_inicio)
                            )
                            AND (
                                p.turno = 'Indiferente'
                                OR (p.turno = 'M' AND ds.hora_inicio < '14:00:00' AND ds.hora_fin > '06:00:00')
                                OR (p.turno = 'T' AND ds.hora_inicio < '23:59:59' AND ds.hora_fin > '14:00:00')
                            )
                    )
                {$capacityJoin}
                WHERE " . implode("\n                    AND ", $conditions) . "
                GROUP BY {$groupColumns}
                ORDER BY " . ($includeOfferId ? 'p.id' : 'a.apellidos, a.nombre')
                . ($offerIdsOnly ? '' : ($includeOfferId ? ', a.apellidos, a.nombre' : ''));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
