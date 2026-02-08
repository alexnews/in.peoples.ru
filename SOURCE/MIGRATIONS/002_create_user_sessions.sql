-- Migration 002: Create user_sessions table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS user_sessions (
    id              VARCHAR(128) PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    ip_address      VARCHAR(45) NOT NULL,
    user_agent      VARCHAR(255) DEFAULT NULL,
    last_activity   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_user_id (user_id),
    KEY idx_last_activity (last_activity),
    CONSTRAINT fk_session_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
