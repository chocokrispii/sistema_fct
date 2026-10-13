-- Añade el código postal para permitir validar y actualizar la dirección del alumno.
-- Ejecutar con la base sistema_fct (o una base de pruebas) seleccionada.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos'
       AND COLUMN_NAME = 'codigo_postal') = 0,
    'ALTER TABLE alumnos ADD COLUMN codigo_postal CHAR(5) NULL AFTER numero_seguridad_social',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
