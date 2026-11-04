CREATE TABLE webhook_subscriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    endpoint_url VARCHAR(2048) NOT NULL,
    event_types VARCHAR(400) NOT NULL,
    secret_ciphertext TEXT NOT NULL,
    state ENUM('ACTIVE', 'DEGRADED', 'DISABLED') NOT NULL DEFAULT 'ACTIVE',
    failure_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_failure_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_webhook_subscriptions_state (state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    subscription_id INT UNSIGNED NOT NULL,
    event_id VARCHAR(36) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    payload LONGTEXT NOT NULL,
    state ENUM('PENDING', 'PROCESSING', 'DELIVERED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME DEFAULT NULL,
    http_status SMALLINT UNSIGNED DEFAULT NULL,
    last_error VARCHAR(1000) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    delivered_at DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_delivery_subscription_event (subscription_id, event_id),
    INDEX idx_webhook_delivery_queue (state, available_at, id),
    INDEX idx_webhook_delivery_subscription (subscription_id, created_at),
    CONSTRAINT fk_webhook_delivery_subscription
        FOREIGN KEY (subscription_id) REFERENCES webhook_subscriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
