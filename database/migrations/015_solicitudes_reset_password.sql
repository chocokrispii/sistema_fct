CREATE TABLE IF NOT EXISTS solicitudes_reset_password (
    id INT AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT NOT NULL,
    email VARCHAR(255) NOT NULL,
    estado ENUM('Pendiente', 'Aprobada', 'Rechazada', 'Expirada', 'Usada')
        NOT NULL DEFAULT 'Pendiente',
    token VARCHAR(255) NULL,
    expira_en DATETIME NULL,
    solicitado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    aprobado_en TIMESTAMP NULL DEFAULT NULL,
    aprobado_por INT NULL,
    INDEX idx_reset_usuario_estado (usuario_id, estado),
    INDEX idx_reset_estado_fecha (estado, solicitado_en),
    UNIQUE KEY uq_reset_token (token),
    CONSTRAINT fk_reset_usuario FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_reset_aprobado_por FOREIGN KEY (aprobado_por)
        REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO solicitudes_reset_password (usuario_id, email, estado, solicitado_en)
SELECT r.usuario_id, u.email, 'Pendiente', MIN(r.creada)
FROM recuperaciones_cuenta r
INNER JOIN usuarios u ON u.id = r.usuario_id
WHERE r.estado = 'Solicitada'
  AND NOT EXISTS (
      SELECT 1 FROM solicitudes_reset_password sr
      WHERE sr.usuario_id = r.usuario_id AND sr.estado = 'Pendiente'
  )
GROUP BY r.usuario_id, u.email;

UPDATE recuperaciones_cuenta
SET estado = 'Usada', token_hash = NULL
WHERE estado = 'EnlaceEmitido';
