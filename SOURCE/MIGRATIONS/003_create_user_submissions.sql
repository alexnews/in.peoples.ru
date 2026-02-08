-- Migration 003: Create user_submissions table
-- Database: peoplesru
-- This is the moderation staging queue. Content lives here until approved,
-- then gets INSERTed into the target table via peoples_section.table_name mapping.

CREATE TABLE IF NOT EXISTS user_submissions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    section_id      INT NOT NULL COMMENT 'FK to peoples_section.id (2=histories, 3=photo, 4=news, etc.)',
    KodPersons      INT DEFAULT NULL COMMENT 'FK to persons.Persons_id',
    title           VARCHAR(500) DEFAULT NULL,
    content         MEDIUMTEXT DEFAULT NULL,
    epigraph        VARCHAR(1000) DEFAULT NULL,
    source_url      VARCHAR(500) DEFAULT NULL,
    photo_path      VARCHAR(500) DEFAULT NULL COMMENT 'For photo submissions: temp file path',
    status          ENUM('draft', 'pending', 'approved', 'rejected', 'revision_requested') DEFAULT 'draft',
    moderator_id    INT UNSIGNED DEFAULT NULL,
    moderator_note  TEXT DEFAULT NULL,
    reviewed_at     DATETIME DEFAULT NULL,
    published_id    INT DEFAULT NULL COMMENT 'ID in the target table after approval',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_section_id (section_id),
    KEY idx_person (KodPersons),
    KEY idx_created (created_at),
    CONSTRAINT fk_submission_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
