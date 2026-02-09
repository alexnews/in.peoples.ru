<?php

declare(strict_types=1);

/**
 * Authentication functions: registration, login, logout, profile updates.
 *
 * All public-facing data is returned in UTF-8. All writes convert to CP1251.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encoding.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/validation.php';

/**
 * Register a new user account.
 *
 * Validates input, checks for duplicate username/email, hashes password,
 * inserts the user, and returns the new user row (UTF-8 encoded).
 *
 * @param string $username Desired username (UTF-8)
 * @param string $email Email address
 * @param string $password Plain-text password
 * @param string $displayName Display name (UTF-8)
 * @return array User data row (UTF-8 encoded)
 * @throws InvalidArgumentException On validation failure
 * @throws RuntimeException On duplicate username/email or database error
 */
function registerUser(string $username, string $email, string $password, string $displayName): array
{
    // Trim inputs
    $username = trim($username);
    $email = trim($email);
    $displayName = trim($displayName);

    // Validate all fields
    $errors = [];

    $usernameCheck = validateUsername($username);
    if ($usernameCheck !== true) {
        $errors['username'] = $usernameCheck;
    }

    if (!validateEmail($email)) {
        $errors['email'] = 'Invalid email address';
    }

    $passwordCheck = validatePassword($password);
    if ($passwordCheck !== true) {
        $errors['password'] = $passwordCheck;
    }

    if (mb_strlen($displayName, 'UTF-8') < 2) {
        $errors['display_name'] = 'Display name must be at least 2 characters long';
    }

    if (mb_strlen($displayName, 'UTF-8') > 100) {
        $errors['display_name'] = 'Display name must not exceed 100 characters';
    }

    if (!empty($errors)) {
        throw new InvalidArgumentException(
            json_encode($errors, JSON_UNESCAPED_UNICODE)
        );
    }

    $db = getDb();

    // Check for existing username (case-insensitive)
    $stmt = $db->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => toDb($username)]);
    if ($stmt->fetch()) {
        throw new RuntimeException(
            json_encode(['username' => 'This username is already taken'], JSON_UNESCAPED_UNICODE)
        );
    }

    // Check for existing email
    $stmt = $db->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => toDb($email)]);
    if ($stmt->fetch()) {
        throw new RuntimeException(
            json_encode(['email' => 'This email is already registered'], JSON_UNESCAPED_UNICODE)
        );
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Insert user
    $stmt = $db->prepare(
        'INSERT INTO users (username, email, password_hash, display_name, role, status, reputation, created_at, updated_at)
         VALUES (:username, :email, :password_hash, :display_name, :role, :status, 0, NOW(), NOW())'
    );
    $stmt->execute([
        ':username'      => toDb($username),
        ':email'         => toDb($email),
        ':password_hash' => $passwordHash,
        ':display_name'  => toDb($displayName),
        ':role'          => 'user',
        ':status'        => 'active',
    ]);

    $userId = (int) $db->lastInsertId();

    // Fetch and return the created user
    return getUserById($userId);
}

/**
 * Authenticate a user by email or username and password.
 *
 * On success, creates a new session and returns the user row.
 *
 * @param string $login Email address or username
 * @param string $password Plain-text password
 * @return array User data row (UTF-8 encoded)
 * @throws InvalidArgumentException If credentials are invalid
 * @throws RuntimeException If user is banned or suspended
 */
function loginUser(string $login, string $password): array
{
    $login = trim($login);

    if ($login === '' || $password === '') {
        throw new InvalidArgumentException('Login and password are required');
    }

    $db = getDb();

    // Try to find user by email or username
    $loginDb = toDb($login);
    $stmt = $db->prepare(
        'SELECT id, username, email, password_hash, display_name, avatar_path,
                role, status, reputation, bio, last_login, login_ip, created_at, updated_at
         FROM users
         WHERE email = :login_email OR username = :login_user
         LIMIT 1'
    );
    $stmt->execute([':login_email' => $loginDb, ':login_user' => $loginDb]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new InvalidArgumentException('Invalid login credentials');
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        throw new InvalidArgumentException('Invalid login credentials');
    }

    // Check user status
    if ($user['status'] === 'banned') {
        throw new RuntimeException('Your account has been banned');
    }

    if ($user['status'] === 'suspended') {
        throw new RuntimeException('Your account is currently suspended');
    }

    // Update last login info
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $updateStmt = $db->prepare(
        'UPDATE users SET last_login = NOW(), login_ip = :ip WHERE id = :id'
    );
    $updateStmt->execute([
        ':ip' => $ip,
        ':id' => $user['id'],
    ]);

    // Create session
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    startSession((int) $user['id'], $ip, $userAgent);

    // Return user data (without password_hash)
    unset($user['password_hash']);
    return fromDbArray($user);
}

