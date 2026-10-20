<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Models/Asignacion.php';
require_once __DIR__ . '/../app/Models/Calificacion.php';
require_once __DIR__ . '/../app/Models/Oferta.php';

function minimoBenchmark(string $nombre, int $porDefecto): int
{
    $valor = getenv($nombre);
    if ($valor === false || $valor === '') {
        return $porDefecto;
    }
    $valorValidado = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($valorValidado === false) {
        throw new InvalidArgumentException("$nombre debe ser un entero no negativo.");
    }

    return $valorValidado;
}

function medirOperacion(PDO $db, string $nombre, callable $operacion, int $repeticiones): array
{
    $operacion();
    $tiempos = [];
    $consultas = [];
    $filas = 0;

    for ($i = 0; $i < $repeticiones; $i++) {
        $estadoInicial = $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC);
        $inicio = hrtime(true);
        $filas = (int)$operacion();
        $duracion = (hrtime(true) - $inicio) / 1_000_000;
        $estadoFinal = $db->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(PDO::FETCH_ASSOC);
        $tiempos[] = $duracion;
        $consultas[] = (int)$estadoFinal['Value'] - (int)$estadoInicial['Value'];
    }

    sort($tiempos);
    sort($consultas);
    $centro = intdiv($repeticiones, 2);
    $mediana = $repeticiones % 2 === 0
        ? ($tiempos[$centro - 1] + $tiempos[$centro]) / 2
        : $tiempos[$centro];
    $medianaConsultas = $repeticiones % 2 === 0
        ? ($consultas[$centro - 1] + $consultas[$centro]) / 2
        : $consultas[$centro];

    return [
        'operacion' => $nombre,
        'filas_visibles' => $filas,
        'consultas_select_mediana' => $medianaConsultas,
        'tiempo_mediana_ms' => round($mediana, 3),
        'tiempo_minimo_ms' => round($tiempos[0], 3),
        'tiempo_maximo_ms' => round($tiempos[$repeticiones - 1], 3),
    ];
}

