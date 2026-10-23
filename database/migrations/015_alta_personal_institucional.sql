ALTER TABLE usuarios
    ADD COLUMN nombre VARCHAR(50) DEFAULT NULL AFTER identificador_fiscal,
    ADD COLUMN apellidos VARCHAR(100) DEFAULT NULL AFTER nombre;

UPDATE usuarios u
INNER JOIN coordinadores c ON c.usuario_id = u.id
SET u.nombre = c.nombre,
    u.apellidos = c.apellidos
WHERE u.rol = 'Coordinador';

CREATE TABLE staff_activation_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT(11) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_activation_token_hash (token_hash),
    KEY idx_staff_activation_user (usuario_id),
    CONSTRAINT fk_staff_activation_user
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