/**
 * Log out the current user by destroying the active session.
 *
 * @return void
 */
function logoutUser(): void
{
    destroySession();
}

/**
 * Update a user's profile fields.
 *
 * Allowed fields: display_name, bio, avatar_path, email.
 * Password changes are handled separately to enforce old-password verification.
 *
 * @param int $userId User ID to update
 * @param array $data Associative array of fields to update
 * @return array Updated user data row (UTF-8 encoded)
 * @throws InvalidArgumentException On validation failure
 * @throws RuntimeException On database error or duplicate email
 */
function updateProfile(int $userId, array $data): array
{
    $allowedFields = ['display_name', 'bio', 'avatar_path', 'email'];
    $errors = [];
    $updates = [];
    $params = [':id' => $userId];

    foreach ($data as $field => $value) {
        if (!in_array($field, $allowedFields, true)) {
            continue;
        }

        switch ($field) {
            case 'display_name':
                $value = trim((string) $value);
                if (mb_strlen($value, 'UTF-8') < 2) {
                    $errors['display_name'] = 'Display name must be at least 2 characters long';
                    continue 2;
                }
                if (mb_strlen($value, 'UTF-8') > 100) {
                    $errors['display_name'] = 'Display name must not exceed 100 characters';
                    continue 2;
                }
                $updates[] = 'display_name = :display_name';
                $params[':display_name'] = toDb($value);
                break;

            case 'bio':
                $value = $value === null ? null : trim((string) $value);
                if ($value !== null && mb_strlen($value, 'UTF-8') > 5000) {
                    $errors['bio'] = 'Bio must not exceed 5000 characters';
                    continue 2;
                }
                $updates[] = 'bio = :bio';
                $params[':bio'] = $value !== null ? toDb($value) : null;
                break;

            case 'avatar_path':
                $value = $value === null ? null : trim((string) $value);
                $updates[] = 'avatar_path = :avatar_path';
                $params[':avatar_path'] = $value !== null ? toDb($value) : null;
                break;

            case 'email':
                $value = trim((string) $value);
                if (!validateEmail($value)) {
                    $errors['email'] = 'Invalid email address';
                    continue 2;
                }
                // Check uniqueness
                $db = getDb();
                $stmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :uid LIMIT 1');
                $stmt->execute([':email' => toDb($value), ':uid' => $userId]);
                if ($stmt->fetch()) {
                    $errors['email'] = 'This email is already registered by another user';
                    continue 2;
                }
                $updates[] = 'email = :email';
                $params[':email'] = toDb($value);
                break;
        }
    }

    if (!empty($errors)) {
        throw new InvalidArgumentException(
            json_encode($errors, JSON_UNESCAPED_UNICODE)
        );
    }

    if (empty($updates)) {
        // Nothing to update — just return current user data
        return getUserById($userId);
    }

    $db = getDb();
    $sql = 'UPDATE users SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return getUserById($userId);
}

/**
 * Change a user's password.
 *
 * Verifies the current password before updating.
 *
 * @param int $userId User ID
 * @param string $currentPassword Current password for verification
 * @param string $newPassword New password
 * @return void
 * @throws InvalidArgumentException If current password is wrong or new password is invalid
 */
function changePassword(int $userId, string $currentPassword, string $newPassword): void
{
    $db = getDb();

    $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch();

    if (!$row) {
        throw new InvalidArgumentException('User not found');
    }

    if (!password_verify($currentPassword, $row['password_hash'])) {
        throw new InvalidArgumentException('Current password is incorrect');
    }

    $passwordCheck = validatePassword($newPassword);
    if ($passwordCheck !== true) {
        throw new InvalidArgumentException($passwordCheck);
    }

    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $stmt = $db->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':hash' => $newHash,
        ':id'   => $userId,
    ]);
}

/**
 * Fetch a user by ID and return the row with UTF-8 encoding.
 *
 * @param int $userId User ID
 * @return array User data (without password_hash)
 * @throws RuntimeException If user not found
 */
function getUserById(int $userId): array
{
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT id, username, email, display_name, avatar_path, role, status,
                reputation, bio, last_login, login_ip, created_at, updated_at
         FROM users
         WHERE id = :id'
    );
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user) {
        throw new RuntimeException('User not found');
    }

    return fromDbArray($user);
}
