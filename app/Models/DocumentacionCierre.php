<?php

declare(strict_types=1);

final class DocumentacionCierre
{
    public const TYPE_MEMORIA = 'MEMORIA';
    public const TYPE_HOJA_FIRMAS = 'HOJA_FIRMAS';

    public function __construct(private PDO $db)
    {
    }

    public function listarPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT af.id AS asignacion_id, af.estado, af.fecha_cierre,
                    e.nombre_empresa, es.nombre_especialidad,
                    p.fecha_inicio, p.fecha_fin,
                    d.id AS documento_id, d.tipo_documento, d.nombre_original, d.mime_type,
                    d.tamano_bytes, d.created_at
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             LEFT JOIN documentacion_cierre_fct d ON d.asignacion_id = af.id AND d.alumno_id = a.id
             WHERE a.usuario_id = :usuario_id AND af.estado = 'Cerrada'
             ORDER BY af.fecha_cierre DESC, af.id DESC"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        $asignaciones = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = (int)$row['asignacion_id'];
            if (!isset($asignaciones[$id])) {
                $asignaciones[$id] = [
                    'id' => $id,
                    'estado' => $row['estado'],
                    'fecha_cierre' => $row['fecha_cierre'],
                    'nombre_empresa' => $row['nombre_empresa'],
                    'nombre_especialidad' => $row['nombre_especialidad'],
                    'fecha_inicio' => $row['fecha_inicio'],
                    'fecha_fin' => $row['fecha_fin'],
                    'documentos' => [],
                ];
            }
            if ($row['documento_id'] !== null) {
                $asignaciones[$id]['documentos'][$row['tipo_documento']] = [
                    'id' => (int)$row['documento_id'],
                    'nombre_original' => $row['nombre_original'],
                    'mime_type' => $row['mime_type'],
                    'tamano_bytes' => (int)$row['tamano_bytes'],
                    'created_at' => $row['created_at'],
                ];
            }
        }

        return array_values($asignaciones);
    }

    public function obtenerAnterior(int $alumnoId, int $asignacionId, string $tipo): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, archivo_path FROM documentacion_cierre_fct
             WHERE alumno_id = :alumno_id AND asignacion_id = :asignacion_id AND tipo_documento = :tipo
             LIMIT 1'
        );
        $stmt->execute(['alumno_id' => $alumnoId, 'asignacion_id' => $asignacionId, 'tipo' => $tipo]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function guardar(int $alumnoId, int $asignacionId, string $tipo, string $path, string $original, string $mime, int $size): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO documentacion_cierre_fct
                (asignacion_id, alumno_id, tipo_documento, archivo_path, nombre_original, mime_type, tamano_bytes)
             VALUES (:asignacion_id, :alumno_id, :tipo, :path, :original, :mime, :size)
             ON DUPLICATE KEY UPDATE archivo_path = VALUES(archivo_path), nombre_original = VALUES(nombre_original),
                mime_type = VALUES(mime_type), tamano_bytes = VALUES(tamano_bytes), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'asignacion_id' => $asignacionId,
            'alumno_id' => $alumnoId,
            'tipo' => $tipo,
            'path' => $path,
            'original' => $original,
            'mime' => $mime,
            'size' => $size,
        ]);
    }
}
