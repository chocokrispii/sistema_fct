-- Corrige la errata de la columna en bases de datos creadas con el esquema anterior.
-- Compatible con MariaDB 10.4 (no admite RENAME COLUMN).
USE sistema_fct;

ALTER TABLE especialidades
    CHANGE nombre_specialidad nombre_especialidad VARCHAR(150) NOT NULL,
    DROP INDEX nombre_specialidad,
    ADD UNIQUE KEY nombre_especialidad (nombre_especialidad);
