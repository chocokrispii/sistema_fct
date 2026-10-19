-- Corrige la errata de la columna en bases de datos creadas con el esquema anterior.
-- Compatible con MariaDB 10.4 (no admite RENAME COLUMN).
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'especialidades'
       AND COLUMN_NAME = 'nombre_specialidad') = 0
    AND (SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'especialidades'
           AND COLUMN_NAME = 'nombre_especialidad') = 1,
    'SET @fct_noop = 1',
    'ALTER TABLE especialidades
        CHANGE nombre_specialidad nombre_especialidad VARCHAR(150) NOT NULL,
        DROP INDEX nombre_specialidad,
        ADD UNIQUE KEY nombre_especialidad (nombre_especialidad)'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
