<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../Core/AuditLogService.php';

class Auditoria
{
    private AuditLogService $auditLogService;

    public function __construct(private PDO $db)
    {
        $this->auditLogService = new AuditLogService($db);
    }

    public function registrar(
        ?int $usuarioId,
        string $evento,
        string $entidad,
        ?int $entidadId,
        string $detalle = '',
        ?string $accion = null
    ): void
    {
        $this->auditLogService->registrar($usuarioId, $evento, $entidad, $entidadId, $detalle, $accion);
    }
}
