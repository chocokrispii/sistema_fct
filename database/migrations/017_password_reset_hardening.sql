SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
       AND COLUMN_NAME = 'session_version') = 0,
    'ALTER TABLE usuarios ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

CREATE TABLE IF NOT EXISTS intentos_reset_password (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identificador_hash CHAR(64) NOT NULL,
    ip_hash CHAR(64) NOT NULL,
    solicitado_en DATETIME NOT NULL,
    INDEX idx_reset_intentos_identificador_fecha (identificador_hash, solicitado_en),
    INDEX idx_reset_intentos_ip_fecha (ip_hash, solicitado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
