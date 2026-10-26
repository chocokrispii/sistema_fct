-- Añade los datos de alta pública y el flujo de aprobación del convenio marco.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'nombre_comercial') = 0,
    'ALTER TABLE empresas ADD COLUMN nombre_comercial VARCHAR(150) NULL AFTER nombre_empresa',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'representante_legal') = 0,
    'ALTER TABLE empresas ADD COLUMN representante_legal VARCHAR(150) NULL AFTER persona_contacto',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'dni_representante') = 0,
    'ALTER TABLE empresas ADD COLUMN dni_representante VARCHAR(20) NULL AFTER representante_legal',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'estado') = 0,
    'ALTER TABLE empresas ADD COLUMN estado ENUM(''Pendiente_Aprobacion'', ''Aprobada'', ''Rechazada'') NOT NULL DEFAULT ''Aprobada''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'numero_convenio') = 0,
    'ALTER TABLE empresas ADD COLUMN numero_convenio VARCHAR(100) NULL AFTER estado',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'pdf_convenio_path') = 0,
    'ALTER TABLE empresas ADD COLUMN pdf_convenio_path VARCHAR(255) NULL AFTER numero_convenio',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND COLUMN_NAME = 'fecha_aprobacion') = 0,
    'ALTER TABLE empresas ADD COLUMN fecha_aprobacion TIMESTAMP NULL DEFAULT NULL AFTER pdf_convenio_path',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

UPDATE empresas
SET nombre_comercial = nombre_empresa
WHERE nombre_comercial IS NULL OR TRIM(nombre_comercial) = '';

ALTER TABLE empresas
    MODIFY COLUMN estado ENUM('Pendiente_Aprobacion', 'Aprobada', 'Rechazada')
    NOT NULL DEFAULT 'Pendiente_Aprobacion';

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas'
       AND INDEX_NAME = 'idx_empresas_estado') = 0,
    'ALTER TABLE empresas ADD INDEX idx_empresas_estado (estado)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
