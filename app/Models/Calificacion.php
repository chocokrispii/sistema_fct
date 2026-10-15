<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Auditoria.php';

class Calificacion
{
    public function __construct(private PDO $db)
    {
    }

    public function listar(bool $soloPropias = false, int $usuarioId = 0): array
    {
        $sql = "SELECT af.id AS asignacion_id, af.estado AS estado_asignacion,
                       af.fecha_asignacion, af.fecha_cierre, af.tutor_empresa,
                       a.nombre AS alumno_nombre, a.apellidos, a.correo AS alumno_correo,
                       e.nombre_empresa, es.nombre_especialidad, p.fecha_inicio, p.fecha_fin,
                       c.nota, c.observaciones, c.calificada_en, c.actualizada_en
                FROM asignaciones_fct af
                INNER JOIN alumnos a ON a.id = af.alumno_id
                INNER JOIN usuarios u ON u.id = a.usuario_id
                INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
                INNER JOIN empresas e ON e.id = p.empresa_id
                INNER JOIN especialidades es ON es.id = p.especialidad_id
                LEFT JOIN calificaciones_fct c ON c.asignacion_id = af.id";
        $params = [];
        if ($soloPropias) {
            $sql .= ' WHERE u.id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $sql .= ' ORDER BY af.fecha_cierre DESC, a.apellidos, a.nombre';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerNotaMediaPorUsuario(int $usuarioId): ?float
    {
        $stmt = $this->db->prepare(
            'SELECT AVG(c.nota)
             FROM calificaciones_fct c
             INNER JOIN asignaciones_fct af ON af.id = c.asignacion_id
             INNER JOIN alumnos a ON a.id = af.alumno_id
             WHERE a.usuario_id = :usuario_id'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        $notaMedia = $stmt->fetchColumn();

        return $notaMedia === false || $notaMedia === null ? null : (float)$notaMedia;
    }

    public function guardar(int $asignacionId, int $usuarioId, float $nota, string $observaciones): void
    {
        if (!is_finite($nota) || $nota < 0 || $nota > 10 || mb_strlen($observaciones) > 2000) {
            throw new InvalidArgumentException('La nota o las observaciones no tienen un valor permitido.');
        }
        $this->db->beginTransaction();
        try {
            $assignment = $this->db->prepare(
                "SELECT id FROM asignaciones_fct
                 WHERE id = :id AND estado = 'Cerrada'
                 FOR UPDATE"
            );
            $assignment->execute(['id' => $asignacionId]);
            if ($assignment->fetchColumn() === false) {
                throw new DomainException('Solo se pueden calificar prácticas cerradas.');
            }

            $save = $this->db->prepare(
                'INSERT INTO calificaciones_fct (asignacion_id, calificador_id, nota, observaciones)
                 VALUES (:asignacion_id, :calificador_id, :nota, :observaciones)
                 ON DUPLICATE KEY UPDATE
                    calificador_id = VALUES(calificador_id),
                    nota = VALUES(nota),
                    observaciones = VALUES(observaciones),
                    actualizada_en = CURRENT_TIMESTAMP'
            );
            $save->execute([
                'asignacion_id' => $asignacionId,
                'calificador_id' => $usuarioId,
                'nota' => number_format($nota, 2, '.', ''),
                'observaciones' => $observaciones !== '' ? $observaciones : null,
            ]);
            (new Auditoria($this->db))->registrar(
                $usuarioId,
                'calificacion.guardada',
                'asignaciones_fct',
                $asignacionId,
                'nota=' . number_format($nota, 2, '.', '')
            );
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
