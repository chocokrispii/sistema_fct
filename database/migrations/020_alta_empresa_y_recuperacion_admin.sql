-- Alta pública de empresas y recuperación de contraseña aprobada por administración.
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'estado_solicitud') = 0,
    'ALTER TABLE empresas ADD COLUMN estado_solicitud ENUM(''Pendiente_Aprobacion'', ''Aprobada'', ''Rechazada'') NOT NULL DEFAULT ''Aprobada'' AFTER persona_contacto', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'numero_convenio_marco') = 0,
    'ALTER TABLE empresas ADD COLUMN numero_convenio_marco VARCHAR(100) NULL AFTER estado_solicitud', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'archivo_convenio_marco') = 0,
    'ALTER TABLE empresas ADD COLUMN archivo_convenio_marco VARCHAR(255) NULL AFTER numero_convenio_marco', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'aprobada_por') = 0,
    'ALTER TABLE empresas ADD COLUMN aprobada_por INT NULL AFTER archivo_convenio_marco', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'aprobada_en') = 0,
    'ALTER TABLE empresas ADD COLUMN aprobada_en DATETIME NULL AFTER aprobada_por', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND INDEX_NAME = 'idx_empresas_estado_solicitud') = 0,
    'CREATE INDEX idx_empresas_estado_solicitud ON empresas (estado_solicitud)', 'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
