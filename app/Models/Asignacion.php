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

    public function listar(bool $soloPropias = false, int $usuarioId = 0, bool $anonimizarAlumnado = false): array
    {
        [$sql, $params] = $this->consultaListado($soloPropias, $usuarioId, $anonimizarAlumnado);
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarActivasPorUsuario(int $usuarioId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.fecha_inicio, p.fecha_fin, e.nombre_empresa
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             WHERE a.usuario_id = :usuario_id AND af.estado = 'Asignada'"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function consultaListado(bool $soloPropias, int $usuarioId, bool $anonimizarAlumnado): array
    {
        $studentFields = $anonimizarAlumnado
            ? "'Alumno anonimizado' AS alumno_nombre, '' AS apellidos, NULL AS alumno_correo, NULL AS alumno_id"
            : 'a.id AS alumno_id, a.nombre AS alumno_nombre, a.apellidos, a.correo AS alumno_correo';
        $tutorField = $anonimizarAlumnado ? 'NULL AS tutor_empresa' : 'af.tutor_empresa';
        $privateIds = $anonimizarAlumnado
            ? 'NULL AS id, NULL AS oferta_id, NULL AS alumno_usuario_id'
            : 'af.id, p.id AS oferta_id, u.id AS alumno_usuario_id';
        $sql = "SELECT $privateIds, af.estado, af.fecha_asignacion, af.fecha_cierre, $tutorField,
                       $studentFields,
                       p.fecha_inicio, p.fecha_fin, e.nombre_empresa, es.nombre_especialidad
                FROM asignaciones_fct af
                INNER JOIN alumnos a ON a.id = af.alumno_id
                INNER JOIN usuarios u ON u.id = a.usuario_id
                INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
                INNER JOIN empresas e ON e.id = p.empresa_id
                INNER JOIN especialidades es ON es.id = p.especialidad_id";
        $params = [];
        if ($soloPropias) {
            $sql .= ' WHERE a.usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $sql .= ' ORDER BY af.fecha_asignacion DESC, af.id DESC';

        return [$sql, $params];
    }

    public function obtenerPagina(
        bool $soloPropias,
        int $usuarioId,
        int $pagina,
        int $porPagina = 20,
        bool $anonimizarAlumnado = false
    ): array {
        $where = $soloPropias ? ' WHERE a.usuario_id = :usuario_id' : '';
        $params = $soloPropias ? ['usuario_id' => $usuarioId] : [];
        $count = $this->db->prepare(
            'SELECT COUNT(*)
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id'
             . $where
        );
        $count->execute($params);
        $paginacion = Paginacion::calcular((int)$count->fetchColumn(), $pagina, $porPagina);

        [$sql, $listParams] = $this->consultaListado($soloPropias, $usuarioId, $anonimizarAlumnado);
        $items = $this->db->prepare($sql . ' LIMIT :limite OFFSET :offset');
        foreach ($listParams as $name => $value) {
            $items->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $items->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $items->bindValue(':offset', $paginacion['offset'], PDO::PARAM_INT);
        $items->execute();
        $paginacion['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        return $paginacion;
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
                 UNION ALL
                 SELECT d.fecha_fin AS fecha, 'Fin de disponibilidad' AS titulo,
                        'Periodo registrado' AS detalle
                 FROM alumno_disponibilidad d
                 INNER JOIN alumnos a ON a.id = d.alumno_id
                 WHERE a.usuario_id = :usuario_fin_disponibilidad
                   AND d.fecha_fin >= CURDATE()
             ) eventos
             ORDER BY eventos.fecha,
                      CASE WHEN eventos.titulo LIKE 'Fin de%' THEN 1 ELSE 0 END,
                      eventos.titulo,
                      eventos.detalle
             LIMIT 5"
        );
        $stmt->execute([
            'usuario_inicio' => $usuarioId,
            'usuario_fin' => $usuarioId,
            'usuario_disponibilidad' => $usuarioId,
            'usuario_fin_disponibilidad' => $usuarioId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crear(int $ofertaId, int $alumnoId, int $coordinadorId, string $tutorEmpresa): int
    {
        $this->db->beginTransaction();
        try {
            $offer = $this->db->prepare(
                "SELECT p.id, p.plazas_cuantas, p.estado,
                        p.fecha_fin >= CURDATE() AS vigente
                 FROM empresa_especialidades_plazas p WHERE p.id = :id FOR UPDATE"
            );
            $offer->execute(['id' => $ofertaId]);
            $row = $offer->fetch(PDO::FETCH_ASSOC);
            if (!$row || $row['estado'] !== 'Aprobada' || (int)$row['vigente'] !== 1) {
                throw new RuntimeException('La oferta no existe, no está aprobada o ya no está vigente.');
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
                    AND (
                        d.es_indiferente = TRUE
                        OR d.turno = 'Indiferente'
                        OR p.turno = 'Indiferente'
                        OR d.turno = p.turno
                    )
                    AND EXISTS (
                        SELECT 1 FROM empresa_oferta_horarios_semanales oh
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
                throw new DomainException('El alumno no cumple el ciclo, disponibilidad o cobertura horaria de la oferta.');
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

    public function obtenerEmpresaAsignada(int $usuarioId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT e.nombre_empresa, e.categoria, es.nombre_especialidad,
                    p.fecha_inicio, p.fecha_fin
             FROM asignaciones_fct af
             INNER JOIN alumnos a ON a.id = af.alumno_id
             INNER JOIN empresa_especialidades_plazas p ON p.id = af.oferta_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id
             WHERE a.usuario_id = :usuario_id AND af.estado = 'Asignada'
             ORDER BY af.fecha_asignacion DESC
             LIMIT 1"
        );
        $stmt->execute(['usuario_id' => $usuarioId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }
}
