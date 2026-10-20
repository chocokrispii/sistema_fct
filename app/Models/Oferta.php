<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';

class Oferta {
    private PDO $db;

    private const SELECT_BASE =
        'SELECT p.id, p.empresa_id, p.especialidad_id, p.plazas_cuantas, p.fecha_inicio, p.fecha_fin,
                p.estado, p.turno, p.titulo, p.descripcion, p.modalidad, p.ubicacion,
                p.requisitos, p.tecnologias, p.motivo_revision,
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
        string $titulo,
        string $descripcion,
        string $turno,
        string $modalidad,
        ?string $ubicacion,
        ?string $requisitos,
        ?string $tecnologias,
        array $horarios
    ): int {
        $stmt = $this->db->prepare(
            "INSERT INTO empresa_especialidades_plazas
                (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin,
                 titulo, descripcion, turno, modalidad, ubicacion, requisitos, tecnologias)
             VALUES (:empresa_id, :especialidad_id, :plazas, :fecha_inicio, :fecha_fin,
                 :titulo, :descripcion, :turno, :modalidad, :ubicacion, :requisitos, :tecnologias)"
        );
        $stmt->execute([
            'empresa_id' => $empresaId,
            'especialidad_id' => $especialidadId,
            'plazas' => $plazas,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'turno' => $turno,
            'modalidad' => $modalidad,
            'ubicacion' => $ubicacion,
            'requisitos' => $requisitos,
            'tecnologias' => $tecnologias,
        ]);
        $offerId = (int)$this->db->lastInsertId();
        $shift = $this->db->prepare(
            'INSERT INTO empresa_oferta_horarios_semanales (oferta_id, dia_semana, hora_inicio, hora_fin)
             VALUES (:oferta_id, :dia_semana, :hora_inicio, :hora_fin)'
        );
        foreach ($horarios as $horario) {
            $shift->execute([
                'oferta_id' => $offerId,
                'dia_semana' => $horario['dia_semana'],
                'hora_inicio' => $horario['hora_inicio'],
                'hora_fin' => $horario['hora_fin'],
            ]);
        }
        return $offerId;
    }

    public function obtenerHorarioSemanal(int $ofertaId): array
    {
        $stmt = $this->db->prepare(
            'SELECT dia_semana, hora_inicio, hora_fin
             FROM empresa_oferta_horarios_semanales
             WHERE oferta_id = :oferta_id ORDER BY dia_semana'
        );
        $stmt->execute(['oferta_id' => $ofertaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPorEmpresa(int $empresaId): array {
        $stmt = $this->db->prepare(self::SELECT_BASE . ' WHERE p.empresa_id = :empresa_id ORDER BY p.fecha_inicio DESC');
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerTodas(bool $soloVigentes = false): array {
        $sql = self::SELECT_BASE
            . ($soloVigentes
                ? " WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()
                    AND EXISTS (
                        SELECT 1 FROM empresa_oferta_horarios_semanales oh
                        WHERE oh.oferta_id = p.id
                    )"
                : '')
            . ' ORDER BY p.fecha_inicio';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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
        $sql = "SELECT a.id AS alumno_id, a.nombre, a.apellidos, a.correo
                FROM empresa_especialidades_plazas p
                INNER JOIN alumnos a ON a.especialidad_id = p.especialidad_id
                INNER JOIN alumno_disponibilidad d
                    ON d.alumno_id = a.id
                    AND d.fecha_inicio <= p.fecha_inicio
                    AND d.fecha_fin >= p.fecha_fin
                    AND EXISTS (
                        SELECT 1
                        FROM empresa_oferta_horarios_semanales oh
                        WHERE oh.oferta_id = p.id
                            AND (
                                DATEDIFF(p.fecha_fin, p.fecha_inicio) >= 6
                                OR MOD(oh.dia_semana - 1 - WEEKDAY(p.fecha_inicio) + 7, 7)
                                    <= DATEDIFF(p.fecha_fin, p.fecha_inicio)
                            )
                    )
                    AND (
                        d.es_indiferente = TRUE
                        OR NOT EXISTS (
                            SELECT 1 FROM empresa_oferta_horarios_semanales oh
                            WHERE oh.oferta_id = p.id
                                AND (
                                    DATEDIFF(p.fecha_fin, p.fecha_inicio) >= 6
                                    OR MOD(oh.dia_semana - 1 - WEEKDAY(p.fecha_inicio) + 7, 7)
                                        <= DATEDIFF(p.fecha_fin, p.fecha_inicio)
                                )
                                AND NOT EXISTS (
                                    SELECT 1 FROM alumno_disponibilidad_semanal ds
                                    WHERE ds.disponibilidad_id = d.id
                                        AND ds.dia_semana = oh.dia_semana
                                        AND ds.hora_inicio <= oh.hora_inicio
                                        AND ds.hora_fin >= oh.hora_fin
                                )
                        )
                    )
                WHERE p.id = :oferta_id
                    AND p.estado = 'Aprobada'
                    AND NOT EXISTS (
                        SELECT 1 FROM asignaciones_fct af
                        WHERE af.alumno_id = a.id AND af.estado = 'Asignada'
                    )
                GROUP BY a.id, a.nombre, a.apellidos, a.correo
                ORDER BY a.apellidos, a.nombre";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['oferta_id' => $ofertaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
