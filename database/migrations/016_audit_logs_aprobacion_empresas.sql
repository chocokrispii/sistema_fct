CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_usuario_admin INT NOT NULL,
    nombre_completo VARCHAR(255) NOT NULL,
    accion VARCHAR(100) NOT NULL,
    entidad_afectada VARCHAR(100) NOT NULL,
    id_registro_modificado INT NOT NULL,
    ip_address VARCHAR(45) NULL,
    `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_accion_timestamp (accion, `timestamp`),
    INDEX idx_audit_logs_usuario_timestamp (id_usuario_admin, `timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
