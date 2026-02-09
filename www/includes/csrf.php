<?php

declare(strict_types=1);

/**
 * CSRF protection helpers.
 *
 * Generates and validates tokens stored in PHP's native $_SESSION.
 * Tokens can be submitted via a hidden form field or the X-CSRF-Token HTTP header.
 *
 * Note: This uses PHP's built-in session mechanism ($_SESSION) purely for CSRF
 * token storage. User authentication is handled separately via the user_sessions
 * database table and the peoples_session cookie.
 */

/** CSRF token session key */
define('CSRF_SESSION_KEY', '_csrf_token');

/** CSRF token length in bytes (64 hex characters) */
define('CSRF_TOKEN_BYTES', 32);

/**
 * Ensure PHP session is started for CSRF storage.
 *
 * @return void
 */
function ensurePhpSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('peoples_csrf');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly'  => true,
            'samesite'  => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Generate a new CSRF token and store it in the PHP session.
 *
 * If a token already exists in the session, it is returned instead of
 * generating a new one (one token per session for simplicity).
 *
 * @return string The CSRF token (hex string)
 */
function generateCsrfToken(): string
{
    ensurePhpSession();

    if (!empty($_SESSION[CSRF_SESSION_KEY])) {
        return $_SESSION[CSRF_SESSION_KEY];
    }

    $token = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    $_SESSION[CSRF_SESSION_KEY] = $token;

    return $token;
}

/**
 * Validate a CSRF token against the stored session token.
 *
 * Checks the provided token first. If null, falls back to the X-CSRF-Token
 * HTTP header.
 *
 * @param string|null $token Token from form submission (or null to check header)
 * @return bool True if the token matches, false otherwise
 */
function validateCsrfToken(?string $token = null): bool
{
    ensurePhpSession();

    $storedToken = $_SESSION[CSRF_SESSION_KEY] ?? null;

    if ($storedToken === null || $storedToken === '') {
        return false;
    }

    // If no token argument provided, try the HTTP header
    if ($token === null || $token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    if ($token === null || $token === '') {
        return false;
    }

    return hash_equals($storedToken, $token);
}

/**
 * Generate a hidden HTML input field containing the CSRF token.
 *
 * For use in HTML forms:
 *   echo csrfField();
 *
 * @return string HTML hidden input element
 */
function csrfField(): string
{
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}
