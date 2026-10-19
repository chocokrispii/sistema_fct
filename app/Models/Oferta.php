<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/Paginacion.php';

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

    public function obtenerPaginaPorEmpresa(int $empresaId, int $pagina, int $porPagina = 20): array
    {
        return $this->obtenerPagina('p.empresa_id = :empresa_id', ['empresa_id' => $empresaId], 'p.fecha_inicio DESC, p.id DESC', $pagina, $porPagina);
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

    public function obtenerCandidatos(int $ofertaId): array
    {
        [$sql, $params] = $this->construirConsultaCandidatos(
            'p.id = :oferta_id',
            ['oferta_id' => $ofertaId]
        );
        $sql .= ' ORDER BY apellidos, nombre';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidatos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($candidatos as &$candidato) {
            unset($candidato['oferta_id']);
        }
        unset($candidato);
        return $candidatos;
    }

    public function obtenerCandidatosVigentes(?int $usuarioId = null): array
    {
        [$sql, $params] = $this->construirConsultaCandidatos(
            'p.fecha_fin >= CURDATE()',
            [],
            true,
            $usuarioId
        );
        $sql .= ' ORDER BY oferta_id, apellidos, nombre';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $candidatosPorOferta = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidato) {
            $ofertaId = (int)$candidato['oferta_id'];
            unset($candidato['oferta_id']);
            $candidatosPorOferta[$ofertaId][] = $candidato;
        }

        return $candidatosPorOferta;
    }

    public function obtenerIdsOfertasVigentesCompatibles(int $usuarioId, ?array $ofertaIds = null): array
    {
        if ($ofertaIds === null) {
            $ofertaIds = [];
            foreach ($this->obtenerVigentesConAsignaciones() as $oferta) {
                if ((int)$oferta['asignadas'] < (int)$oferta['plazas_cuantas']) {
                    $ofertaIds[] = (int)$oferta['id'];
                }
            }
        }
        if ($ofertaIds === []) {
            return [];
        }

        foreach ($ofertaIds as $ofertaId) {
            if (!is_int($ofertaId) || $ofertaId < 1) {
                throw new InvalidArgumentException('El identificador de oferta no es válido.');
            }
        }
        $ofertaIds = array_values(array_unique($ofertaIds));
        $placeholders = [];
        $params = [];
        foreach ($ofertaIds as $indice => $ofertaId) {
            $placeholder = 'oferta_' . $indice;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $ofertaId;
        }
        [$sql, $params] = $this->construirConsultaCandidatos(
            'p.id IN (' . implode(', ', $placeholders) . ') AND p.fecha_fin >= CURDATE()',
            $params,
            true,
            $usuarioId,
            true
        );
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $fila): int => (int)$fila['oferta_id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public function obtenerCandidatosPaginados(
        array $ofertaIds,
        array $paginasPorOferta,
        int $porPagina = 10
    ): array {
        if ($ofertaIds === []) {
            return [];
        }
        if ($porPagina < 1 || $porPagina > 100) {
            throw new InvalidArgumentException('El tamaño de página debe estar entre 1 y 100.');
        }

        foreach ($ofertaIds as $ofertaId) {
            if (!is_int($ofertaId) || $ofertaId < 1) {
                throw new InvalidArgumentException('El identificador de oferta no es válido.');
            }
        }
        $ofertaIds = array_values(array_unique($ofertaIds));
        $placeholders = [];
        $params = [];
        foreach ($ofertaIds as $indice => $ofertaId) {
            $placeholder = 'oferta_' . $indice;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $ofertaId;
        }
        [$baseSql, $params] = $this->construirConsultaCandidatos(
            'p.id IN (' . implode(', ', $placeholders) . ') AND p.fecha_fin >= CURDATE()',
            $params,
            true
        );
        $rangosDesde = [];
        $rangosHasta = [];
        foreach ($ofertaIds as $indice => $ofertaId) {
            $pagina = Paginacion::desdeEntrada($paginasPorOferta[$ofertaId] ?? 1);
            $offset = ($pagina - 1) * $porPagina;

            $desdeId = 'desde_oferta_' . $indice;
            $desdeFila = 'desde_fila_' . $indice;
            $hastaId = 'hasta_oferta_' . $indice;
            $hastaFila = 'hasta_fila_' . $indice;
            $rangosDesde[] = 'WHEN :' . $desdeId . ' THEN :' . $desdeFila;
            $rangosHasta[] = 'WHEN :' . $hastaId . ' THEN :' . $hastaFila;
            $params[$desdeId] = $ofertaId;
            $params[$desdeFila] = $offset;
            $params[$hastaId] = $ofertaId;
            $params[$hastaFila] = $offset + $porPagina + 1;
        }

        $sql = 'SELECT pagina_candidatos.*
                FROM (
                    SELECT candidatos.*,
                           ROW_NUMBER() OVER (
                               PARTITION BY oferta_id
                               ORDER BY apellidos, nombre, alumno_id, turno
                           ) AS numero_fila
                    FROM (' . $baseSql . ') candidatos
                ) pagina_candidatos
                WHERE pagina_candidatos.numero_fila > CASE pagina_candidatos.oferta_id '
                    . implode(' ', $rangosDesde) . ' ELSE 0 END
                  AND pagina_candidatos.numero_fila <= CASE pagina_candidatos.oferta_id '
                    . implode(' ', $rangosHasta) . ' ELSE 0 END
                ORDER BY pagina_candidatos.oferta_id, pagina_candidatos.numero_fila';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $resultado = [];
        foreach ($ofertaIds as $ofertaId) {
            $resultado[$ofertaId] = [
                'candidatos' => [],
                'haySiguiente' => false,
                'pagina' => Paginacion::desdeEntrada($paginasPorOferta[$ofertaId] ?? 1),
            ];
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidato) {
            $ofertaId = (int)$candidato['oferta_id'];
            if ((int)$candidato['numero_fila'] > (($resultado[$ofertaId]['pagina'] - 1) * $porPagina) + $porPagina) {
                $resultado[$ofertaId]['haySiguiente'] = true;
                continue;
            }
            unset($candidato['oferta_id'], $candidato['numero_fila']);
            $resultado[$ofertaId]['candidatos'][] = $candidato;
        }

        return $resultado;
    }

    public function contarAsignacionesPorOferta(array $ofertaIds): array
    {
        if ($ofertaIds === []) {
            return [];
        }
        foreach ($ofertaIds as $ofertaId) {
            if (!is_int($ofertaId) || $ofertaId < 1) {
                throw new InvalidArgumentException('El identificador de oferta no es válido.');
            }
        }
        $ofertaIds = array_values(array_unique($ofertaIds));

        $placeholders = [];
        $params = [];
        $conteos = array_fill_keys($ofertaIds, 0);
        foreach ($ofertaIds as $indice => $ofertaId) {
            $placeholder = 'oferta_' . $indice;
            $placeholders[] = ':' . $placeholder;
            $params[$placeholder] = $ofertaId;
        }

        $sql = "SELECT oferta_id, COUNT(*) AS asignadas
                FROM asignaciones_fct
                WHERE oferta_id IN (" . implode(', ', $placeholders) . ")
                  AND estado IN ('Asignada', 'Cerrada')
                GROUP BY oferta_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $conteos[(int)$fila['oferta_id']] = (int)$fila['asignadas'];
        }

        return $conteos;
    }

    private function construirConsultaCandidatos(
        string $filtro,
        array $params,
        bool $soloOfertasConCapacidad = false,
        ?int $usuarioId = null,
        bool $soloIds = false
    ): array
    {
        $capacityJoin = $soloOfertasConCapacidad
            ? "LEFT JOIN (
                    SELECT oferta_id, COUNT(*) AS asignadas
                    FROM asignaciones_fct
                    WHERE estado IN ('Asignada', 'Cerrada')
                    GROUP BY oferta_id
                ) capacidad ON capacidad.oferta_id = p.id"
            : '';
        $capacityCondition = $soloOfertasConCapacidad
            ? ' AND COALESCE(capacidad.asignadas, 0) < p.plazas_cuantas'
            : '';
        $condicionUsuario = '';
        if ($usuarioId !== null) {
            $condicionUsuario = ' AND a.usuario_id = :usuario_id';
            $params['usuario_id'] = $usuarioId;
        }
        $columnas = $soloIds
            ? 'p.id AS oferta_id'
            : 'p.id AS oferta_id, a.id AS alumno_id, a.nombre, a.apellidos, a.correo, d.turno';
        $agrupacion = $soloIds
            ? 'p.id'
            : 'p.id, a.id, a.nombre, a.apellidos, a.correo, d.turno';
        $sql = "SELECT {$columnas}
                FROM empresa_especialidades_plazas p
                INNER JOIN alumnos a ON a.especialidad_id = p.especialidad_id
                INNER JOIN alumno_disponibilidad d
                    ON d.alumno_id = a.id
                    AND d.fecha_inicio <= p.fecha_inicio
                    AND d.fecha_fin >= p.fecha_fin
                    AND (d.turno = 'Indiferente' OR p.turno = 'Indiferente'
                         OR d.turno = p.turno)
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
                WHERE {$filtro}
                    AND p.estado = 'Aprobada'
                    {$condicionUsuario}
                    AND NOT EXISTS (
                        SELECT 1 FROM asignaciones_fct af
                        WHERE af.alumno_id = a.id AND af.estado = 'Asignada'
                    )
                    {$capacityCondition}
                GROUP BY {$agrupacion}";
        if ($soloIds) {
            $sql .= ' ORDER BY p.id';
        }
        return [$sql, $params];
    }

    private function obtenerPagina(string $where, array $params, string $orden, int $pagina, int $porPagina): array
    {
        if ($porPagina < 1 || $porPagina > 100) {
            throw new InvalidArgumentException('El tamaño de página debe estar entre 1 y 100.');
        }

        $whereSql = $where === '' ? '' : ' WHERE ' . $where;
        $count = $this->db->prepare('SELECT COUNT(*) FROM empresa_especialidades_plazas p' . $whereSql);
        $count->execute($params);
        $paginacion = Paginacion::calcular((int)$count->fetchColumn(), $pagina, $porPagina);

        $stmt = $this->db->prepare(
            self::SELECT_BASE . $whereSql . ' ORDER BY ' . $orden . ' LIMIT :limite OFFSET :offset'
        );
        foreach ($params as $nombre => $valor) {
            $stmt->bindValue(':' . $nombre, $valor, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $paginacion['offset'], PDO::PARAM_INT);
        $stmt->execute();

        $paginacion['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $paginacion;
    }
}
