-- Migration 014: Create booking_applications table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS booking_applications (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name           VARCHAR(255) NOT NULL,
    phone               VARCHAR(50) NOT NULL,
    email               VARCHAR(255) DEFAULT NULL,
    city                VARCHAR(255) DEFAULT NULL,
    category_id         INT UNSIGNED DEFAULT NULL,
    activity_description TEXT DEFAULT NULL,
    person_id           INT DEFAULT NULL,
    status              ENUM('new','contacted','approved','rejected','spam') DEFAULT 'new',
    admin_note          TEXT DEFAULT NULL,
    reviewed_by         INT UNSIGNED DEFAULT NULL,
    booking_person_id   INT UNSIGNED DEFAULT NULL,
    ip_address          VARCHAR(45) DEFAULT NULL,
    user_agent          VARCHAR(500) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_status (status),
    KEY idx_created (created_at),
    CONSTRAINT fk_ba_category FOREIGN KEY (category_id) REFERENCES booking_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_ba_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_ba_booking_person FOREIGN KEY (booking_person_id) REFERENCES booking_persons(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
