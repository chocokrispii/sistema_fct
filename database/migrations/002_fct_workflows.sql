-- Migra los flujos FCT y admite esquemas base que ya incluyan algunas columnas.
-- Ejecutar con sistema_fct (o una base de pruebas) seleccionada.
SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'activo') = 0,
    'ALTER TABLE usuarios ADD COLUMN activo BOOLEAN NOT NULL DEFAULT TRUE AFTER rol',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresas' AND COLUMN_NAME = 'persona_contacto') = 0,
    'ALTER TABLE empresas ADD COLUMN persona_contacto VARCHAR(150) NULL AFTER cif_empresa',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'alumnos' AND COLUMN_NAME = 'fecha_nacimiento') = 0,
    'ALTER TABLE alumnos ADD COLUMN fecha_nacimiento DATE NULL AFTER apellidos',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tutores_legales' AND COLUMN_NAME = 'dni') = 0,
    'ALTER TABLE tutores_legales ADD COLUMN dni VARCHAR(20) NULL AFTER nombre_completo',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tutores_legales'
       AND COLUMN_NAME = 'numero_seguridad_social') = 0,
    'ALTER TABLE tutores_legales ADD COLUMN numero_seguridad_social VARCHAR(20) NULL AFTER dni',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tutores_legales' AND COLUMN_NAME = 'correo') = 0,
    'ALTER TABLE tutores_legales ADD COLUMN correo VARCHAR(100) NULL AFTER telefono',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'estado') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN estado ENUM(''Pendiente'', ''Aprobada'', ''Rechazada'') NOT NULL DEFAULT ''Pendiente''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'turno') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN turno ENUM(''M'', ''T'', ''Indiferente'') NOT NULL DEFAULT ''Indiferente''',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'motivo_revision') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN motivo_revision VARCHAR(500) NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'revisado_por') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN revisado_por INT NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND COLUMN_NAME = 'revisado_en') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD COLUMN revisado_en DATETIME NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND CONSTRAINT_NAME = 'fk_oferta_revisor' AND CONSTRAINT_TYPE = 'FOREIGN KEY') = 0,
    'ALTER TABLE empresa_especialidades_plazas ADD CONSTRAINT fk_oferta_revisor FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

SET @fct_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_especialidades_plazas'
       AND INDEX_NAME = 'idx_ofertas_estado_fechas') = 0,
    'CREATE INDEX idx_ofertas_estado_fechas ON empresa_especialidades_plazas (estado, fecha_inicio, fecha_fin)',
    'SET @fct_noop = 1'
);
PREPARE fct_stmt FROM @fct_ddl;
EXECUTE fct_stmt;
DEALLOCATE PREPARE fct_stmt;

CREATE TABLE IF NOT EXISTS empresa_sedes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    nombre VARCHAR(150) NOT NULL,
    direccion VARCHAR(255) NOT NULL,
    telefono VARCHAR(20) NULL,
    activa BOOLEAN NOT NULL DEFAULT TRUE,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sede_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    INDEX idx_sedes_empresa (empresa_id, activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS convenios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT NOT NULL,
    sede_id INT NULL,
    numero_convenio VARCHAR(100) NOT NULL,
    fecha_firma DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    archivo_path VARCHAR(255) NOT NULL,
    estado ENUM('Pendiente', 'Aprobado', 'Rechazado') NOT NULL DEFAULT 'Pendiente',
    motivo_revision VARCHAR(500) NULL,
    revisado_por INT NULL,
    revisado_en DATETIME NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_convenio_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_convenio_sede
        FOREIGN KEY (sede_id) REFERENCES empresa_sedes(id) ON DELETE SET NULL,
    CONSTRAINT fk_convenio_revisor
        FOREIGN KEY (revisado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    UNIQUE KEY uq_convenio_empresa_numero (empresa_id, numero_convenio),
    INDEX idx_convenios_revision (estado, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alumno_disponibilidad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    alumno_id INT NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    turno ENUM('M', 'T', 'Indiferente') NOT NULL DEFAULT 'Indiferente',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disponibilidad_alumno
        FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE,
    CONSTRAINT chk_disponibilidad_fechas CHECK (fecha_fin >= fecha_inicio),
    INDEX idx_disponibilidad_alumno_fechas (alumno_id, fecha_inicio, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asignaciones_fct (
    id INT AUTO_INCREMENT PRIMARY KEY,
    oferta_id INT NOT NULL,
    alumno_id INT NOT NULL,
    tutor_empresa VARCHAR(150) NOT NULL,
    estado ENUM('Asignada', 'Cerrada', 'Cancelada') NOT NULL DEFAULT 'Asignada',
    fecha_asignacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    fecha_cierre DATETIME NULL,
    asignado_por INT NULL,
    CONSTRAINT fk_asignacion_oferta
        FOREIGN KEY (oferta_id) REFERENCES empresa_especialidades_plazas(id) ON DELETE RESTRICT,
    CONSTRAINT fk_asignacion_alumno
        FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE RESTRICT,
    CONSTRAINT fk_asignacion_coordinador
        FOREIGN KEY (asignado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
    UNIQUE KEY uq_asignacion_oferta_alumno (oferta_id, alumno_id),
    INDEX idx_asignaciones_estado (estado, oferta_id),
    INDEX idx_asignaciones_alumno (alumno_id, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auditoria (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NULL,
    evento VARCHAR(100) NOT NULL,
    entidad VARCHAR(100) NOT NULL,
    entidad_id INT NULL,
    detalle VARCHAR(500) NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_auditoria_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
    INDEX idx_auditoria_evento_fecha (evento, creado_en),
    INDEX idx_auditoria_usuario_fecha (usuario_id, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
