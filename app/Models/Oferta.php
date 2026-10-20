<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/Paginacion.php';

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

    private function obtenerPagina(string $where, array $params, string $orderBy, int $pagina, int $porPagina): array
    {
        $whereSql = $where !== '' ? ' WHERE ' . $where : '';
        $count = $this->db->prepare(
            'SELECT COUNT(*)
             FROM empresa_especialidades_plazas p
             INNER JOIN empresas e ON e.id = p.empresa_id
             INNER JOIN especialidades es ON es.id = p.especialidad_id' . $whereSql
        );
        $count->execute($params);
        $paginacion = Paginacion::calcular((int)$count->fetchColumn(), $pagina, $porPagina);

        $stmt = $this->db->prepare(self::SELECT_BASE . $whereSql . ' ORDER BY ' . $orderBy . ' LIMIT :limite OFFSET :offset');
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $paginacion['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $paginacion['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $paginacion;
    }

    public function obtenerPaginaPorEmpresa(int $empresaId, int $pagina, int $porPagina = 20): array
    {
        return $this->obtenerPagina('p.empresa_id = :empresa_id', ['empresa_id' => $empresaId], 'p.fecha_inicio DESC, p.id DESC', $pagina, $porPagina);
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

    public function obtenerResumenVigentes(): array
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) AS total_ofertas, COUNT(DISTINCT empresa_id) AS total_empresas
             FROM empresa_especialidades_plazas
             WHERE estado = 'Aprobada' AND fecha_fin >= CURDATE()"
        );

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function obtenerRecientesVigentes(int $limite = 4): array
    {
        if ($limite < 1 || $limite > 20) {
            throw new InvalidArgumentException('El límite de ofertas recientes debe estar entre 1 y 20.');
        }

        $stmt = $this->db->prepare(
            self::SELECT_BASE
            . " WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()
               ORDER BY p.fecha_inicio, p.id LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPaginaTodas(bool $soloVigentes, int $pagina, int $porPagina = 20): array
    {
        $where = $soloVigentes ? "p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()" : '';
        return $this->obtenerPagina($where, [], 'p.fecha_inicio, p.id', $pagina, $porPagina);
    }

    public function obtenerPendientes(): array
    {
        $stmt = $this->db->query(self::SELECT_BASE . " WHERE p.estado = 'Pendiente' ORDER BY p.fecha_inicio");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPaginaPendientes(int $pagina, int $porPagina = 20): array
    {
        return $this->obtenerPagina("p.estado = 'Pendiente'", [], 'p.fecha_inicio, p.id', $pagina, $porPagina);
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

    public function contarAsignacionesPorOferta(array $ofertaIds): array
    {
        $ofertaIds = array_values(array_unique(array_map('intval', $ofertaIds)));
        if ($ofertaIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ofertaIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT oferta_id, COUNT(*) AS total
             FROM asignaciones_fct
             WHERE estado IN ('Asignada', 'Cerrada') AND oferta_id IN ($placeholders)
             GROUP BY oferta_id"
        );
        $stmt->execute($ofertaIds);

        $conteos = array_fill_keys($ofertaIds, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $conteos[(int)$fila['oferta_id']] = (int)$fila['total'];
        }

        return $conteos;
    }

    /**
     * @param int[] $ofertaIds
     * @param array<int, int> $paginas Página solicitada por oferta.
     * @return array<int, array<string, mixed>> Paginación y 'candidatos' por oferta.
     */
    public function obtenerCandidatosPaginados(array $ofertaIds, array $paginas, int $porPagina = 5): array
    {
        $resultado = [];
        foreach ($ofertaIds as $ofertaId) {
            $ofertaId = (int)$ofertaId;
            $candidatos = $this->obtenerCandidatos($ofertaId);
            $paginacion = Paginacion::calcular(count($candidatos), (int)($paginas[$ofertaId] ?? 1), $porPagina);
            $paginacion['candidatos'] = array_slice($candidatos, $paginacion['offset'], $porPagina);
            $resultado[$ofertaId] = $paginacion;
        }

        return $resultado;
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
                                OR MOD(CAST(oh.dia_semana AS SIGNED) - 1 - WEEKDAY(p.fecha_inicio) + 7, 7)
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
                                    OR MOD(CAST(oh.dia_semana AS SIGNED) - 1 - WEEKDAY(p.fecha_inicio) + 7, 7)
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
