<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Auditoria.php';
require_once __DIR__ . '/../Core/Webhook.php';
require_once __DIR__ . '/../Core/Paginacion.php';

class Asignacion
{
    private const SELECT_LISTADO =
        "SELECT af.id, af.estado, af.fecha_asignacion, af.fecha_cierre, af.tutor_empresa,
                a.id AS alumno_id, a.nombre AS alumno_nombre, a.apellidos, a.correo AS alumno_correo,
                p.id AS oferta_id, p.fecha_inicio, p.fecha_fin,
                e.nombre_empresa, es.nombre_especialidad
         FROM asignaciones_fct af
         INNER JOIN alumnos a ON a.id = af.alumno_id
         INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
         INNER JOIN empresas e ON e.id = p.empresa_id
         INNER JOIN especialidades es ON es.id = p.especialidad_id";

    public function __construct(private PDO $db)
    {
    }

    public function listar(bool $soloPropias = false, int $usuarioId = 0): array
    {
        [$sql, $params] = $this->consultaListado($soloPropias, $usuarioId);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPagina(bool $soloPropias, int $usuarioId, int $pagina, int $porPagina = 20): array
    {
        if ($porPagina < 1 || $porPagina > 100) {
            throw new InvalidArgumentException('El tamaño de página debe estar entre 1 y 100.');
        }

        $whereSql = $soloPropias ? ' WHERE a.usuario_id = :usuario_id' : '';
        $params = $soloPropias ? ['usuario_id' => $usuarioId] : [];
        $countSql = 'SELECT COUNT(*) FROM asignaciones_fct af'
            . ($soloPropias ? ' INNER JOIN alumnos a ON a.id = af.alumno_id' . $whereSql : '');
        $count = $this->db->prepare($countSql);
        $count->execute($params);
        $paginacion = Paginacion::calcular((int)$count->fetchColumn(), $pagina, $porPagina);

        [$sql, $params] = $this->consultaListado($soloPropias, $usuarioId);
        $stmt = $this->db->prepare($sql . ' LIMIT :limite OFFSET :offset');
        foreach ($params as $nombre => $valor) {
            $stmt->bindValue(':' . $nombre, $valor, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $paginacion['offset'], PDO::PARAM_INT);
        $stmt->execute();

        $paginacion['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $paginacion;
    }

    private function consultaListado(bool $soloPropias, int $usuarioId): array
    {
        $sql = self::SELECT_LISTADO;
        $params = [];
        if ($soloPropias) {
            $sql .= ' WHERE a.usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $sql .= ' ORDER BY af.fecha_asignacion DESC, af.id DESC';

        return [$sql, $params];
    }

    public function contarActivasPorUsuario(int $usuarioId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*)
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             WHERE a.usuario_id = :usuario_id AND af.estado = 'Asignada'"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return (int)$stmt->fetchColumn();
    }

    public function obtenerProximosEventos(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT eventos.fecha, eventos.titulo, eventos.detalle
             FROM (
                 SELECT p.fecha_inicio AS fecha, 'Inicio de prácticas' AS titulo,
                        e.nombre_empresa AS detalle
                 FROM asignaciones_fct af
                 INNER JOIN alumnos a ON a.id = af.alumno_id
                 INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
                 INNER JOIN empresas e ON e.id = p.empresa_id
                 WHERE a.usuario_id = :usuario_inicio
                   AND af.estado = 'Asignada' AND p.fecha_inicio >= CURDATE()
                 UNION ALL
                 SELECT p.fecha_fin AS fecha, 'Fin de prácticas' AS titulo,
                        e.nombre_empresa AS detalle
                 FROM asignaciones_fct af
                 INNER JOIN alumnos a ON a.id = af.alumno_id
                 INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
                 INNER JOIN empresas e ON e.id = p.empresa_id
                 WHERE a.usuario_id = :usuario_fin
                   AND af.estado = 'Asignada' AND p.fecha_fin >= CURDATE()
                 UNION ALL
                 SELECT d.fecha_inicio AS fecha, 'Inicio de disponibilidad' AS titulo,
                        'Periodo registrado' AS detalle
                 FROM alumno_disponibilidad d
                 INNER JOIN alumnos a ON a.id = d.alumno_id
                 WHERE a.usuario_id = :usuario_disponibilidad
                   AND d.fecha_inicio >= CURDATE()
             ) eventos
             ORDER BY eventos.fecha
             LIMIT 3"
        );
        $stmt->execute([
            'usuario_inicio' => $usuarioId,
            'usuario_fin' => $usuarioId,
            'usuario_disponibilidad' => $usuarioId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crear(int $ofertaId, int $alumnoId, int $coordinadorId, string $tutorEmpresa): int
    {
        $this->db->beginTransaction();
        try {
            $offer = $this->db->prepare(
                "SELECT p.id, p.plazas_cuantas, p.estado
                 FROM empresa_especialidades_plazas p WHERE p.id = :id FOR UPDATE"
            );
            $offer->execute(['id' => $ofertaId]);
            $row = $offer->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['estado'] !== 'Aprobada') {
                throw new RuntimeException('La oferta no existe o no está aprobada.');
            }

            $capacity = $this->db->prepare(
                "SELECT COUNT(*) FROM asignaciones_fct
                 WHERE oferta_id = :oferta_id AND estado IN ('Asignada', 'Cerrada')"
            );
            $capacity->execute(['oferta_id' => $ofertaId]);
            if ((int)$capacity->fetchColumn() >= (int)$row['plazas_cuantas']) {
                throw new RuntimeException('La oferta ya no tiene plazas disponibles.');
            }

            $studentLock = $this->db->prepare('SELECT id FROM alumnos WHERE id = :alumno_id FOR UPDATE');
            $studentLock->execute(['alumno_id' => $alumnoId]);
            if ($studentLock->fetchColumn() === false) {
                throw new RuntimeException('No se encontró el expediente del alumno.');
            }

            $candidate = $this->db->prepare(
                "SELECT 1
                 FROM alumnos a
                 INNER JOIN empresa_especialidades_plazas p ON p.especialidad_id = a.especialidad_id
                 INNER JOIN alumno_disponibilidad d
                    ON d.alumno_id = a.id
                    AND d.fecha_inicio <= p.fecha_inicio AND d.fecha_fin >= p.fecha_fin
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
                 WHERE a.id = :alumno_id AND p.id = :oferta_id
                    AND p.estado = 'Aprobada'
                    AND NOT EXISTS (
                        SELECT 1 FROM asignaciones_fct af
                        WHERE af.alumno_id = a.id AND af.estado = 'Asignada'
                    )
                 LIMIT 1 FOR UPDATE"
            );
            $candidate->execute(['alumno_id' => $alumnoId, 'oferta_id' => $ofertaId]);
            if (!$candidate->fetchColumn()) {
                throw new RuntimeException('El alumno no cumple el ciclo, disponibilidad o turno de la oferta.');
            }

            $insert = $this->db->prepare(
                "INSERT INTO asignaciones_fct (oferta_id, alumno_id, tutor_empresa, asignado_por)
                 VALUES (:oferta_id, :alumno_id, :tutor, :coordinador)"
            );
            $insert->execute([
                'oferta_id' => $ofertaId,
                'alumno_id' => $alumnoId,
                'tutor' => $tutorEmpresa,
                'coordinador' => $coordinadorId,
            ]);
            $asignacionId = (int)$this->db->lastInsertId();
            (new Auditoria($this->db))->registrar(
                $coordinadorId,
                'asignacion.creada',
                'asignaciones_fct',
                $asignacionId,
                'alumno_id=' . $alumnoId
            );
            Webhook::enqueue($this->db, 'practica.asignada', [
                'asignacion_id' => $asignacionId,
                'oferta_id' => $ofertaId,
                'alumno_id' => $alumnoId,
            ]);
            $this->db->commit();
            return $asignacionId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function cerrar(int $id, int $usuarioId): bool
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "UPDATE asignaciones_fct SET estado = 'Cerrada', fecha_cierre = NOW()
                 WHERE id = :id AND estado = 'Asignada'"
            );
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }
            (new Auditoria($this->db))->registrar($usuarioId, 'asignacion.cerrada', 'asignaciones_fct', $id);
            Webhook::enqueue($this->db, 'practica.cerrada', [
                'asignacion_id' => $id,
            ]);
            $this->db->commit();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
