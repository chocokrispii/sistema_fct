SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'sede_id') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN sede_id INT NULL AFTER empresa_id',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

INSERT INTO empresa_sedes (empresa_id, nombre, direccion, telefono)
SELECT p.empresa_id,
       CONCAT('Sede de oferta ', MIN(p.id)),
       LEFT(p.ubicacion, 255),
       MAX(d.telefono)
FROM empresa_especialidades_plazas p
LEFT JOIN (
    SELECT empresa_id, MAX(telefono) AS telefono
    FROM direcciones_telefonos
    WHERE empresa_id IS NOT NULL
    GROUP BY empresa_id
) d ON d.empresa_id = p.empresa_id
WHERE p.sede_id IS NULL
  AND NULLIF(TRIM(p.ubicacion), '') IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM empresa_sedes s
      WHERE s.empresa_id = p.empresa_id AND s.activa = 1
        AND s.direccion COLLATE utf8mb4_general_ci = p.ubicacion
  )
GROUP BY p.empresa_id, p.ubicacion;

INSERT INTO empresa_sedes (empresa_id, nombre, direccion, telefono)
SELECT p.empresa_id,
       'Sede principal',
       LEFT(COALESCE(NULLIF(MAX(d.direccion_completa), ''), 'Ubicación pendiente de actualizar'), 255),
       MAX(d.telefono)
FROM empresa_especialidades_plazas p
LEFT JOIN direcciones_telefonos d ON d.empresa_id = p.empresa_id
WHERE p.sede_id IS NULL
GROUP BY p.empresa_id
HAVING NOT EXISTS (
    SELECT 1 FROM empresa_sedes s
    WHERE s.empresa_id = p.empresa_id AND s.activa = 1
);

UPDATE empresa_especialidades_plazas p
INNER JOIN empresa_sedes s
    ON s.empresa_id = p.empresa_id AND s.activa = 1
       AND s.direccion COLLATE utf8mb4_general_ci = p.ubicacion
SET p.sede_id = s.id
WHERE p.sede_id IS NULL;

UPDATE empresa_especialidades_plazas p
INNER JOIN (
    SELECT empresa_id, MIN(id) AS sede_id
    FROM empresa_sedes
    WHERE activa = 1
    GROUP BY empresa_id
) s ON s.empresa_id = p.empresa_id
SET p.sede_id = s.sede_id
WHERE p.sede_id IS NULL;

SET @fct_ddl = IF(
    (SELECT IS_NULLABLE FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'sede_id') = 'YES',
    'ALTER TABLE empresa_especialidades_plazas MODIFY COLUMN sede_id INT NOT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_sedes'
       AND INDEX_NAME = 'uq_empresa_sedes_empresa_id') = 0,
    'CREATE UNIQUE INDEX uq_empresa_sedes_empresa_id ON empresa_sedes (empresa_id, id)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_NAME = 'fk_oferta_sede_empresa' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE empresa_especialidades_plazas
       ADD CONSTRAINT fk_oferta_sede_empresa
       FOREIGN KEY (empresa_id, sede_id) REFERENCES empresa_sedes (empresa_id, id)
       ON DELETE RESTRICT ON UPDATE CASCADE',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
