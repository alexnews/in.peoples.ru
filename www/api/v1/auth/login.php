<?php

declare(strict_types=1);

/**
 * POST /api/v1/auth/login
 *
 * Authenticate a user by email/username and password.
 * Creates a session and returns the user data with session cookie set.
 *
 * Input: login (email or username), password
 * Output: user object with session cookie set
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$login    = trim((string) getInput('login', ''));
$password = (string) getInput('password', '');

if ($login === '' || $password === '') {
    jsonError(
        'Login and password are required',
        'VALIDATION_ERROR',
        400
    );
}

try {
    // loginUser handles authentication, session creation, and last_login update
    $user = loginUser($login, $password);

    // Include CSRF token for subsequent requests
    $user['csrf_token'] = generateCsrfToken();

    jsonSuccess($user);

} catch (InvalidArgumentException $e) {
    // Invalid credentials
    jsonError($e->getMessage(), 'AUTH_ERROR', 401);

} catch (RuntimeException $e) {
    // Banned or suspended
    jsonError($e->getMessage(), 'ACCOUNT_ERROR', 403);
}
