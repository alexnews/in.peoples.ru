-- Migration 011: Create booking_persons table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS booking_persons (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id       INT NOT NULL,
    category_id     INT UNSIGNED NOT NULL,
    price_from      INT UNSIGNED DEFAULT NULL,
    price_to        INT UNSIGNED DEFAULT NULL,
    description     TEXT DEFAULT NULL,
    short_desc      VARCHAR(500) DEFAULT NULL,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    is_featured     TINYINT(1) NOT NULL DEFAULT 0,
    sort_order      INT UNSIGNED NOT NULL DEFAULT 0,
    added_by        INT UNSIGNED DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_person_category (person_id, category_id),
    KEY idx_person_id (person_id),
    KEY idx_category_id (category_id),
    KEY idx_active (is_active),
    KEY idx_featured (is_featured),
    KEY idx_price (price_from),
    CONSTRAINT fk_bp_category FOREIGN KEY (category_id) REFERENCES booking_categories(id),
    CONSTRAINT fk_bp_added_by FOREIGN KEY (added_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
