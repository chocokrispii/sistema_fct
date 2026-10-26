SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'fecha_firma_convenio') = 0,
    'ALTER TABLE empresas
     ADD COLUMN fecha_firma_convenio TIMESTAMP NULL DEFAULT NULL AFTER fecha_aprobacion',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
