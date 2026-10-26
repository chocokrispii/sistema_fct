-- Guarda la modalidad de jornada y, para jornada partida, una segunda franja diaria.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'jornada') = 0,
    'ALTER TABLE empresa_especialidades_plazas
     ADD COLUMN jornada ENUM(''normal'', ''partida'') NOT NULL DEFAULT ''normal''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_oferta_horarios_semanales'
       AND COLUMN_NAME = 'hora_inicio_2') = 0,
    'ALTER TABLE empresa_oferta_horarios_semanales ADD COLUMN hora_inicio_2 TIME NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_oferta_horarios_semanales'
       AND COLUMN_NAME = 'hora_fin_2') = 0,
    'ALTER TABLE empresa_oferta_horarios_semanales ADD COLUMN hora_fin_2 TIME NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND TABLE_NAME = 'empresa_oferta_horarios_semanales'
       AND CONSTRAINT_NAME = 'chk_oferta_segunda_franja'
       AND CONSTRAINT_TYPE = 'CHECK') = 0,
    'ALTER TABLE empresa_oferta_horarios_semanales
     ADD CONSTRAINT chk_oferta_segunda_franja CHECK (
         (hora_inicio_2 IS NULL AND hora_fin_2 IS NULL)
         OR (hora_inicio_2 IS NOT NULL AND hora_fin_2 IS NOT NULL
             AND hora_inicio_2 >= hora_fin AND hora_fin_2 > hora_inicio_2)
     )',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;
