-- Migration 008: Create user_newsletter_subscribers table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS user_newsletter_subscribers (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id           INT UNSIGNED DEFAULT NULL,
    email             VARCHAR(255) NOT NULL,
    frequency         ENUM('daily', 'weekly') NOT NULL DEFAULT 'weekly',
    status            ENUM('pending', 'confirmed', 'unsubscribed', 'paused') DEFAULT 'pending',
    bounce_count      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    confirm_token     VARCHAR(64) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    confirmed_at      DATETIME DEFAULT NULL,
    last_sent_at      DATETIME DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_email (email),
    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_last_sent (last_sent_at),
    CONSTRAINT fk_uns_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
