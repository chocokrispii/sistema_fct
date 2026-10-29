-- Evaluación final entregada por la empresa para una asignación FCT.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_dictamen') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_dictamen ENUM(''Apto'', ''No Apto'') NULL AFTER estado',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_archivo_path') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_archivo_path VARCHAR(500) NULL AFTER evaluacion_dictamen',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_nombre_original') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_nombre_original VARCHAR(255) NULL AFTER evaluacion_archivo_path',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_mime_type') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_mime_type VARCHAR(100) NULL AFTER evaluacion_nombre_original',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_tamano_bytes') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_tamano_bytes INT UNSIGNED NULL AFTER evaluacion_mime_type',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'asignaciones_fct' AND COLUMN_NAME = 'evaluacion_subida_en') = 0,
    'ALTER TABLE asignaciones_fct ADD COLUMN evaluacion_subida_en DATETIME NULL AFTER evaluacion_tamano_bytes',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl; EXECUTE fct_stmt; DEALLOCATE PREPARE fct_stmt;
