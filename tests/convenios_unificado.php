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
require_once __DIR__ . '/../app/Middleware/CsrfMiddleware.php';
require_once __DIR__ . '/../app/Controllers/DevelopmentPreviewController.php';
require_once __DIR__ . '/../app/Controllers/ConvenioController.php';

function assertConvenios(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = getPDOConnection();
$routes = require __DIR__ . '/../routes/web.php';
assertConvenios(
    ($routes['aprobar-convenio']['method'] ?? null) === 'POST'
        && ($routes['aprobar-convenio']['roles'] ?? null) === ['Administrador']
        && ($routes['rechazar-convenio']['roles'] ?? null) === ['Administrador']
        && ($routes['archivar-convenio']['roles'] ?? null) === ['Administrador']
        && ($routes['descargar-convenio']['roles'] ?? null) === ['Administrador'],
    'Las transiciones y descargas deben estar limitadas a Administrador mediante POST/GET según corresponda.'
);
$cycleId = (int)$db->query('SELECT id FROM especialidades WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
if ($cycleId < 1) {
    throw new RuntimeException('La base de pruebas necesita al menos un ciclo formativo activo.');
}

$fixture = bin2hex(random_bytes(5));
$userIds = [];
$companyId = null;
$agreementId = null;
try {
    $createUser = $db->prepare(
        'INSERT INTO usuarios (email, password_hash, rol) VALUES (:email, :password, :rol)'
    );
    foreach (['Empresa', 'Coordinador'] as $role) {
        $createUser->execute([
            'email' => strtolower($role) . "-$fixture@example.test",
            'password' => password_hash('TestPassword123!', PASSWORD_DEFAULT),
            'rol' => $role,
        ]);
        $userIds[$role] = (int)$db->lastInsertId();
    }

    $createCompany = $db->prepare(
        'INSERT INTO empresas (usuario_id, nombre_empresa, cif_empresa, persona_contacto)
         VALUES (:usuario, :nombre, :cif, :contacto)'
    );
    $createCompany->execute([
        'usuario' => $userIds['Empresa'],
        'nombre' => "Convenios test $fixture",
        'cif' => 'Z' . strtoupper(substr($fixture, 0, 8)),
        'contacto' => 'Contacto test',
    ]);
    $companyId = (int)$db->lastInsertId();

    $createAgreement = $db->prepare(
        "INSERT INTO convenios
            (empresa_id, especialidad_id, numero_convenio, fecha_firma, fecha_inicio, fecha_fin, archivo_path)
         VALUES (:empresa, :especialidad, :numero, '2026-01-01', '2026-01-01', '2027-01-01', 'test-unificado.pdf')"
    );
    $createAgreement->execute([
        'empresa' => $companyId,
        'especialidad' => $cycleId,
        'numero' => "UNIFICADO-$fixture",
    ]);
    $agreementId = (int)$db->lastInsertId();

    $model = new Convenio($db);
    $today = (string)$db->query('SELECT CURRENT_DATE()')->fetchColumn();
    $filtered = $model->buscarPaginado([
        'estado' => 'pendiente_revision',
        'nif' => 'Z' . strtoupper(substr($fixture, 0, 8)),
        'fecha_desde' => $today,
        'fecha_hasta' => $today,
        'page' => 1,
        'limit' => 10,
    ]);
    assertConvenios(
        $filtered['total'] === 1 && (int)$filtered['items'][0]['id'] === $agreementId,
        'La consulta paginada debe filtrar por NIF, fechas y estado.'
    );

    $db->beginTransaction();
    try {
        $previousState = $model->transicionar($agreementId, 'Aprobado', '', $userIds['Coordinador']);
        (new Auditoria($db))->registrar(
            $userIds['Coordinador'],
            'convenio.revisado',
            'convenios',
            $agreementId,
            'estado_anterior=' . $previousState . ';estado_nuevo=Aprobado'
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $db->beginTransaction();
    try {
        $previousState = $model->transicionar($agreementId, 'Archivado', '', $userIds['Coordinador']);
        (new Auditoria($db))->registrar(
            $userIds['Coordinador'],
            'convenio.archivado',
            'convenios',
            $agreementId,
            'estado_anterior=' . $previousState . ';estado_nuevo=Archivado'
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $audit = $db->prepare(
        "SELECT id_usuario_admin, detalle FROM audit_logs
         WHERE evento = 'convenio.archivado'
           AND entidad_afectada = 'convenios'
           AND id_registro_modificado = :id
         ORDER BY id DESC LIMIT 1"
    );
    $audit->execute(['id' => $agreementId]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    assertConvenios(
        is_array($auditRow)
            && (int)$auditRow['id_usuario_admin'] === $userIds['Coordinador']
            && str_contains((string)$auditRow['detalle'], 'estado_anterior=Aprobado')
            && str_contains((string)$auditRow['detalle'], 'estado_nuevo=Archivado'),
        'La transición archivada debe quedar auditada con actor y estados anterior/nuevo.'
    );

    $archived = $model->buscarPaginado([
        'estado' => 'archivado',
        'nif' => 'Z' . strtoupper(substr($fixture, 0, 8)),
        'page' => 1,
        'limit' => 10,
    ]);
    assertConvenios(
        $archived['total'] === 1 && (int)$archived['items'][0]['id'] === $agreementId,
        'El estado Archivado debe poder filtrarse.'
    );

    $_SESSION = ['usuario_id' => $userIds['Coordinador'], 'rol' => 'Coordinador'];
    $_GET = ['id' => $agreementId];
    http_response_code(200);
    $denied = false;
    try {
        (new ConvenioController($db))->descargar();
    } catch (RuntimeException) {
        $denied = http_response_code() === 403;
    }
    assertConvenios($denied, 'La descarga del PDF debe bloquear a los perfiles que no sean Administrador.');

    $deniedAudit = $db->prepare(
        "SELECT COUNT(*) FROM auditoria
         WHERE usuario_id = :usuario
           AND evento = 'convenio.descarga.denegada'
           AND entidad_id = :id
           AND detalle = 'motivo=rol_no_autorizado'"
    );
    $deniedAudit->execute(['usuario' => $userIds['Coordinador'], 'id' => $agreementId]);
    assertConvenios((int)$deniedAudit->fetchColumn() === 1, 'La denegación de descarga debe quedar auditada.');

    echo "convenios_unificado: OK\n";
} finally {
    if ($agreementId !== null) {
        $deleteAudit = $db->prepare(
            'DELETE FROM auditoria WHERE entidad = :entidad AND entidad_id = :id'
        );
        $deleteAudit->execute(['entidad' => 'convenios', 'id' => $agreementId]);
        $deleteLogs = $db->prepare(
            'DELETE FROM audit_logs WHERE entidad_afectada = :entidad AND id_registro_modificado = :id'
        );
        $deleteLogs->execute(['entidad' => 'convenios', 'id' => $agreementId]);
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
