<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';

class Convenio
{
    public function __construct(private PDO $db)
    {
    }

    public function crear(
        int $empresaId,
        int $especialidadId,
        ?int $sedeId,
        string $numero,
        string $firma,
        string $inicio,
        string $fin,
        string $ruta
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO convenios (empresa_id, especialidad_id, sede_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path)
             VALUES (:empresa, :especialidad, :sede, :numero, :firma, :inicio, :fin, :ruta)'
        );
        $stmt->execute([
            'empresa' => $empresaId,
            'especialidad' => $especialidadId,
            'sede' => $sedeId,
            'numero' => $numero,
            'firma' => $firma,
            'inicio' => $inicio,
            'fin' => $fin,
            'ruta' => $ruta,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function listar(?int $empresaId = null, ?string $estado = null): array
    {
        $sql = 'SELECT c.*, e.nombre_empresa, s.nombre AS nombre_sede,
                       es.nombre_especialidad
                FROM convenios c
                INNER JOIN empresas e ON e.id = c.empresa_id
                LEFT JOIN empresa_sedes s ON s.id = c.sede_id
                LEFT JOIN especialidades es ON es.id = c.especialidad_id';
        $where = [];
        $params = [];
        if ($empresaId !== null) {
            $where[] = 'c.empresa_id = :empresa_id';
            $params['empresa_id'] = $empresaId;
        }
        if ($estado !== null) {
            $where[] = 'c.estado = :estado';
            $params['estado'] = $estado;
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.creado_en DESC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarArchivo(): array
    {
        $stmt = $this->db->query(
            'SELECT c.*, e.nombre_empresa, s.nombre AS nombre_sede,
                    es.nombre_especialidad,
                    COALESCE(NULLIF(CONCAT_WS(\' \', coord.nombre, coord.apellidos), \'\'), revisor.email, \'Usuario eliminado\') AS nombre_revisor
             FROM convenios c
             INNER JOIN empresas e ON e.id = c.empresa_id
             LEFT JOIN empresa_sedes s ON s.id = c.sede_id
             LEFT JOIN especialidades es ON es.id = c.especialidad_id
             LEFT JOIN usuarios revisor ON revisor.id = c.revisado_por
             LEFT JOIN coordinadores coord ON coord.usuario_id = revisor.id
             ORDER BY c.creado_en DESC, c.id DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function buscarPaginado(array $filtros): array
    {
        $where = [];
        $params = [];
        $estado = $filtros['estado'] ?? 'todos';
        $estados = [
            'pendiente_revision' => 'Pendiente',
            'aprobado' => 'Aprobado',
            'rechazado' => 'Rechazado',
            'archivado' => 'Archivado',
        ];
        if ($estado !== 'todos') {
            if (!is_string($estado) || !isset($estados[$estado])) {
                throw new InvalidArgumentException('El filtro de estado no es válido.');
            }
            $where[] = 'c.estado = :estado';
            $params['estado'] = $estados[$estado];
        }

        $nif = $filtros['nif'] ?? '';
        if (!is_string($nif)) {
            throw new InvalidArgumentException('El filtro NIF no es válido.');
        }
        $nif = strtoupper((string)preg_replace('/[\s.-]+/u', '', trim($nif)));
        if (mb_strlen($nif) > 20) {
            throw new InvalidArgumentException('El filtro NIF no puede superar los 20 caracteres.');
        }
        if ($nif !== '') {
            $where[] = "REPLACE(REPLACE(REPLACE(UPPER(e.cif_empresa), '-', ''), ' ', ''), '.', '') LIKE :nif";
            $params['nif'] = '%' . addcslashes($nif, '\\_%') . '%';
        }

        foreach (['fecha_desde', 'fecha_hasta'] as $dateFilter) {
            if (!isset($filtros[$dateFilter]) || $filtros[$dateFilter] === '') {
                continue;
            }
            $date = $filtros[$dateFilter];
            if (!is_string($date) || !self::esFechaValida($date)) {
                throw new InvalidArgumentException('El rango de fechas no es válido.');
            }
            if ($dateFilter === 'fecha_desde') {
                $where[] = 'c.creado_en >= :fecha_desde';
                $params['fecha_desde'] = $date . ' 00:00:00';
            } else {
                $where[] = 'c.creado_en < DATE_ADD(:fecha_hasta, INTERVAL 1 DAY)';
                $params['fecha_hasta'] = $date;
            }
        }
        if (
            isset($filtros['fecha_desde'], $filtros['fecha_hasta'])
            && is_string($filtros['fecha_desde'])
            && is_string($filtros['fecha_hasta'])
            && $filtros['fecha_desde'] !== ''
            && $filtros['fecha_hasta'] !== ''
            && $filtros['fecha_desde'] > $filtros['fecha_hasta']
        ) {
            throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
        }

        $page = filter_var($filtros['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $limit = filter_var($filtros['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) {
            throw new InvalidArgumentException('La paginación solicitada no es válida.');
        }
        $offset = ($page - 1) * $limit;
        if ($offset > 2_000_000_000) {
            throw new InvalidArgumentException('La página solicitada está fuera de rango.');
        }

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $sql = 'SELECT c.*, e.nombre_empresa, e.cif_empresa, s.nombre AS nombre_sede,
                       es.nombre_especialidad,
                       COALESCE(NULLIF(CONCAT_WS(\' \', coord.nombre, coord.apellidos), \'\'), revisor.email, \'Usuario eliminado\') AS nombre_revisor,
                       COUNT(*) OVER() AS total_registros
                FROM convenios c
                INNER JOIN empresas e ON e.id = c.empresa_id
                LEFT JOIN empresa_sedes s ON s.id = c.sede_id
                LEFT JOIN especialidades es ON es.id = c.especialidad_id
                LEFT JOIN usuarios revisor ON revisor.id = c.revisado_por
                LEFT JOIN coordinadores coord ON coord.usuario_id = revisor.id'
                . $whereSql .
                ' ORDER BY c.creado_en DESC, c.id DESC
                  LIMIT :limit OFFSET :offset';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue(':' . $name, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows !== []) {
            $total = (int)$rows[0]['total_registros'];
        } else {
            $count = $this->db->prepare(
                'SELECT COUNT(*)
                 FROM convenios c
                 INNER JOIN empresas e ON e.id = c.empresa_id'
                 . $whereSql
            );
            $count->execute($params);
            $total = (int)$count->fetchColumn();
        }
        foreach ($rows as &$row) {
            unset($row['total_registros']);
        }
        unset($row);

        return [
            'items' => $rows,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'pages' => max(1, (int)ceil($total / $limit)),
        ];
    }

    public function listarEventos(array $convenioIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn(mixed $id): int => (int)$id, $convenioIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($ids as $index => $id) {
            $placeholder = ':convenio_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $id;
        }
        $stmt = $this->db->prepare(
            "SELECT a.entidad_id AS convenio_id, a.evento, a.detalle, a.creado_en,
                    COALESCE(NULLIF(CONCAT_WS(' ', coord.nombre, coord.apellidos), ''), u.email, 'Usuario eliminado') AS actor
             FROM auditoria a
             INNER JOIN convenios c ON c.id = a.entidad_id
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             LEFT JOIN coordinadores coord ON coord.usuario_id = u.id
             WHERE a.entidad = 'convenios'
               AND a.evento LIKE 'convenio.%'
               AND a.entidad_id IN (" . implode(', ', $placeholders) . ')
             ORDER BY a.creado_en DESC, a.id DESC'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarEventosArchivo(): array
    {
        $stmt = $this->db->query(
            "SELECT a.entidad_id AS convenio_id, a.evento, a.detalle, a.creado_en,
                    COALESCE(NULLIF(CONCAT_WS(' ', coord.nombre, coord.apellidos), ''), u.email, 'Usuario eliminado') AS actor
             FROM auditoria a
             INNER JOIN convenios c ON c.id = a.entidad_id
             LEFT JOIN usuarios u ON u.id = a.usuario_id
             LEFT JOIN coordinadores coord ON coord.usuario_id = u.id
             WHERE a.entidad = 'convenios'
               AND a.evento LIKE 'convenio.%'
             ORDER BY a.creado_en DESC, a.id DESC"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listarParaObservador(): array
    {
        $stmt = $this->db->query(
            'SELECT e.nombre_empresa, es.nombre_especialidad, c.fecha_firma, c.fecha_inicio,
                    c.fecha_fin, c.estado
             FROM convenios c
             INNER JOIN empresas e ON e.id = c.empresa_id
             LEFT JOIN especialidades es ON es.id = c.especialidad_id
             ORDER BY c.creado_en DESC'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function obtener(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM convenios WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function resolver(int $id, string $estado, string $motivo, int $usuarioId): bool
    {
        if (!in_array($estado, ['Aprobado', 'Rechazado'], true)) {
            throw new InvalidArgumentException('Estado de revisión de convenio no válido.');
        }
        $stmt = $this->db->prepare(
            "UPDATE convenios SET estado = :estado, motivo_revision = :motivo,
                 revisado_por = :usuario, revisado_en = NOW()
             WHERE id = :id AND estado = 'Pendiente'"
        );
        $stmt->execute([
            'estado' => $estado,
            'motivo' => $motivo !== '' ? $motivo : null,
            'usuario' => $usuarioId,
            'id' => $id,
        ]);
        return $stmt->rowCount() === 1;
    }

    public function transicionar(int $id, string $estado, string $motivo, int $usuarioId): string
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Las transiciones de convenio deben ejecutarse dentro de una transacción.');
        }
        $permitidos = [
            'Aprobado' => ['Pendiente'],
            'Rechazado' => ['Pendiente'],
            'Archivado' => ['Aprobado', 'Rechazado'],
        ];
        if (!isset($permitidos[$estado])) {
            throw new InvalidArgumentException('La transición de estado no es válida.');
        }
        $stmt = $this->db->prepare('SELECT estado FROM convenios WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $anterior = $stmt->fetchColumn();
        if (!is_string($anterior) || !in_array($anterior, $permitidos[$estado], true)) {
            throw new DomainException('El convenio no existe o su estado ya no permite esta acción.');
        }
        $update = $this->db->prepare(
            'UPDATE convenios
             SET estado = :estado, motivo_revision = :motivo,
                 revisado_por = :usuario, revisado_en = NOW()
             WHERE id = :id'
        );
        $update->execute([
            'estado' => $estado,
            'motivo' => $motivo !== '' ? $motivo : null,
            'usuario' => $usuarioId,
            'id' => $id,
        ]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('No se pudo actualizar el estado del convenio.');
        }
        return $anterior;
    }

    private static function esFechaValida(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }
}
