-- Indexa los tokens y permite purgar solicitudes de recuperación antiguas.
-- Las solicitudes se conservan hasta 90 días desde su creación.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recuperaciones_cuenta'
       AND INDEX_NAME = 'uq_recuperacion_token_hash') = 0,
    'CREATE UNIQUE INDEX uq_recuperacion_token_hash ON recuperaciones_cuenta (token_hash)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'recuperaciones_cuenta'
       AND INDEX_NAME = 'idx_recuperacion_creada') = 0,
    'CREATE INDEX idx_recuperacion_creada ON recuperaciones_cuenta (creada)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
