CREATE TABLE activation_resend_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT(11) NOT NULL,
    estado ENUM('Pendiente de Reenvío', 'Reenviada') NOT NULL DEFAULT 'Pendiente de Reenvío',
    creada TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resuelta DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_activation_resend_status_created (estado, creada),
    KEY idx_activation_resend_user_status (usuario_id, estado),
    CONSTRAINT fk_activation_resend_user
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
