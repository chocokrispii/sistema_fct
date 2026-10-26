-- Añade el nombre y el lugar para las ofertas registradas desde el centro.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'titulo') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN titulo VARCHAR(150) NOT NULL DEFAULT ''''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'lugar') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN lugar VARCHAR(255) NOT NULL DEFAULT ''''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
