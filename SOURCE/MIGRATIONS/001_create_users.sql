-- Migration 001: Create users table
-- Database: peoplesru

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username        VARCHAR(50)  NOT NULL,
    email           VARCHAR(255) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    display_name    VARCHAR(100) NOT NULL,
    avatar_path     VARCHAR(255) DEFAULT NULL,
    role            ENUM('user', 'moderator', 'admin') DEFAULT 'user',
    status          ENUM('active', 'banned', 'suspended') DEFAULT 'active',
    reputation      INT DEFAULT 0,
    bio             TEXT DEFAULT NULL,
    last_login      DATETIME DEFAULT NULL,
    login_ip        VARCHAR(45) DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_username (username),
    UNIQUE KEY idx_email (email),
    KEY idx_role (role),
    KEY idx_status (status)
) ENGINE=InnoDB CHARACTER SET cp1251 COLLATE cp1251_general_ci;
