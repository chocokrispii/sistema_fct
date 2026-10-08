-- Asocia cada convenio nuevo a un ciclo formativo.
-- Los convenios preexistentes permanecen sin clasificar (NULL) hasta su revisión.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios'
       AND COLUMN_NAME = 'especialidad_id') = 0,
    'ALTER TABLE convenios ADD COLUMN especialidad_id INT NULL AFTER empresa_id',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios'
       AND INDEX_NAME = 'idx_convenios_especialidad') = 0,
    'CREATE INDEX idx_convenios_especialidad ON convenios (especialidad_id)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios'
       AND CONSTRAINT_NAME = 'fk_convenio_especialidad'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE convenios ADD CONSTRAINT fk_convenio_especialidad FOREIGN KEY (especialidad_id) REFERENCES especialidades(id) ON DELETE RESTRICT',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios'
       AND INDEX_NAME = 'uq_convenio_empresa_especialidad') = 0,
    'CREATE UNIQUE INDEX uq_convenio_empresa_especialidad ON convenios (empresa_id, especialidad_id)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
