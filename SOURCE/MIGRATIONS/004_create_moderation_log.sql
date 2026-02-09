-- Migration 004: Create users_moderation_log table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS users_moderation_log (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    moderator_id    INT UNSIGNED NOT NULL,
    action          ENUM('approve', 'reject', 'request_revision', 'ban_user', 'unban_user', 'promote', 'demote') NOT NULL,
    target_type     VARCHAR(50) NOT NULL COMMENT 'submission, user',
    target_id       INT UNSIGNED NOT NULL,
    note            TEXT DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_moderator (moderator_id),
    KEY idx_target (target_type, target_id),
    KEY idx_created (created_at),
    CONSTRAINT fk_modlog_moderator FOREIGN KEY (moderator_id) REFERENCES users(id)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
