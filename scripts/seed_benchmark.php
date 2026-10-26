<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    if (getenv('FCT_BENCH_SEED_ALLOW') !== '1') {
        throw new RuntimeException('Para crear los datos define FCT_BENCH_SEED_ALLOW=1.');
    }

    $db = getPDOConnection();
    $nombreBase = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if (!str_starts_with($nombreBase, 'fct_bench_')) {
        throw new RuntimeException('Los datos solo se crean en una base cuyo nombre empiece por fct_bench_.');
    }

    $duplicados = (int)$db->query(
        "SELECT COUNT(*) FROM usuarios WHERE email LIKE 'bench.student.%@bench.invalid'"
    )->fetchColumn();
    if ($duplicados > 0) {
        throw new RuntimeException(
            'Esta base ya contiene datos de benchmark. Crea una base fct_bench_ nueva para repetir la muestra.'
        );
    }

    $especialidad = (int)$db->query('SELECT id FROM especialidades ORDER BY id LIMIT 1')->fetchColumn();
    $coordinador = (int)$db->query(
        "SELECT id FROM usuarios WHERE rol = 'Coordinador' ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($especialidad < 1 || $coordinador < 1) {
        throw new RuntimeException('Importa el volcado y las migraciones antes de generar la muestra.');
    }

    $fechas = $db->query(
        'SELECT DATE_ADD(CURDATE(), INTERVAL 14 DAY) AS inicio,
                DATE_ADD(CURDATE(), INTERVAL 44 DAY) AS fin'
    )->fetch(PDO::FETCH_ASSOC);
    if ($fechas === false) {
        throw new RuntimeException('No se pudieron calcular las fechas de la muestra.');
    }

    $hash = password_hash('Benchmark data only', PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('No se pudo preparar la credencial de prueba.');
    }

    $db->beginTransaction();

    $insertarUsuario = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :hash, :rol)'
    );
    $insertarEmpresa = $db->prepare(
        'INSERT INTO empresas
            (usuario_id, nombre_empresa, nombre_comercial, cif_empresa, pertenece_grupo_empresa,
             categoria, persona_contacto, representante_legal, estado)
         VALUES (:usuario_id, :nombre, :comercial, :cif, 0, :categoria, :contacto, :contacto, \'Aprobada\')'
    );
    $insertarAlumno = $db->prepare(
        'INSERT INTO alumnos
            (usuario_id, nombre, apellidos, dni, numero_seguridad_social, correo, especialidad_id)
         VALUES (:usuario_id, :nombre, :apellidos, :dni, :nss, :correo, :especialidad_id)'
    );
    $insertarOferta = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin, estado, turno, revisado_por, revisado_en)
         VALUES (:empresa_id, :especialidad_id, 2, :fecha_inicio, :fecha_fin, 'Aprobada', 'Indiferente', :revisor, NOW())"
    );
    $insertarConvenio = $db->prepare(
        "INSERT INTO convenios
            (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin,
             archivo_path, estado, revisado_por, revisado_en)
         VALUES
            (:empresa_id, :especialidad_id, :numero, CURDATE(), :fecha_inicio, :fecha_fin,
             :archivo, 'Aprobado', :revisor, NOW())"
    );
    $insertarDisponibilidad = $db->prepare(
        "INSERT INTO alumno_disponibilidad (alumno_id, fecha_inicio, fecha_fin, turno)
         VALUES (:alumno_id, :fecha_inicio, :fecha_fin, 'Indiferente')"
    );
    $insertarFranja = $db->prepare(
        'INSERT INTO alumno_disponibilidad_semanal
            (disponibilidad_id, dia_semana, hora_inicio, hora_fin)
         VALUES (:disponibilidad_id, :dia_semana, :hora_inicio, :hora_fin)'
    );
    $insertarAsignacion = $db->prepare(
        "INSERT INTO asignaciones_fct
            (oferta_id, alumno_id, tutor_empresa, estado, asignado_por)
         VALUES (:oferta_id, :alumno_id, 'Tutor de pruebas', 'Asignada', :asignado_por)"
    );

    $empresaIds = [];
    for ($indice = 1; $indice <= 10; $indice++) {
        $correo = sprintf('bench.company.%02d@bench.invalid', $indice);
        $insertarUsuario->execute(['email' => $correo, 'hash' => $hash, 'rol' => 'Empresa']);
        $usuarioId = (int)$db->lastInsertId();
        $insertarEmpresa->execute([
            'usuario_id' => $usuarioId,
            'nombre' => sprintf('Empresa Benchmark %02d', $indice),
            'comercial' => sprintf('Empresa Benchmark %02d', $indice),
            'cif' => sprintf('B9%07d', $indice),
            'categoria' => 'Desarrollo de software',
            'contacto' => 'Contacto de pruebas',
        ]);
        $empresaIds[] = (int)$db->lastInsertId();
    }

    $convenioNumero = 0;
    foreach ($empresaIds as $empresaId) {
        $convenioNumero++;
        $insertarConvenio->execute([
            'empresa_id' => $empresaId,
            'especialidad_id' => $especialidad,
            'numero' => sprintf('BENCH-CNV-%02d', $convenioNumero),
            'fecha_inicio' => $fechas['inicio'],
            'fecha_fin' => $fechas['fin'],
            'archivo' => bin2hex(random_bytes(24)) . '.pdf',
            'revisor' => $coordinador,
        ]);
    }

    $alumnoIds = [];
    for ($indice = 1; $indice <= 500; $indice++) {
        $correo = sprintf('bench.student.%04d@bench.invalid', $indice);
        $insertarUsuario->execute(['email' => $correo, 'hash' => $hash, 'rol' => 'Alumno']);
        $usuarioId = (int)$db->lastInsertId();
        $insertarAlumno->execute([
            'usuario_id' => $usuarioId,
            'nombre' => sprintf('Alumno%04d', $indice),
            'apellidos' => 'Benchmark',
            'dni' => sprintf('BENCH-DNI-%04d', $indice),
            'nss' => sprintf('BENCH-NSS-%04d', $indice),
            'correo' => $correo,
            'especialidad_id' => $especialidad,
        ]);
        $alumnoIds[] = (int)$db->lastInsertId();
    }

    $ofertaIds = [];
    for ($indice = 0; $indice < 250; $indice++) {
        $insertarOferta->execute([
            'empresa_id' => $empresaIds[$indice % count($empresaIds)],
            'especialidad_id' => $especialidad,
            'fecha_inicio' => $fechas['inicio'],
            'fecha_fin' => $fechas['fin'],
            'revisor' => $coordinador,
        ]);
        $ofertaIds[] = (int)$db->lastInsertId();
    }

    for ($indice = 0; $indice < 500; $indice++) {
        $insertarDisponibilidad->execute([
            'alumno_id' => $alumnoIds[$indice],
            'fecha_inicio' => $fechas['inicio'],
            'fecha_fin' => $fechas['fin'],
        ]);
        $disponibilidadId = (int)$db->lastInsertId();
        foreach ([1, 3] as $dia) {
            $insertarFranja->execute([
                'disponibilidad_id' => $disponibilidadId,
                'dia_semana' => $dia,
                'hora_inicio' => '08:00:00',
                'hora_fin' => '14:00:00',
            ]);
        }
    }

    for ($indice = 0; $indice < 400; $indice++) {
        $insertarAsignacion->execute([
            'oferta_id' => $ofertaIds[10 + intdiv($indice, 2)],
            'alumno_id' => $alumnoIds[$indice],
            'asignado_por' => $coordinador,
        ]);
    }

    $db->commit();

    echo json_encode([
        'base_de_datos' => $nombreBase,
        'empresas_creadas' => count($empresaIds),
        'convenios_creados' => count($empresaIds),
        'ofertas_creadas' => count($ofertaIds),
        'alumnos_creados' => count($alumnoIds),
        'disponibilidades_creadas' => 500,
        'franjas_semanales_creadas' => 1000,
        'asignaciones_creadas' => 400,
        'alumnos_sin_asignar' => count($alumnoIds) - 400,
        'fecha_inicio' => $fechas['inicio'],
        'fecha_fin' => $fechas['fin'],
        'ofertas_con_plazas_disponibles_en_primera_pagina' => 10,
        'candidatos_no_asignados_por_oferta' => count($alumnoIds) - 400,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
