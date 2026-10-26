-- Permite crear el expediente mínimo del alumnado a partir de los datos disponibles en el centro.
SET @fct_ddl = IF(
    (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos'
       AND COLUMN_NAME = 'numero_seguridad_social') = 'NO',
    'ALTER TABLE alumnos MODIFY numero_seguridad_social VARCHAR(20) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos'
       AND COLUMN_NAME = 'curso') = 0,
    'ALTER TABLE alumnos ADD COLUMN curso TINYINT UNSIGNED NULL AFTER especialidad_id',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos'
       AND COLUMN_NAME = 'observaciones_administrativas') = 0,
    'ALTER TABLE alumnos ADD COLUMN observaciones_administrativas TEXT NULL AFTER curso',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
