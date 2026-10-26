ALTER TABLE solicitudes_reset_password
    MODIFY COLUMN estado ENUM(
        'Pendiente_Aprobacion',
        'Pendiente',
        'Aprobada',
        'Rechazada',
        'Expirada',
        'Usada'
    ) NOT NULL DEFAULT 'Pendiente_Aprobacion';

UPDATE solicitudes_reset_password
SET estado = 'Pendiente_Aprobacion'
WHERE estado = 'Pendiente';

ALTER TABLE solicitudes_reset_password
    MODIFY COLUMN estado ENUM(
        'Pendiente_Aprobacion',
        'Aprobada',
        'Rechazada',
        'Expirada',
        'Usada'
    ) NOT NULL DEFAULT 'Pendiente_Aprobacion';
