-- Migration 015: Create user_info_change_requests table
-- Stores requests from celebrities/managers/fans to correct person profile info

CREATE TABLE IF NOT EXISTS user_info_change_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id           INT DEFAULT NULL,
    person_name_manual  VARCHAR(255) DEFAULT NULL,
    requester_name      VARCHAR(255) NOT NULL,
    requester_phone     VARCHAR(50) NOT NULL,
    requester_email     VARCHAR(255) DEFAULT NULL,
    requester_role      ENUM('self','manager','relative','fan','other') DEFAULT 'other',
    change_fields       VARCHAR(500) DEFAULT NULL,
    description         TEXT NOT NULL,
    evidence_url        VARCHAR(500) DEFAULT NULL,
    status              ENUM('new','in_progress','completed','rejected','spam') DEFAULT 'new',
    admin_note          TEXT DEFAULT NULL,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    ip_address          VARCHAR(45) DEFAULT NULL,
    user_agent          VARCHAR(500) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_status (status),
    KEY idx_created (created_at),
    KEY idx_person (person_id),
    CONSTRAINT fk_icr_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
