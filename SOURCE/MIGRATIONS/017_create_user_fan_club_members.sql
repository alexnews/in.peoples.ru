-- Migration 017: Create user_fan_club_members table
-- Replaces the old peoples_fan table
-- Stores fan club signups for individual persons

DROP TABLE IF EXISTS peoples_fan;

CREATE TABLE IF NOT EXISTS user_fan_club_members (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id         INT NOT NULL,
    email             VARCHAR(255) NOT NULL,
    name              VARCHAR(255) NOT NULL,
    message           TEXT DEFAULT NULL,
    status            ENUM('pending','confirmed','unsubscribed') DEFAULT 'pending',
    confirm_token     VARCHAR(64) NOT NULL,
    unsubscribe_token VARCHAR(64) NOT NULL,
    confirmed_at      DATETIME DEFAULT NULL,
    ip_address        VARCHAR(45) DEFAULT NULL,
    user_agent        VARCHAR(500) DEFAULT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY idx_person_email (person_id, email),
    KEY idx_person (person_id),
    KEY idx_status (status)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;

-- Grant access to in_peoples user
GRANT SELECT, INSERT, UPDATE, DELETE ON peoplesru.user_fan_club_members TO 'in_peoples'@'%';
