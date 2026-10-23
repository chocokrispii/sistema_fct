<?php

require_once __DIR__ . '/../app/Core/AuditLogService.php';
require_once __DIR__ . '/../app/Models/Auditoria.php';

final class AuditLogTestStatement extends PDOStatement
{
    public function __construct(
        private AuditLogTestPDO $db,
        private string $query
    ) {
    }

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->query, 'INSERT INTO audit_logs') && $this->db->failAuditLogInsert) {
            throw new PDOException('Simulated audit log failure.');
        }

        $this->db->entries[] = ['query' => $this->query, 'params' => $params ?? []];
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }
}

final class AuditLogTestPDO extends PDO
{
    public bool $transaction = false;
    public bool $failAuditLogInsert = false;
    public int $commits = 0;
    public int $rollbacks = 0;
    public array $entries = [];

    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new AuditLogTestStatement($this, $query);
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function beginTransaction(): bool
    {
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->transaction = false;
        $this->commits++;
        return true;
    }

    public function rollBack(): bool
    {
        $this->transaction = false;
        $this->rollbacks++;
        return true;
    }
}

function assertAuditService(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new AuditLogTestPDO();
$service = new AuditLogService($db);
$cases = [
    ['usuario.estado_cambiado', 'activo=0', 'cambio de estado'],
    ['ciclo.retirado', '', 'retirada de ciclo'],
    ['ciclo.reactivado', '', 'reactivación de ciclo'],
    ['convenio.revisado', 'estado=Aprobado', 'aprobación'],
    ['oferta.revisada', 'estado=Rechazada', 'rechazo'],
    ['cuenta.activacion_reenviada', 'usuario_id=13', 'reenvío de activación'],
];

foreach ($cases as [$event, $detail, $expectedAction]) {
    $service->registrar(null, $event, 'entidad', 7, $detail);
}

$logs = array_values(array_filter(
    $db->entries,
    static fn(array $entry): bool => str_contains($entry['query'], 'INSERT INTO audit_logs')
));
assertAuditService(count($logs) === count($cases), 'Each event must be written to audit_logs.');
foreach ($cases as $index => [, , $expectedAction]) {
    assertAuditService(
        $logs[$index]['params']['accion'] === $expectedAction,
        'Unexpected action label for ' . $cases[$index][0]
    );
}
assertAuditService(count($db->entries) === count($cases) * 2, 'Legacy auditoria entries must be preserved.');
assertAuditService($db->commits === count($cases), 'The service must commit transactions it owns.');

$detailDb = new AuditLogTestPDO();
(new AuditLogService($detailDb))->registrar(
    null,
    'usuario.actualizado',
    'usuarios',
    9,
    'email=persona@example.test; token=topsecret; rol=Coordinador'
);
$safeDetails = array_column(array_column($detailDb->entries, 'params'), 'detalle');
assertAuditService(
    $safeDetails[0] === '[dato omitido]; [dato omitido]; rol=Coordinador',
    'Email and token details must be redacted in both audit tables.'
);
assertAuditService($safeDetails[0] === $safeDetails[1], 'Legacy and central audit details must remain consistent.');

$legacyCallerDb = new AuditLogTestPDO();
(new Auditoria($legacyCallerDb))->registrar(null, 'api_token.revocado', 'api_access_tokens', 11, '', 'revocación');
$legacyCallerLogs = array_values(array_filter(
    $legacyCallerDb->entries,
    static fn(array $entry): bool => str_contains($entry['query'], 'INSERT INTO audit_logs')
));
assertAuditService(
    count($legacyCallerLogs) === 1 && $legacyCallerLogs[0]['params']['accion'] === 'revocación',
    'The Auditoria compatibility wrapper must use the central service and retain explicit action labels.'
);

$externalTransactionDb = new AuditLogTestPDO();
$externalTransactionDb->transaction = true;
$externalTransactionDb->failAuditLogInsert = true;
try {
    (new AuditLogService($externalTransactionDb))->registrar(null, 'evento', 'entidad', 1);
    throw new RuntimeException('Database audit failures must be propagated.');
} catch (PDOException $exception) {
    assertAuditService($externalTransactionDb->transaction, 'The caller-owned transaction must remain open.');
    assertAuditService($externalTransactionDb->rollbacks === 0, 'The service must not roll back a caller-owned transaction.');
}

$ownedTransactionDb = new AuditLogTestPDO();
$ownedTransactionDb->failAuditLogInsert = true;
try {
    (new AuditLogService($ownedTransactionDb))->registrar(null, 'evento', 'entidad', 1);
    throw new RuntimeException('Database audit failures must be propagated.');
} catch (PDOException $exception) {
    assertAuditService(!$ownedTransactionDb->transaction, 'The service-owned transaction must be rolled back on failure.');
    assertAuditService($ownedTransactionDb->rollbacks === 1, 'The service must roll back a failed owned transaction.');
}

echo "AuditLogService tests passed.\n";
