CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_usuario_admin INT(11) DEFAULT NULL,
    nombre_completo VARCHAR(200) NOT NULL,
    accion VARCHAR(50) NOT NULL,
    entidad_afectada VARCHAR(100) NOT NULL,
    id_registro_modificado BIGINT DEFAULT NULL,
    ip_address VARCHAR(45) NOT NULL,
    evento VARCHAR(100) NOT NULL,
    detalle VARCHAR(500) DEFAULT NULL,
    `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_logs_actor_timestamp (id_usuario_admin, `timestamp`),
    KEY idx_audit_logs_entity_timestamp (entidad_afectada, id_registro_modificado, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
