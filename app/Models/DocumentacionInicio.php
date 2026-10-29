<?php

declare(strict_types=1);

final class DocumentacionInicio
{
    public const TYPE_GUIDE = 'GUIA_INICIO';
    public const TYPE_PLAN = 'PLAN_FORMATIVO';

    public function __construct(private PDO $db)
    {
    }

    public function datosAsignacion(int $asignacionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT af.id, a.id AS alumno_id, a.nombre, a.apellidos, u.email,
                    e.nombre_empresa, es.nombre_especialidad, p.titulo,
                    p.fecha_inicio, p.fecha_fin, af.tutor_empresa
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN usuarios u ON u.id = a.usuario_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             WHERE af.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $asignacionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listarPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.tipo_documento, d.nombre_original, d.created_at,
                    af.id AS asignacion_id, e.nombre_empresa, es.nombre_especialidad,
                    p.fecha_inicio, p.fecha_fin
             FROM documentacion_inicio_fct d
             INNER JOIN asignaciones_fct af ON af.id = d.asignacion_id
             INNER JOIN alumnos a ON a.id = d.alumno_id AND a.usuario_id = :usuario_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             ORDER BY d.created_at DESC, d.id DESC'
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function guardar(int $asignacionId, int $alumnoId, string $tipo, string $path, string $original): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO documentacion_inicio_fct
                (asignacion_id, alumno_id, tipo_documento, archivo_path, nombre_original)
             VALUES (:asignacion_id, :alumno_id, :tipo, :path, :original)
             ON DUPLICATE KEY UPDATE archivo_path = VALUES(archivo_path), nombre_original = VALUES(nombre_original)'
        );
        $stmt->execute([
            'asignacion_id' => $asignacionId,
            'alumno_id' => $alumnoId,
            'tipo' => $tipo,
            'path' => $path,
            'original' => $original,
        ]);
    }

    public function obtener(int $usuarioId, int $documentoId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT d.id, d.archivo_path, d.nombre_original
             FROM documentacion_inicio_fct d
             INNER JOIN alumnos a ON a.id = d.alumno_id AND a.usuario_id = :usuario_id
             WHERE d.id = :id LIMIT 1'
        );
        $stmt->execute(['usuario_id' => $usuarioId, 'id' => $documentoId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}
