<?php

declare(strict_types=1);

final class EvaluacionEmpresa
{
    public function __construct(private PDO $db)
    {
    }

    public function listarPorEmpresa(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT af.id, af.estado, af.fecha_asignacion, af.fecha_cierre, af.tutor_empresa,
                    af.evaluacion_dictamen, af.evaluacion_archivo_path, af.evaluacion_nombre_original,
                    af.evaluacion_mime_type, af.evaluacion_tamano_bytes, af.evaluacion_subida_en,
                    a.nombre AS alumno_nombre, a.apellidos, a.correo AS alumno_correo,
                    p.fecha_inicio, p.fecha_fin, es.nombre_especialidad
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN usuarios eu ON eu.id = e.usuario_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             WHERE eu.id = :usuario_id AND eu.rol = 'Empresa' AND af.estado <> 'Cancelada'
             ORDER BY p.fecha_fin DESC, af.id DESC"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerAsignacionPropia(int $asignacionId, int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT af.id, af.alumno_id, af.evaluacion_archivo_path, af.evaluacion_dictamen,
                    e.id AS empresa_id, e.nombre_empresa, a.nombre, a.apellidos
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN usuarios eu ON eu.id = e.usuario_id
             WHERE af.id = :id AND eu.id = :usuario_id AND eu.rol = 'Empresa'
               AND af.estado <> 'Cancelada' LIMIT 1"
        );
        $stmt->execute(['id' => $asignacionId, 'usuario_id' => $usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function listarDocumentosInicio(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.id, d.tipo_documento, d.nombre_original, d.created_at,
                    af.id AS asignacion_id, a.nombre, a.apellidos
             FROM documentacion_inicio_fct d
             INNER JOIN asignaciones_fct af ON af.id = d.asignacion_id
             INNER JOIN alumnos a ON a.id = d.alumno_id
             INNER JOIN empresas e ON e.id = (
                 SELECT p.empresa_id FROM empresa_especialidades_plazas p WHERE p.id = af.oferta_id
             )
             WHERE e.usuario_id = :usuario_id AND af.estado <> 'Cancelada'
             ORDER BY d.created_at DESC, d.id DESC"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerDocumentoInicio(int $documentoId, int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT d.id, d.archivo_path, d.nombre_original
             FROM documentacion_inicio_fct d
             INNER JOIN asignaciones_fct af ON af.id = d.asignacion_id
             INNER JOIN empresas e ON e.id = (
                 SELECT p.empresa_id FROM empresa_especialidades_plazas p WHERE p.id = af.oferta_id
             )
             WHERE d.id = :id AND e.usuario_id = :usuario_id AND af.estado <> 'Cancelada' LIMIT 1"
        );
        $stmt->execute(['id' => $documentoId, 'usuario_id' => $usuarioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function guardar(int $asignacionId, string $dictamen, string $path, string $original, string $mime, int $size): void
    {
        $stmt = $this->db->prepare(
            "UPDATE asignaciones_fct
             SET evaluacion_dictamen = :dictamen, evaluacion_archivo_path = :path,
                 evaluacion_nombre_original = :original, evaluacion_mime_type = :mime,
                 evaluacion_tamano_bytes = :size, evaluacion_subida_en = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            'dictamen' => $dictamen,
            'path' => $path,
            'original' => $original,
            'mime' => $mime,
            'size' => $size,
            'id' => $asignacionId,
        ]);
    }
}
