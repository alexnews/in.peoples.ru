-- 006: Create user_person_suggestions table for staging person suggestions
-- Separate from user_submissions. Flow: User → Moderator (content quality) → Admin (push to persons)
CREATE TABLE IF NOT EXISTS user_person_suggestions (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED NOT NULL,

    -- Person fields (structured data)
    NameRus             VARCHAR(255) NOT NULL,
    SurNameRus          VARCHAR(255) NOT NULL,
    NameEngl            VARCHAR(255) DEFAULT NULL,
    SurNameEngl         VARCHAR(255) DEFAULT NULL,
    DateIn              DATE DEFAULT NULL,
    DateOut             DATE DEFAULT NULL,
    gender              CHAR(1) DEFAULT NULL,
    TownIn              VARCHAR(255) DEFAULT NULL,
    cc2born             CHAR(2) DEFAULT NULL,
    cc2dead             CHAR(2) DEFAULT NULL,
    cc2                 CHAR(2) DEFAULT NULL,

    -- Article fields
    title               VARCHAR(500) DEFAULT NULL COMMENT 'Article title (Заголовок)',
    epigraph            VARCHAR(1000) DEFAULT NULL COMMENT 'Short description / who is this person',

    -- Content
    biography           MEDIUMTEXT DEFAULT NULL COMMENT 'Biography text written by user',
    source_url          VARCHAR(500) DEFAULT NULL,

    -- Moderation (moderator checks content quality)
    status              ENUM('pending', 'approved', 'rejected', 'revision_requested', 'published') DEFAULT 'pending',
    moderator_id        INT UNSIGNED DEFAULT NULL,
    moderator_note      TEXT DEFAULT NULL,
    reviewed_at         DATETIME DEFAULT NULL,

    -- Admin push (admin checks duplicates, pushes to real persons table)
    published_person_id INT DEFAULT NULL COMMENT 'Persons_id after admin pushes to persons table',
    published_at        DATETIME DEFAULT NULL,

    -- Timestamps
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_status (status),
    KEY idx_created (created_at),
    CONSTRAINT fk_person_suggestion_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
