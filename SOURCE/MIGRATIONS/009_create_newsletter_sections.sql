-- Migration 009: Create user_newsletter_sections table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS user_newsletter_sections (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscriber_id   INT UNSIGNED NOT NULL,
    section_id      INT NOT NULL,

    UNIQUE KEY idx_sub_section (subscriber_id, section_id),
    CONSTRAINT fk_uns_subscriber FOREIGN KEY (subscriber_id)
        REFERENCES user_newsletter_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
