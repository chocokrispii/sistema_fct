<?php

require_once __DIR__ . '/../../config/database.php';

class Disponibilidad
{
    public function __construct(private PDO $db)
    {
    }

    public function obtenerPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.fecha_inicio, d.fecha_fin, d.turno
             FROM alumno_disponibilidad d
             INNER JOIN alumnos a ON a.id = d.alumno_id
             WHERE a.usuario_id = :usuario_id
             ORDER BY d.fecha_inicio'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function tienePorUsuario(int $usuarioId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1
             FROM alumno_disponibilidad d
             INNER JOIN alumnos a ON a.id = d.alumno_id
             WHERE a.usuario_id = :usuario_id
             LIMIT 1'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);

        return $stmt->fetchColumn() !== false;
    }

    public function obtenerHorarioSemanalPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            'SELECT s.dia_semana, s.hora_inicio, s.hora_fin
             FROM alumno_disponibilidad_semanal s
             INNER JOIN alumno_disponibilidad d ON d.id = s.disponibilidad_id
             INNER JOIN alumnos a ON a.id = d.alumno_id
             WHERE a.usuario_id = :usuario_id
             ORDER BY s.dia_semana'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function reemplazar(int $usuarioId, string $inicio, string $fin, string $turno, array $horarios = []): void
    {
        $this->db->beginTransaction();
        try {
            $student = $this->db->prepare('SELECT id FROM alumnos WHERE usuario_id = :usuario_id FOR UPDATE');
            $student->execute(['usuario_id' => $usuarioId]);
            $alumnoId = $student->fetchColumn();
            if ($alumnoId === false) {
                throw new RuntimeException('No se encontró el expediente del alumno.');
            }

            $delete = $this->db->prepare('DELETE FROM alumno_disponibilidad WHERE alumno_id = :alumno_id');
            $delete->execute(['alumno_id' => $alumnoId]);

            $insert = $this->db->prepare(
                'INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
                 VALUES (:alumno_id, :inicio, :fin, :turno)'
            );
            $insert->execute([
                'alumno_id' => $alumnoId,
                'inicio' => $inicio,
                'fin' => $fin,
                'turno' => $turno,
            ]);
            $disponibilidadId = (int)$this->db->lastInsertId();

            $insertHorario = $this->db->prepare(
                'INSERT INTO alumno_disponibilidad_semanal
                    (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
                 VALUES (:disponibilidad_id, :dia_semana, :hora_inicio, :hora_fin)'
            );
            foreach ($horarios as $horario) {
                $insertHorario->execute([
                    'disponibilidad_id' => $disponibilidadId,
                    'dia_semana' => $horario['dia_semana'],
                    'hora_inicio' => $horario['hora_inicio'],
                    'hora_fin' => $horario['hora_fin'],
                ]);
            }

            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
