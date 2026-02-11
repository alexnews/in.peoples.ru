<?php

declare(strict_types=1);

/**
 * CSRF protection helpers.
 *
 * Primary method: HMAC-based token derived from the user's DB session ID.
 * No server-side storage needed — works as long as the user is authenticated.
 *
 * Fallback: PHP native $_SESSION for non-authenticated pages (if any).
 */

/** CSRF token session key (fallback) */
define('CSRF_SESSION_KEY', '_csrf_token');

/** CSRF token length in bytes (fallback) */
define('CSRF_TOKEN_BYTES', 32);

/** HMAC key for deriving CSRF tokens from session IDs */
define('CSRF_HMAC_KEY', 'peoples_csrf_hmac_2025');

/**
 * Derive a CSRF token from the user's authenticated session cookie.
 *
 * Returns null if no session cookie is present.
 *
 * @return string|null HMAC-based token, or null if not authenticated
 */
function deriveSessionCsrfToken(): ?string
{
    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? '';
    if ($sessionId === '') {
        return null;
    }

    return hash_hmac('sha256', $sessionId, CSRF_HMAC_KEY);
}

/**
 * Ensure PHP session is started for CSRF storage (fallback).
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
 * Generate a CSRF token.
 *
 * For authenticated users: derives token from their session cookie (no storage needed).
 * For anonymous users: falls back to PHP native session storage.
 *
 * @return string The CSRF token (hex string)
 */
function generateCsrfToken(): string
{
    // Primary: HMAC-based token from authenticated session
    $hmacToken = deriveSessionCsrfToken();
    if ($hmacToken !== null) {
        return $hmacToken;
    }

    // Fallback: PHP native session
    ensurePhpSession();

    if (!empty($_SESSION[CSRF_SESSION_KEY])) {
        return $_SESSION[CSRF_SESSION_KEY];
    }

    $token = bin2hex(random_bytes(CSRF_TOKEN_BYTES));
    $_SESSION[CSRF_SESSION_KEY] = $token;

    return $token;
}

/**
 * Validate a CSRF token.
 *
 * Checks the provided token first. If null, falls back to the X-CSRF-Token
 * HTTP header. Validates against HMAC-derived token (primary) and PHP session
 * token (fallback).
 *
 * @param string|null $token Token from form submission (or null to check header)
 * @return bool True if the token matches, false otherwise
 */
function validateCsrfToken(?string $token = null): bool
{
    // If no token argument provided, try the HTTP header
    if ($token === null || $token === '') {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }

    if ($token === null || $token === '') {
        return false;
    }

    // Primary: HMAC-based validation (for authenticated users)
    $hmacToken = deriveSessionCsrfToken();
    if ($hmacToken !== null && hash_equals($hmacToken, $token)) {
        return true;
    }

    // Fallback: PHP native session validation
    ensurePhpSession();
    $storedToken = $_SESSION[CSRF_SESSION_KEY] ?? null;
    if ($storedToken !== null && $storedToken !== '' && hash_equals($storedToken, $token)) {
        return true;
    }

    return false;
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