try {
    if (getenv('FCT_BENCH_ALLOW') !== '1') {
        throw new RuntimeException('Para habilitar la medición define FCT_BENCH_ALLOW=1.');
    }

    $db = getPDOConnection();
    $nombreBase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!str_starts_with($nombreBase, 'fct_bench_')) {
        throw new RuntimeException('La medición solo se permite en una base cuyo nombre empiece por fct_bench_.');
    }

    $repeticiones = (int)(getenv('FCT_BENCH_REPETITIONS') ?: 5);
    if ($repeticiones < 3 || $repeticiones > 50) {
        throw new InvalidArgumentException('FCT_BENCH_REPETITIONS debe estar entre 3 y 50.');
    }

    $tablas = [
        'empresa_especialidades_plazas' => minimoBenchmark('FCT_BENCH_MIN_OFFERS', 250),
        'convenios' => minimoBenchmark('FCT_BENCH_MIN_AGREEMENTS', 10),
        'alumnos' => minimoBenchmark('FCT_BENCH_MIN_STUDENTS', 500),
        'alumno_disponibilidad' => minimoBenchmark('FCT_BENCH_MIN_AVAILABILITIES', 500),
        'alumno_disponibilidad_semanal' => minimoBenchmark('FCT_BENCH_MIN_WEEKLY_SLOTS', 1000),
        'asignaciones_fct' => minimoBenchmark('FCT_BENCH_MIN_ASSIGNMENTS', 400),
    ];
    $volumen = [];
    foreach ($tablas as $tabla => $minimo) {
        $filas = (int)$db->query('SELECT COUNT(*) FROM `' . $tabla . '`')->fetchColumn();
        $volumen[$tabla] = $filas;
        if ($filas < $minimo) {
            throw new RuntimeException(
                "La tabla $tabla tiene $filas filas; el mínimo representativo configurado es $minimo."
            );
        }
    }

    $ofertas = new Oferta($db);
    $asignaciones = new Asignacion($db);
    $calificaciones = new Calificacion($db);
    $empresaId = (int)$db->query('SELECT id FROM empresas ORDER BY id LIMIT 1')->fetchColumn();
    $usuarioAlumnoId = (int)$db->query('SELECT usuario_id FROM alumnos ORDER BY id LIMIT 1')->fetchColumn();
    if ($empresaId < 1 || $usuarioAlumnoId < 1) {
        throw new RuntimeException('Se requiere al menos una empresa y un alumno para medir.');
    }

    $paginaMatching = $ofertas->obtenerPaginaTodas(true, 1, 10);
    $ofertasMatching = $paginaMatching['items'];
    $idsMatching = array_map(static fn(array $oferta): int => (int)$oferta['id'], $ofertasMatching);
    if ($idsMatching === []) {
        throw new RuntimeException('No hay ofertas aprobadas vigentes para comparar matching.');
    }
    $conteosMatching = $ofertas->contarAsignacionesPorOferta($idsMatching);
    $idsConPlazas = [];
    foreach ($ofertasMatching as $oferta) {
        $id = (int)$oferta['id'];
        if ($conteosMatching[$id] < (int)$oferta['plazas_cuantas']) {
            $idsConPlazas[] = $id;
        }
    }
    $muestraCandidatos = $ofertas->obtenerCandidatosPaginados(
        $idsConPlazas,
        array_fill_keys($idsConPlazas, 1),
        11
    );
    $candidatosDisponibles = array_sum(array_map(
        static fn(array $resultado): int => count($resultado['candidatos']),
        $muestraCandidatos
    ));
    if ($candidatosDisponibles < 11) {
        throw new RuntimeException(
            'La primera página de matching requiere al menos 11 candidatos compatibles para medir la paginación.'
        );
    }

    $operaciones = [
        medirOperacion(
            $db,
            'ofertas.aprobadas.sin_paginar',
            static fn(): int => count($ofertas->obtenerTodas(true)),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'ofertas.aprobadas.pagina_1',
            static fn(): int => count($ofertas->obtenerPaginaTodas(true, 1)['items']),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'ofertas.empresa.pagina_1',
            static fn(): int => count($ofertas->obtenerPaginaPorEmpresa($empresaId, 1)['items']),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'ofertas.pendientes.pagina_1',
            static fn(): int => count($ofertas->obtenerPaginaPendientes(1)['items']),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'asignaciones.sin_paginar',
            static fn(): int => count($asignaciones->listar()),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'asignaciones.pagina_1',
            static fn(): int => count($asignaciones->obtenerPagina(false, 0, 1)['items']),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'asignaciones.alumno.pagina_1',
            static fn(): int => count($asignaciones->obtenerPagina(true, $usuarioAlumnoId, 1)['items']),
            $repeticiones
        ),
        medirOperacion(
            $db,
            'dashboard.ofertas.resumen_y_recientes',
            static function () use ($ofertas): int {
                $ofertas->obtenerResumenVigentes();
                return count($ofertas->obtenerRecientesVigentes());
            },
            $repeticiones
        ),
        medirOperacion(
            $db,
            'dashboard.asignaciones.resumen_y_eventos',
            static function () use ($asignaciones, $usuarioAlumnoId): int {
                $asignaciones->contarActivasPorUsuario($usuarioAlumnoId);
                return count($asignaciones->obtenerProximosEventos($usuarioAlumnoId));
            },
            $repeticiones
        ),
        medirOperacion(
            $db,
            'dashboard.calificaciones.media',
            static fn(): int => $calificaciones->obtenerNotaMediaPorUsuario($usuarioAlumnoId) === null ? 0 : 1,
            $repeticiones
        ),
        medirOperacion(
            $db,
            'matching.pagina_1.implementacion_anterior',
            static function () use ($db, $ofertas, $ofertasMatching): int {
                $filasVisibles = 0;
                foreach ($ofertasMatching as $oferta) {
                    $conteo = $db->prepare(
                        "SELECT COUNT(*) FROM asignaciones_fct
                         WHERE oferta_id = :id AND estado IN ('Asignada', 'Cerrada')"
                    );
                    $conteo->execute(['id' => (int)$oferta['id']]);
                    $disponibles = max(0, (int)$oferta['plazas_cuantas'] - (int)$conteo->fetchColumn());
                    if ($disponibles > 0) {
                        $filasVisibles += min(10, count($ofertas->obtenerCandidatos((int)$oferta['id'])));
                    }
                }

                return $filasVisibles;
            },
            $repeticiones
        ),
        medirOperacion(
            $db,
            'matching.pagina_1.implementacion_paginada',
            static function () use ($ofertas, $idsMatching, $ofertasMatching): int {
                $asignadasPorOferta = $ofertas->contarAsignacionesPorOferta($idsMatching);
                $idsConPlazas = [];
                foreach ($idsMatching as $id) {
                    $paginaOferta = array_values(array_filter(
                        $ofertasMatching,
                        static fn(array $oferta): bool => (int)$oferta['id'] === $id
                    ));
                    if ($paginaOferta !== [] && $asignadasPorOferta[$id] < (int)$paginaOferta[0]['plazas_cuantas']) {
                        $idsConPlazas[] = $id;
                    }
                }
                $candidatos = $ofertas->obtenerCandidatosPaginados(
                    $idsConPlazas,
                    array_fill_keys($idsConPlazas, 1)
                );
                return array_sum(array_map(
                    static fn(array $resultado): int => count($resultado['candidatos']),
                    $candidatos
                ));
            },
            $repeticiones
        ),
    ];

    echo json_encode([
        'base_de_datos' => $nombreBase,
        'version_servidor' => $db->query('SELECT VERSION()')->fetchColumn(),
        'repeticiones' => $repeticiones,
        'filas_por_tabla' => $volumen,
        'ofertas_matching_con_plazas' => count($idsConPlazas),
        'candidatos_compatibles_en_muestra' => $candidatosDisponibles,
        'resultados' => $operaciones,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
