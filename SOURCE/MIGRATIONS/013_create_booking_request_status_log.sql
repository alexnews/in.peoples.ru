-- Migration 013: Create booking_request_status_log table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS booking_request_status_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id      INT UNSIGNED NOT NULL,
    old_status      VARCHAR(20) DEFAULT NULL,
    new_status      VARCHAR(20) NOT NULL,
    note            TEXT DEFAULT NULL,
    changed_by      INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_request_id (request_id),
    CONSTRAINT fk_brsl_request FOREIGN KEY (request_id) REFERENCES booking_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_brsl_changed_by FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
