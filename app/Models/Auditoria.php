<?php

require_once __DIR__ . '/../../config/database.php';

class Auditoria
{
    public function __construct(private PDO $db)
    {
    }

    public function registrar(?int $usuarioId, string $evento, string $entidad, ?int $entidadId, string $detalle = ''): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO auditoria (usuario_id, evento, entidad, entidad_id, detalle)
             VALUES (:usuario, :evento, :entidad, :id, :detalle)'
        );
        $stmt->execute([
            'usuario' => $usuarioId,
            'evento' => $evento,
            'entidad' => $entidad,
            'id' => $entidadId,
            'detalle' => $detalle !== '' ? $detalle : null,
        ]);
    }
}
