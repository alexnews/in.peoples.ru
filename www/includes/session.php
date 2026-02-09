<?php

declare(strict_types=1);

/**
 * Server-side session management using the user_sessions table.
 *
 * Sessions are stored in the database rather than PHP's default file-based sessions.
 * A secure cookie ('peoples_session') holds the session ID on the client side.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encoding.php';

/** @var array|null|false Cached current user (null=not loaded, false=no user, array=user) */
$_currentUserCache = null;

/** Cookie name for the session identifier */
define('SESSION_COOKIE_NAME', 'peoples_session');

/** Session lifetime in seconds (30 days) */
define('SESSION_LIFETIME', 30 * 24 * 60 * 60);

/** Interval for cleaning expired sessions (1 hour) */
define('SESSION_GC_INTERVAL', 3600);

/** Probability of garbage collection on each request (1 in N) */
define('SESSION_GC_PROBABILITY', 100);

/**
 * Create a new session for the given user.
 *
 * Inserts a row into user_sessions and sets the session cookie.
 *
 * @param int $userId User's ID
 * @param string $ip Client IP address
 * @param string $userAgent Client User-Agent string
 * @return string The generated session ID
 */
function startSession(int $userId, string $ip, string $userAgent): string
{
    $db = getDb();

    // Generate a cryptographically secure session ID
    $sessionId = bin2hex(random_bytes(64));

    // Truncate user agent to 255 chars to fit the column
    $userAgent = mb_substr($userAgent, 0, 255, 'UTF-8');

    $stmt = $db->prepare(
        'INSERT INTO user_sessions (id, user_id, ip_address, user_agent, last_activity, created_at)
         VALUES (:id, :user_id, :ip, :ua, NOW(), NOW())'
    );
    $stmt->execute([
        ':id'      => $sessionId,
        ':user_id' => $userId,
        ':ip'      => $ip,
        ':ua'      => toDb($userAgent),
    ]);

    // Set the session cookie
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(SESSION_COOKIE_NAME, $sessionId, [
        'expires'  => time() + SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);

    // Probabilistic garbage collection
    if (random_int(1, SESSION_GC_PROBABILITY) === 1) {
        gcSessions();
    }

    return $sessionId;
}

/**
 * Get the currently authenticated user based on the session cookie.
 *
 * Validates the session against the user_sessions table, checks that the user
 * exists and is active, refreshes the session's last_activity timestamp,
 * and returns the user row converted from CP1251 to UTF-8.
 *
 * @return array|null User row (UTF-8 encoded) or null if not authenticated
 */
function getCurrentUser(): ?array
{
    global $_currentUserCache;

    // Return cached result if available (false = checked but no user)
    if ($_currentUserCache !== null) {
        return $_currentUserCache === false ? null : $_currentUserCache;
    }

    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? null;

    if ($sessionId === null || $sessionId === '') {
        $_currentUserCache = false;
        return null;
    }

    // Validate session ID format (must be 128 hex chars)
    if (!preg_match('/^[a-f0-9]{128}$/', $sessionId)) {
        $_currentUserCache = false;
        return null;
    }

    $db = getDb();

    // Look up session and join with users in a single query
    $stmt = $db->prepare(
        'SELECT u.id, u.username, u.email, u.password_hash, u.display_name,
                u.avatar_path, u.role, u.status, u.reputation, u.bio,
                u.last_login, u.login_ip, u.created_at, u.updated_at,
                s.last_activity, s.created_at AS session_created
         FROM user_sessions s
         INNER JOIN users u ON u.id = s.user_id
         WHERE s.id = :sid'
    );
    $stmt->execute([':sid' => $sessionId]);
    $row = $stmt->fetch();

    if (!$row) {
        // Invalid session — clear the stale cookie
        clearSessionCookie();
        $_currentUserCache = false;
        return null;
    }

    // Check if the session has expired
    $lastActivity = strtotime($row['last_activity']);
    if ($lastActivity !== false && (time() - $lastActivity) > SESSION_LIFETIME) {
        // Session expired — remove it
        $deleteStmt = $db->prepare('DELETE FROM user_sessions WHERE id = :sid');
        $deleteStmt->execute([':sid' => $sessionId]);
        clearSessionCookie();
        $_currentUserCache = false;
        return null;
    }

    // Check if user is active
    if ($row['status'] !== 'active') {
        // User is banned or suspended — destroy the session
        $deleteStmt = $db->prepare('DELETE FROM user_sessions WHERE id = :sid');
        $deleteStmt->execute([':sid' => $sessionId]);
        clearSessionCookie();
        $_currentUserCache = false;
        return null;
    }

    // Refresh last_activity (but not on every request — only if > 5 min old)
    if ($lastActivity !== false && (time() - $lastActivity) > 300) {
        $updateStmt = $db->prepare(
            'UPDATE user_sessions SET last_activity = NOW() WHERE id = :sid'
        );
        $updateStmt->execute([':sid' => $sessionId]);
    }

    // Remove internal fields before returning
    unset($row['password_hash'], $row['last_activity'], $row['session_created']);

    $_currentUserCache = fromDbArray($row);
    return $_currentUserCache;
}

/**
 * Destroy the current session.
 *
 * Deletes the session row from the database and clears the session cookie.
 *
 * @return void
 */
function destroySession(): void
{
    global $_currentUserCache;

    $sessionId = $_COOKIE[SESSION_COOKIE_NAME] ?? null;

    if ($sessionId !== null && $sessionId !== '') {
        $db = getDb();
        $stmt = $db->prepare('DELETE FROM user_sessions WHERE id = :sid');
        $stmt->execute([':sid' => $sessionId]);
    }

    clearSessionCookie();
    $_currentUserCache = null;
}

/**
 * Clear the session cookie from the client.
 *
 * @return void
 */
function clearSessionCookie(): void
{
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    setcookie(SESSION_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[SESSION_COOKIE_NAME]);
}

/**
 * Remove expired sessions from the database.
 *
 * Called probabilistically from startSession() to avoid running on every request.
 *
 * @return void
 */
function gcSessions(): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'DELETE FROM user_sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL :lifetime SECOND)'
    );
    $stmt->execute([':lifetime' => SESSION_LIFETIME]);
}
