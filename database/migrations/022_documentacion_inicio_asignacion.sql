CREATE TABLE IF NOT EXISTS documentacion_inicio_fct (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    asignacion_id INT NOT NULL,
    alumno_id INT NOT NULL,
    tipo_documento ENUM('GUIA_INICIO', 'PLAN_FORMATIVO') NOT NULL,
    archivo_path VARCHAR(500) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_inicio_asignacion_tipo (asignacion_id, tipo_documento),
    KEY idx_inicio_alumno (alumno_id),
    CONSTRAINT fk_inicio_asignacion FOREIGN KEY (asignacion_id) REFERENCES asignaciones_fct(id) ON DELETE CASCADE,
    CONSTRAINT fk_inicio_alumno FOREIGN KEY (alumno_id) REFERENCES alumnos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
