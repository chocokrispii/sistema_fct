SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'titulo') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN titulo VARCHAR(150) NOT NULL DEFAULT ''Oferta FCT''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'descripcion') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN descripcion TEXT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'modalidad') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN modalidad ENUM(''Presencial'', ''Remoto'', ''Híbrido'') NOT NULL DEFAULT ''Presencial''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'ubicacion') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN ubicacion VARCHAR(255) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'requisitos') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN requisitos TEXT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'tecnologias') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN tecnologias VARCHAR(500) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'turno') > 0
    AND (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
           AND COLUMN_NAME = 'turno') NOT LIKE '%''P''%',
    'ALTER TABLE empresa_especialidades_plazas MODIFY COLUMN turno ENUM(''M'', ''T'', ''P'', ''Indiferente'') NOT NULL DEFAULT ''Indiferente''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
