-- Seed: Create initial admin user
-- Password: change_me_immediately (bcrypt hash)
-- IMPORTANT: Change the password after first login!

INSERT INTO users (username, email, password_hash, display_name, role, status)
VALUES (
    'admin',
    'alex@peoples.ru',
    '$2y$10$placeholder_change_this_after_setup',
    'Admin',
    'admin',
    'active'
) ON DUPLICATE KEY UPDATE id=id;
