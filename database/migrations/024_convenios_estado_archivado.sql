-- Añade el estado de archivo conservando los estados vigentes.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'convenios'
       AND COLUMN_NAME = 'estado'
       AND COLUMN_TYPE LIKE '%Archivado%') = 0,
    'ALTER TABLE convenios MODIFY estado ENUM(''Pendiente'', ''Aprobado'', ''Rechazado'', ''Archivado'') NOT NULL DEFAULT ''Pendiente''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
