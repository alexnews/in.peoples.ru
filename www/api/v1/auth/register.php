<?php

declare(strict_types=1);

/**
 * POST /api/v1/auth/register
 *
 * Register a new user account. On success, automatically logs in the user
 * by creating a session and returns the user data.
 *
 * Input: username, email, password, display_name
 * Output: user object with session cookie set
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$username    = trim((string) getInput('username', ''));
$email       = trim((string) getInput('email', ''));
$password    = (string) getInput('password', '');
$displayName = trim((string) getInput('display_name', ''));

if ($username === '' || $email === '' || $password === '' || $displayName === '') {
    jsonError(
        'All fields are required: username, email, password, display_name',
        'VALIDATION_ERROR',
        400
    );
}

try {
    // Register the user (validates inputs, checks duplicates, inserts)
    $user = registerUser($username, $email, $password, $displayName);

    // Auto-login: create session
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    startSession((int) $user['id'], $ip, $userAgent);

    // Include CSRF token for subsequent requests
    $user['csrf_token'] = generateCsrfToken();

    jsonSuccess($user, 201);

} catch (InvalidArgumentException $e) {
    // Validation errors (JSON-encoded field errors from registerUser)
    $fields = json_decode($e->getMessage(), true);
    if (is_array($fields)) {
        jsonError('Validation failed', 'VALIDATION_ERROR', 400, $fields);
    }
    jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);

} catch (RuntimeException $e) {
    // Duplicate username/email
    $fields = json_decode($e->getMessage(), true);
    if (is_array($fields)) {
        jsonError('Registration failed', 'DUPLICATE_ERROR', 409, $fields);
    }
    jsonError($e->getMessage(), 'SERVER_ERROR', 500);
}
