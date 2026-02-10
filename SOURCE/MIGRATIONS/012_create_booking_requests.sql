-- Migration 012: Create booking_requests table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS booking_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id           INT DEFAULT NULL,
    booking_person_id   INT UNSIGNED DEFAULT NULL,

    -- Client info
    client_name         VARCHAR(255) NOT NULL,
    client_phone        VARCHAR(50) NOT NULL,
    client_email        VARCHAR(255) DEFAULT NULL,
    client_company      VARCHAR(255) DEFAULT NULL,

    -- Event details
    event_type          VARCHAR(100) DEFAULT NULL,
    event_date          DATE DEFAULT NULL,
    event_city          VARCHAR(255) DEFAULT NULL,
    event_venue         VARCHAR(500) DEFAULT NULL,
    guest_count         INT UNSIGNED DEFAULT NULL,
    budget_from         INT UNSIGNED DEFAULT NULL,
    budget_to           INT UNSIGNED DEFAULT NULL,
    message             TEXT DEFAULT NULL,

    -- Processing
    status              ENUM('new', 'in_progress', 'contacted', 'completed', 'cancelled', 'spam') DEFAULT 'new',
    admin_note          TEXT DEFAULT NULL,
    assigned_to         INT UNSIGNED DEFAULT NULL,
    ip_address          VARCHAR(45) DEFAULT NULL,
    user_agent          VARCHAR(500) DEFAULT NULL,

    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_person_id (person_id),
    KEY idx_booking_person_id (booking_person_id),
    KEY idx_status (status),
    KEY idx_created (created_at),
    KEY idx_assigned (assigned_to),
    CONSTRAINT fk_br_booking_person FOREIGN KEY (booking_person_id) REFERENCES booking_persons(id) ON DELETE SET NULL,
    CONSTRAINT fk_br_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
