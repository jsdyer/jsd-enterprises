CREATE TABLE IF NOT EXISTS market_notice (
    id TINYINT UNSIGNED NOT NULL,
    message VARCHAR(280) NOT NULL,
    severity ENUM('info', 'warning') NOT NULL,
    expires_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT market_notice_singleton CHECK (id = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
