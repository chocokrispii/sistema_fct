-- Persistencia de franjas semanales del alumnado y calificación final de FCT.
-- Ejecutar con la base sistema_fct (o una base de pruebas) seleccionada.
CREATE TABLE IF NOT EXISTS alumno_disponibilidad_semanal (
    id INT AUTO_INCREMENT PRIMARY KEY,
    disponibilidad_id INT NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL COMMENT '1=lunes ... 7=domingo',
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_disponibilidad_semanal_periodo
        FOREIGN KEY (disponibilidad_id) REFERENCES alumno_disponibilidad(id) ON DELETE CASCADE,
    CONSTRAINT chk_disponibilidad_semanal_dia CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT chk_disponibilidad_semanal_horas CHECK (hora_fin > hora_inicio),
    UNIQUE KEY uq_disponibilidad_semanal_dia (disponibilidad_id, dia_semana),
    INDEX idx_disponibilidad_semanal_dia_horas (dia_semana, hora_inicio, hora_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calificaciones_fct (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asignacion_id INT NOT NULL,
    calificador_id INT NOT NULL,
    nota DECIMAL(4,2) NOT NULL,
    observaciones VARCHAR(2000) NULL,
    calificada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_calificacion_asignacion
        FOREIGN KEY (asignacion_id) REFERENCES asignaciones_fct(id) ON DELETE CASCADE,
    CONSTRAINT fk_calificacion_calificador
        FOREIGN KEY (calificador_id) REFERENCES usuarios(id) ON DELETE RESTRICT,
    CONSTRAINT chk_calificacion_nota CHECK (nota >= 0 AND nota <= 10),
    UNIQUE KEY uq_calificacion_asignacion (asignacion_id),
    INDEX idx_calificaciones_calificador (calificador_id, calificada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
