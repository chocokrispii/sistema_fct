-- Límite de intentos de login y recuperación verificada por el centro.
-- Ejecutar después de 002_fct_workflows.sql. Se puede lanzar más de una vez.
CREATE TABLE IF NOT EXISTS intentos_login (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    fecha DATETIME NOT NULL,
    INDEX idx_intentos_email_fecha (email, fecha),
    INDEX idx_intentos_ip_fecha (ip, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recuperaciones_cuenta (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    estado ENUM('Solicitada', 'EnlaceEmitido', 'Usada') NOT NULL DEFAULT 'Solicitada',
    token_hash CHAR(64) NULL,
    expira DATETIME NULL,
    creada DATETIME NOT NULL,
    INDEX idx_recuperacion_usuario (usuario_id, estado),
    CONSTRAINT fk_recuperacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
