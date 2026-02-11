CREATE TABLE IF NOT EXISTS user_ad_requests (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ad_type           ENUM('banner','content','sponsorship','other') DEFAULT 'other',
    company_name      VARCHAR(255) DEFAULT NULL,
    contact_name      VARCHAR(255) NOT NULL,
    contact_phone     VARCHAR(50) NOT NULL,
    contact_email     VARCHAR(255) DEFAULT NULL,
    message           TEXT NOT NULL,
    budget            VARCHAR(100) DEFAULT NULL,
    status            ENUM('new','in_progress','completed','rejected','spam') DEFAULT 'new',
    admin_note        TEXT DEFAULT NULL,
    reviewed_by       INT UNSIGNED DEFAULT NULL,
    ip_address        VARCHAR(45) DEFAULT NULL,
    user_agent        VARCHAR(500) DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_status (status),
    KEY idx_created (created_at),
    CONSTRAINT fk_adr_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
