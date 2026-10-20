-- Bandejas duraderas para eventos externos y notificaciones salientes.
-- Las firmas se verifican en PHP; los secretos nunca se almacenan en la base.
CREATE TABLE IF NOT EXISTS webhook_inbox (
    event_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    payload LONGTEXT NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_inbox_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_outbox (
    event_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    payload LONGTEXT NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_until DATETIME NULL,
    lock_token CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
    delivered_at DATETIME NULL,
    failed_at DATETIME NULL,
    http_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_webhook_outbox_due (delivered_at, failed_at, next_attempt_at, locked_until, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
