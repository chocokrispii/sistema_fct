ALTER TABLE especialidades
    ADD COLUMN IF NOT EXISTS activo TINYINT(1) NOT NULL DEFAULT 1 AFTER nombre_especialidad;
