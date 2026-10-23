<?php

class ConsultaController
{
    private const FILAS_POR_PAGINA = 50;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getPDOConnection();
    }

    public function buscar(): void
    {
        $db = $this->db;
        $rawEspecialidad = $_GET['especialidad_id'] ?? '';
        $rawEstado = $_GET['estado'] ?? '';
        $rawDesde = $_GET['desde'] ?? '';
        $rawHasta = $_GET['hasta'] ?? '';
        $rawPagina = $_GET['pagina'] ?? '1';
        $error = '';
        if (
            !is_string($rawEspecialidad)
            || !is_string($rawEstado)
            || !is_string($rawDesde)
            || !is_string($rawHasta)
            || !is_string($rawPagina)
        ) {
            throw new InvalidArgumentException('Los filtros de consulta deben ser valores simples.');
        }
        $paginaSolicitada = filter_var($rawPagina, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($paginaSolicitada === false) {
            $error = 'Selecciona una página válida.';
            $paginaSolicitada = 1;
        }

        $desde = $this->parseDate($rawDesde);
        $hasta = $this->parseDate($rawHasta);
        if (
            ($rawDesde !== '' && $desde === null)
            || ($rawHasta !== '' && $hasta === null)
            || ($desde !== null && $hasta !== null && $desde > $hasta)
        ) {
            $error = 'Indica fechas válidas y comprueba que el inicio no sea posterior al fin.';
        }
        $filters = [
            'especialidad' => $rawEspecialidad === ''
                ? ''
                : filter_var($rawEspecialidad, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]),
            'estado' => $rawEstado,
            'desde' => $desde?->format('Y-m-d') ?? '',
            'hasta' => $hasta?->format('Y-m-d') ?? '',
        ];

        $where = [];
        $params = [];
        if ($filters['especialidad'] === false) {
            $error = 'Selecciona un ciclo válido.';
        } elseif ($filters['especialidad'] !== '') {
            $where[] = 'p.especialidad_id = :especialidad';
            $params['especialidad'] = $filters['especialidad'];
        }
        if ($filters['estado'] !== '' && in_array($filters['estado'], ['Pendiente', 'Aprobada', 'Rechazada'], true)) {
            $where[] = 'p.estado = :estado';
            $params['estado'] = $filters['estado'];
        } elseif ($filters['estado'] !== '') {
            $error = 'Selecciona un estado válido.';
        }
        if ($filters['desde'] !== '') {
            $where[] = 'p.fecha_inicio >= :desde';
            $params['desde'] = $filters['desde'];
        }
        if ($filters['hasta'] !== '') {
            $where[] = 'p.fecha_fin <= :hasta';
            $params['hasta'] = $filters['hasta'];
        }

        $from = "FROM empresa_especialidades_plazas p
                 INNER JOIN empresas e ON e.id = p.empresa_id
                 INNER JOIN especialidades es ON es.id = p.especialidad_id
                 LEFT JOIN asignaciones_fct af ON af.oferta_id = p.id AND af.estado <> 'Cancelada'
                 LEFT JOIN alumnos a ON a.id = af.alumno_id";
        $whereSql = '';
        $anonimize = ($_SESSION['rol'] ?? null) === 'Observador';
        $studentFields = $anonimize
            ? "'Alumno anonimizado' AS alumno_nombre, '' AS apellidos"
            : 'a.nombre AS alumno_nombre, a.apellidos';
        if ($where !== []) {
            $whereSql = ' WHERE ' . implode(' AND ', $where);
        }

        $rows = [];
        $totalFilas = 0;
        $totalPaginas = 1;
        $pagina = 1;
        if ($error === '') {
            $count = $db->prepare('SELECT COUNT(*) ' . $from . $whereSql);
            $count->execute($params);
            $totalFilas = (int)$count->fetchColumn();
            $totalPaginas = max(1, (int)ceil($totalFilas / self::FILAS_POR_PAGINA));
            $pagina = min((int)$paginaSolicitada, $totalPaginas);
            $offset = ($pagina - 1) * self::FILAS_POR_PAGINA;
            $sql = "SELECT p.id, p.titulo, p.modalidad, p.ubicacion, p.estado, p.fecha_inicio, p.fecha_fin, p.plazas_cuantas, p.turno, p.jornada,
                           e.nombre_empresa, es.nombre_especialidad,
                           $studentFields, af.estado AS estado_asignacion
                    $from$whereSql
                    ORDER BY p.fecha_inicio DESC, e.nombre_empresa, p.id, af.id
                    LIMIT :limite OFFSET :offset";
            $stmt = $db->prepare($sql);
            foreach ($params as $name => $value) {
                $stmt->bindValue(':' . $name, $value);
            }
            $stmt->bindValue(':limite', self::FILAS_POR_PAGINA, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        View::render('consultas/index', [
            'rows' => $rows,
            'especialidades' => (new Especialidad($db))->obtenerTodas(),
            'filters' => $filters,
            'error' => $error,
            'pagina' => $pagina,
            'totalPaginas' => $totalPaginas,
            'totalFilas' => $totalFilas,
            'primerResultado' => $totalFilas === 0 ? 0 : $offset + 1,
            'ultimoResultado' => min($pagina * self::FILAS_POR_PAGINA, $totalFilas),
        ]);
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            ? $date
            : null;
    }
}
