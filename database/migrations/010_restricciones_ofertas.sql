-- Refuerza en la base las reglas de plazas y fechas de las ofertas.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_NAME = 'chk_oferta_plazas_positivas'
       AND CONSTRAINT_TYPE = 'CHECK') = 0,
    'ALTER TABLE empresa_especialidades_plazas
     ADD CONSTRAINT chk_oferta_plazas_positivas CHECK (plazas_cuantas >= 1)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_NAME = 'chk_oferta_fechas'
       AND CONSTRAINT_TYPE = 'CHECK') = 0,
    'ALTER TABLE empresa_especialidades_plazas
     ADD CONSTRAINT chk_oferta_fechas CHECK (fecha_fin >= fecha_inicio)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
