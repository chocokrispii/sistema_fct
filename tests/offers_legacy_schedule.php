<?php

declare(strict_types=1);

$testDatabase = getenv('FCT_TEST_DB_NAME');
if (
    !is_string($testDatabase)
    || !preg_match('/^fct_test_[a-zA-Z0-9_]+$/', $testDatabase)
    || getenv('FCT_DB_NAME') !== $testDatabase
) {
    fwrite(STDERR, "Define FCT_DB_NAME y FCT_TEST_DB_NAME con la misma base fct_test_*.\n");
    exit(2);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../app/Core/View.php';
require_once __DIR__ . '/../app/Core/JsonResponse.php';
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/OfertaController.php';

function assertLegacyOffer(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getPDOConnection();
$specialtyId = (int)$db->query(
    "SELECT id FROM especialidades
     WHERE nombre_especialidad = 'Desarrollo de Aplicaciones Multiplataforma (DAM)'
       AND activo = 1 LIMIT 1"
)->fetchColumn();
if ($specialtyId < 1) {
    throw new RuntimeException('La base de pruebas necesita el ciclo DAM activo.');
}

$fixture = bin2hex(random_bytes(5));
$userIds = [];
$companyId = null;
$agreementId = null;
$offerId = null;
try {
    $insertUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :password, :rol)'
    );
    foreach (['Empresa', 'Administrador'] as $role) {
        $insertUser->execute([
            'email' => strtolower($role) . "-offer-$fixture@example.test",
            'password' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
            'rol' => $role,
        ]);
        $userIds[$role] = (int)$db->lastInsertId();
    }

    $insertCompany = $db->prepare(
        'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
         VALUES (:usuario, :nombre, :cif, :contacto)'
    );
    $insertCompany->execute([
        'usuario' => $userIds['Empresa'],
        'nombre' => "Oferta histórica test $fixture",
        'cif' => 'T' . strtoupper(substr($fixture, 0, 8)),
        'contacto' => 'Contacto test',
    ]);
    $companyId = (int)$db->lastInsertId();

    $insertAgreement = $db->prepare(
        "INSERT INTO convenios
            (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path, estado)
         VALUES (:empresa, :especialidad, :numero, '2026-01-01', '2027-03-01', '2027-06-30', 'test-placeholder.pdf', 'Aprobado')"
    );
    $insertAgreement->execute([
        'empresa' => $companyId,
        'especialidad' => $specialtyId,
        'numero' => "AG-TEST-$fixture",
    ]);
    $agreementId = (int)$db->lastInsertId();

    $insertOffer = $db->prepare(
        "INSERT INTO empresa_especialidades_plazas
            (empresa_id, especialidad_id, plazas_cuantas, fecha_inicio, fecha_fin,
             titulo, descripcion, turno, modalidad, ubicacion)
         VALUES (:empresa, :especialidad, 2, '2027-03-01', '2027-06-30',
             'Oferta histórica test', 'Prueba', 'Indiferente', 'Presencial', 'Centro')"
    );
    $insertOffer->execute(['empresa' => $companyId, 'especialidad' => $specialtyId]);
    $offerId = (int)$db->lastInsertId();
    assertLegacyOffer((new Oferta($db))->obtenerHorarioSemanal($offerId) === [], 'La fixture debe comenzar sin horario.');

    $_SESSION = ['usuario_id' => $userIds['Administrador'], 'rol' => 'Administrador'];
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    unset($_SERVER['CONTENT_TYPE']);
    $_POST = [
        'oferta_id' => (string)$offerId,
        'horarios' => [
            1 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '15:00'],
            2 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '15:00'],
            3 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '15:00'],
            4 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '15:00'],
            5 => ['activo' => '1', 'hora_inicio' => '08:00', 'hora_fin' => '15:00'],
        ],
    ];
    ob_start();
    (new OfertaController($db))->actualizarHorarioRevision();
    $scheduleJson = ob_get_clean();
    $scheduleResponse = json_decode((string)$scheduleJson, true);
    assertLegacyOffer(
        is_array($scheduleResponse) && ($scheduleResponse['success'] ?? false) === true,
        'La ruta administrativa debe guardar las franjas semanales.'
    );

    $schedule = (new Oferta($db))->obtenerHorarioSemanal($offerId);
    assertLegacyOffer(
        count($schedule) === 5
            && (int)$schedule[0]['dia_semana'] === 1
            && $schedule[0]['hora_inicio'] === '08:00:00'
            && $schedule[0]['hora_fin'] === '15:00:00'
            && (int)$schedule[4]['dia_semana'] === 5,
        'El horario guardado debe tener las cinco franjas de lunes a viernes de 08:00 a 15:00.'
    );

    $auditSchedule = $db->prepare(
        "SELECT COUNT(*) FROM audit_logs
         WHERE evento = 'oferta.horario_actualizado'
           AND entidad_afectada = 'ofertas'
           AND id_registro_modificado = :id
           AND detalle LIKE '%horario_nuevo=lunes%'"
    );
    $auditSchedule->execute(['id' => $offerId]);
    assertLegacyOffer((int)$auditSchedule->fetchColumn() === 1, 'El cambio de horario debe quedar auditado.');

    $_POST = [
        'oferta_id' => (string)$offerId,
        'decision' => 'Aprobada',
        'motivo' => '',
    ];
    ob_start();
    (new OfertaController($db))->resolverRevision();
    $approvalJson = ob_get_clean();
    $approvalResponse = json_decode((string)$approvalJson, true);
    assertLegacyOffer(
        is_array($approvalResponse) && ($approvalResponse['success'] ?? false) === true,
        'Tras guardar el horario, la oferta debe poder aprobarse si tiene convenio válido.'
    );

    $state = $db->prepare('SELECT estado FROM empresa_especialidades_plazas WHERE id = :id');
    $state->execute(['id' => $offerId]);
    assertLegacyOffer($state->fetchColumn() === 'Aprobada', 'La aprobación debe cambiar el estado de la oferta.');

    $approvalAudit = $db->prepare(
        "SELECT detalle FROM audit_logs
         WHERE evento = 'oferta.revisada'
           AND entidad_afectada = 'ofertas'
           AND id_registro_modificado = :id
         ORDER BY id DESC LIMIT 1"
    );
    $approvalAudit->execute(['id' => $offerId]);
    $approvalDetail = $approvalAudit->fetchColumn();
    assertLegacyOffer(
        is_string($approvalDetail)
            && str_contains($approvalDetail, 'estado_anterior=Pendiente')
            && str_contains($approvalDetail, 'estado_nuevo=Aprobada'),
        'La aprobación debe registrar el estado anterior y el nuevo.'
    );

    echo "offers_legacy_schedule: OK\n";
} finally {
    if ($offerId !== null) {
        $deleteAudit = $db->prepare(
            'DELETE FROM auditoria WHERE entidad = :entidad AND entidad_id = :id'
        );
        $deleteAudit->execute(['entidad' => 'ofertas', 'id' => $offerId]);
        $deleteLogs = $db->prepare(
            'DELETE FROM audit_logs WHERE entidad_afectada = :entidad AND id_registro_modificado = :id'
        );
        $deleteLogs->execute(['entidad' => 'ofertas', 'id' => $offerId]);
        $deleteOffer = $db->prepare('DELETE FROM empresa_especialidades_plazas WHERE id = :id');
        $deleteOffer->execute(['id' => $offerId]);
    }
    if ($agreementId !== null) {
        $deleteAgreement = $db->prepare('DELETE FROM convenios WHERE id = :id');
        $deleteAgreement->execute(['id' => $agreementId]);
    }
    if ($companyId !== null) {
        $deleteCompany = $db->prepare('DELETE FROM empresas WHERE id = :id');
        $deleteCompany->execute(['id' => $companyId]);
    }
    if ($userIds !== []) {
        $deleteUser = $db->prepare('DELETE FROM usuarios WHERE id = :id');
        foreach ($userIds as $userId) {
            $deleteUser->execute(['id' => $userId]);
        }
    }
}
