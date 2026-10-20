CREATE TABLE IF NOT EXISTS empresa_oferta_horarios_semanales (
    id INT NOT NULL AUTO_INCREMENT,
    oferta_id INT NOT NULL,
    dia_semana TINYINT UNSIGNED NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_fin TIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oferta_dia_horario (oferta_id, dia_semana),
    CONSTRAINT fk_oferta_horarios_oferta
        FOREIGN KEY (oferta_id) REFERENCES empresa_especialidades_plazas(id)
        ON DELETE CASCADE,
    CONSTRAINT chk_oferta_horario_dia CHECK (dia_semana BETWEEN 1 AND 7),
    CONSTRAINT chk_oferta_horario_rango CHECK (hora_inicio < hora_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
