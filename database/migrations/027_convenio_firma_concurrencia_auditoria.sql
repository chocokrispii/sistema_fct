SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firma_estado') = 0,
    "ALTER TABLE convenios ADD COLUMN firma_estado VARCHAR(32) NOT NULL DEFAULT 'PENDIENTE_APROBACION'",
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firma_lease_token_hash') = 0,
    'ALTER TABLE convenios ADD COLUMN firma_lease_token_hash CHAR(64) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firma_lease_usuario_id') = 0,
    'ALTER TABLE convenios ADD COLUMN firma_lease_usuario_id INT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'convenios' AND COLUMN_NAME = 'firma_lease_expira_en') = 0,
    'ALTER TABLE convenios ADD COLUMN firma_lease_expira_en DATETIME(6) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

CREATE TABLE IF NOT EXISTS convenio_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    convenio_id INT NOT NULL,
    usuario_id INT NULL,
    evento VARCHAR(64) NOT NULL,
    hash_documento_original CHAR(64) NULL,
    hash_documento_firmado CHAR(64) NULL,
    detalle JSON NULL,
    creado_en DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_convenio_audit_logs_convenio_fecha (convenio_id, creado_en),
    KEY idx_convenio_audit_logs_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO convenio_audit_logs (
    convenio_id,
    usuario_id,
    evento,
    hash_documento_original,
    hash_documento_firmado,
    detalle
)
SELECT
    c.id,
    c.firma_aprobada_por,
    'FIRMA_ANTERIOR_REQUIERE_REVALIDACION',
    c.hash_documento_original,
    c.hash_documento_firmado,
    JSON_OBJECT(
        'motivo', 'La aprobación anterior no acredita una firma PAdES validada.',
        'archivo_firmado_anterior', c.documento_firmado_path
    )
FROM convenios c
WHERE c.estado = 'Aprobado'
  AND c.documento_firmado_path IS NOT NULL
  AND c.firma_estado = 'PENDIENTE_APROBACION'
  AND NOT EXISTS (
      SELECT 1
      FROM convenio_audit_logs a
      WHERE a.convenio_id = c.id
        AND a.evento = 'FIRMA_ANTERIOR_REQUIERE_REVALIDACION'
        AND a.hash_documento_firmado <=> c.hash_documento_firmado
  );

UPDATE convenios
SET estado = 'Pendiente',
    firma_estado = 'PENDIENTE_APROBACION',
    documento_firmado_path = NULL,
    hash_documento_original = NULL,
    hash_documento_firmado = NULL,
    firma_aprobada_por = NULL,
    firmada_en_utc = NULL,
    revisado_por = NULL,
    revisado_en = NULL,
    motivo_revision = 'La firma previa requiere revalidación criptográfica PAdES.'
WHERE estado = 'Aprobado'
  AND documento_firmado_path IS NOT NULL
  AND firma_estado = 'PENDIENTE_APROBACION';
