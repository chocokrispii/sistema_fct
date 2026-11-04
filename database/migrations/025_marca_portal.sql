CREATE TABLE IF NOT EXISTS portal_branding (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    primary_color CHAR(7) NOT NULL DEFAULT '#004085',
    secondary_color CHAR(7) NOT NULL DEFAULT '#00A8E8',
    accent_color CHAR(7) NOT NULL DEFAULT '#A33A0A',
    logo_path VARCHAR(64) NULL,
    logo_mime VARCHAR(32) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_portal_branding_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO portal_branding (id) VALUES (1);
