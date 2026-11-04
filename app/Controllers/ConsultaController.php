<?php

class ConsultaController
{
    public function buscar(): void
    {
        $db = getPDOConnection();
        $rawEspecialidad = $_GET['especialidad_id'] ?? '';
        $rawEstado = $_GET['estado'] ?? '';
        $rawDesde = $_GET['desde'] ?? '';
        $rawHasta = $_GET['hasta'] ?? '';
        $error = '';
        if (!is_string($rawEspecialidad) || !is_string($rawEstado) || !is_string($rawDesde) || !is_string($rawHasta)) {
            throw new InvalidArgumentException('Los filtros de consulta deben ser valores simples.');
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
            'desde' => $desde,
            'hasta' => $hasta,
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
        if ($filters['desde'] !== null) {
            $where[] = 'p.fecha_inicio >= :desde';
            $params['desde'] = $filters['desde']->format('Y-m-d');
        }
        if ($filters['hasta'] !== null) {
            $where[] = 'p.fecha_fin <= :hasta';
            $params['hasta'] = $filters['hasta']->format('Y-m-d');
        }

        $anonimize = ($_SESSION['rol'] ?? null) === 'Observador';
        $studentFields = $anonimize
            ? "'Alumno anonimizado' AS alumno_nombre, '' AS apellidos"
            : 'a.nombre AS alumno_nombre, a.apellidos';
        $sql = "SELECT p.id, p.titulo, p.modalidad, p.ubicacion, s.nombre AS sede_nombre,
                       s.direccion AS sede_direccion, s.telefono AS sede_telefono,
                       p.estado, p.fecha_inicio, p.fecha_fin, p.plazas_cuantas, p.turno,
                       e.nombre_empresa, es.nombre_especialidad,
                       $studentFields, af.estado AS estado_asignacion
                FROM empresa_especialidades_plazas p
                INNER JOIN empresas e ON e.id = p.empresa_id
                INNER JOIN especialidades es ON es.id = p.especialidad_id
                INNER JOIN empresa_sedes s ON s.id = p.sede_id AND s.empresa_id = p.empresa_id
                LEFT JOIN asignaciones_fct af ON af.oferta_id = p.id AND af.estado <> 'Cancelada'
                LEFT JOIN alumnos a ON a.id = af.alumno_id";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.fecha_inicio DESC, e.nombre_empresa LIMIT 500';

        $rows = [];
        if ($error === '') {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        View::render('consultas/index', [
            'rows' => $rows,
            'especialidades' => (new Especialidad($db))->obtenerTodas(),
            'filters' => $filters,
            'error' => $error,
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
