SET @fct_add_es_indiferente = (
    SELECT COUNT(*) = 0
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'alumno_disponibilidad'
      AND COLUMN_NAME = 'es_indiferente'
);

SET @fct_ddl = IF(
    @fct_add_es_indiferente,
    'ALTER TABLE alumno_disponibilidad
        ADD COLUMN es_indiferente BOOLEAN NOT NULL DEFAULT FALSE AFTER turno',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

UPDATE alumno_disponibilidad
SET es_indiferente = (turno = 'Indiferente')
WHERE @fct_add_es_indiferente = 1;
