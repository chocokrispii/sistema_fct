SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'documento_firmado_path') = 0,
    'ALTER TABLE convenios ADD COLUMN documento_firmado_path VARCHAR(255) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'hash_documento_original') = 0,
    'ALTER TABLE convenios ADD COLUMN hash_documento_original CHAR(64) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'hash_documento_firmado') = 0,
    'ALTER TABLE convenios ADD COLUMN hash_documento_firmado CHAR(64) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firma_aprobada_por') = 0,
    'ALTER TABLE convenios ADD COLUMN firma_aprobada_por INT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firmada_en_utc') = 0,
    'ALTER TABLE convenios ADD COLUMN firmada_en_utc DATETIME(6) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
