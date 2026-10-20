<?php
// app/Models/Oferta.php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/Paginacion.php';

class Oferta {
    private PDO $db;
    private ?array $columnasDescriptivas = null;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getPDOConnection();
    }

    public function tieneDatosDescriptivos(): bool
    {
        $columnas = $this->obtenerColumnasDescriptivas();
        return in_array('titulo', $columnas, true)
            && (in_array('lugar', $columnas, true) || in_array('ubicacion', $columnas, true));
    }

    public function crearOferta(
        int $empresaId,
        int $especialidadId,
        int $plazas,
        string $fechaInicio,
        string $fechaFin,
        string $tituloOrTurno,
        string $descripcionOrTitle = '',
        string $turnoOrLocation = 'Indiferente',
        string $modalidad = 'Presencial',
        ?string $ubicacion = null,
        ?string $requisitos = null,
        ?string $tecnologias = null,
        array $horarios = []
    ): int {
        if (func_num_args() <= 8) {
            $turno = $tituloOrTurno;
            $titulo = $descripcionOrTitle;
            $descripcion = null;
            $ubicacion = $turnoOrLocation !== '' ? $turnoOrLocation : null;
            $requisitos = null;
            $tecnologias = null;
            $horarios = [];
        } else {
            $titulo = $tituloOrTurno;
            $descripcion = $descripcionOrTitle;
            $turno = $turnoOrLocation;
        }

        $datos = [
            'empresa_id' => $empresaId,
            'especialidad_id' => $especialidadId,
            'plazas_cuantas' => $plazas,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
            'turno' => $turno,
        ];
        $columnasDisponibles = $this->obtenerColumnasDescriptivas();
        $datosDescriptivos = [
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'modalidad' => $modalidad,
            'ubicacion' => $ubicacion,
            'lugar' => $ubicacion,
            'requisitos' => $requisitos,
            'tecnologias' => $tecnologias,
        ];
        foreach ($datosDescriptivos as $columna => $valor) {
            if (in_array($columna, $columnasDisponibles, true)) {
                $datos[$columna] = $valor;
            }
        }

        $columnas = array_keys($datos);
        $marcadores = array_map(static fn(string $columna): string => ':' . $columna, $columnas);
        $stmt = $this->db->prepare(
            'INSERT INTO empresa_especialidades_plazas (' . implode(', ', $columnas) . ')
             VALUES (' . implode(', ', $marcadores) . ')'
        );
        $stmt->execute($datos);
        $offerId = (int)$this->db->lastInsertId();

        if ($horarios !== []) {
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
        $stmt = $this->db->prepare($this->selectBase() . ' WHERE p.empresa_id = :empresa_id ORDER BY p.fecha_inicio DESC');
        $stmt->execute(['empresa_id' => $empresaId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPaginaPorEmpresa(int $empresaId, int $pagina, int $porPagina = 20): array
    {
        return $this->obtenerPagina('p.empresa_id = :empresa_id', ['empresa_id' => $empresaId], 'p.fecha_inicio DESC, p.id DESC', $pagina, $porPagina);
    }

    public function obtenerTodas(bool $soloVigentes = false): array {
        $sql = $this->selectBase()
            . ($soloVigentes ? " WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()" : '')
            . ' ORDER BY p.fecha_inicio, p.id';
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerVigentesConAsignaciones(): array
    {
        $stmt = $this->db->query(
            "SELECT p.id, p.empresa_id, p.especialidad_id,
                    " . $this->expresionesDatosDescriptivos() . ",
                    p.plazas_cuantas, p.fecha_inicio, p.fecha_fin, p.turno,
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
            $this->selectBase()
            . " WHERE p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()
               ORDER BY p.fecha_inicio, p.id LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPaginaTodas(bool $soloVigentes, int $pagina, int $porPagina = 20): array
    {
        $where = $soloVigentes
            ? "p.estado = 'Aprobada' AND p.fecha_fin >= CURDATE()"
            : '';
        return $this->obtenerPagina($where, [], 'p.fecha_inicio, p.id', $pagina, $porPagina);
    }

    public function obtenerPendientes(): array
    {
        $stmt = $this->db->query($this->selectBase() . " WHERE p.estado = 'Pendiente' ORDER BY p.fecha_inicio");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtenerPaginaPendientes(int $pagina, int $porPagina = 20): array
    {
        return $this->obtenerPagina("p.estado = 'Pendiente'", [], 'p.fecha_inicio, p.id', $pagina, $porPagina);
    }

    private function obtenerPagina(string $where, array $params, string $orderBy, int $pagina, int $porPagina): array
    {
        $countSql = 'SELECT COUNT(*) FROM empresa_especialidades_plazas p';
        if ($where !== '') {
            $countSql .= ' WHERE ' . $where;
        }
        $count = $this->db->prepare($countSql);
        $count->execute($params);
        $paginacion = Paginacion::calcular((int)$count->fetchColumn(), $pagina, $porPagina);

        $sql = $this->selectBase();
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY ' . $orderBy . ' LIMIT :limite OFFSET :offset';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $paginacion['offset'], PDO::PARAM_INT);
        $stmt->execute();
        $paginacion['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $paginacion;
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

    private function selectBase(): string
    {
        return 'SELECT p.id, p.empresa_id, p.especialidad_id, '
            . $this->expresionesDatosDescriptivos() . ',
                p.plazas_cuantas, p.fecha_inicio, p.fecha_fin,
                p.estado, p.turno, p.motivo_revision,
                e.nombre_empresa, es.nombre_especialidad
         FROM empresa_especialidades_plazas p
         INNER JOIN empresas e ON e.id = p.empresa_id
         INNER JOIN especialidades es ON es.id = p.especialidad_id';
    }

    private function expresionesDatosDescriptivos(): string
    {
        $columnas = $this->obtenerColumnasDescriptivas();
        $ubicaciones = [];
        foreach (['ubicacion', 'lugar'] as $columna) {
            if (in_array($columna, $columnas, true)) {
                $ubicaciones[] = "NULLIF(p.{$columna}, '')";
            }
        }

        return implode(', ', [
            in_array('titulo', $columnas, true) ? "COALESCE(p.titulo, '') AS titulo" : "'' AS titulo",
            in_array('descripcion', $columnas, true) ? "COALESCE(p.descripcion, '') AS descripcion" : "'' AS descripcion",
            in_array('modalidad', $columnas, true) ? "COALESCE(p.modalidad, 'Presencial') AS modalidad" : "'Presencial' AS modalidad",
            $ubicaciones !== [] ? 'COALESCE(' . implode(', ', $ubicaciones) . ", '') AS ubicacion" : "'' AS ubicacion",
            $ubicaciones !== [] ? 'COALESCE(' . implode(', ', $ubicaciones) . ", '') AS lugar" : "'' AS lugar",
            in_array('requisitos', $columnas, true) ? "COALESCE(p.requisitos, '') AS requisitos" : "'' AS requisitos",
            in_array('tecnologias', $columnas, true) ? "COALESCE(p.tecnologias, '') AS tecnologias" : "'' AS tecnologias",
        ]);
    }

    private function obtenerColumnasDescriptivas(): array
    {
        if ($this->columnasDescriptivas === null) {
            $stmt = $this->db->query(
                "SELECT COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'empresa_especialidades_plazas'
                   AND COLUMN_NAME IN ('titulo', 'lugar', 'descripcion', 'modalidad', 'ubicacion', 'requisitos', 'tecnologias')"
            );
            $this->columnasDescriptivas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        return $this->columnasDescriptivas;
    }
}
