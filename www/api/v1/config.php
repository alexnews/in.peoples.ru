<?php

declare(strict_types=1);

/**
 * API v1 bootstrap configuration.
 *
 * Every API endpoint requires this file. It loads all shared includes,
 * sets JSON headers, handles CORS, parses JSON request bodies, and
 * provides helper functions for input access and method enforcement.
 */

// Load shared includes
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/encoding.php';
require_once dirname(__DIR__, 2) . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/validation.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/response.php';
require_once dirname(__DIR__, 2) . '/includes/upload.php';

// --- CORS headers ---
$allowedOrigin = 'https://in.peoples.ru';

// Also allow the non-www variant and local dev
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = [
    'https://in.peoples.ru',
    'https://www.in.peoples.ru',
    'http://in.peoples.ru',
    'http://localhost',
    'http://localhost:8080',
];

if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Set JSON content type ---
header('Content-Type: application/json; charset=utf-8');

// --- Parse JSON request body ---
/** @var array Parsed JSON body for POST/PUT/PATCH requests */
$_JSON_BODY = [];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    // Only parse JSON bodies (not multipart form uploads)
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody !== false && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $_JSON_BODY = $decoded;
            }
        }
    }
}

/**
 * Get an input value from the parsed JSON body, $_POST, or $_GET.
 *
 * Priority: JSON body > $_POST > $_GET
 *
 * @param string $key Parameter name
 * @param mixed $default Default value if not found
 * @return mixed
 */
function getInput(string $key, mixed $default = null): mixed
{
    global $_JSON_BODY;

    if (isset($_JSON_BODY[$key])) {
        return $_JSON_BODY[$key];
    }

    if (isset($_POST[$key])) {
        return $_POST[$key];
    }

    if (isset($_GET[$key])) {
        return $_GET[$key];
    }

    return $default;
}

/**
 * Require the request to use one of the specified HTTP methods.
 *
 * Sends a 405 Method Not Allowed response and exits if the current
 * method is not in the allowed list.
 *
 * @param string ...$methods Allowed HTTP methods (e.g., 'GET', 'POST')
 * @return void
 */
function requireMethod(string ...$methods): void
{
    $current = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if (!in_array($current, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        jsonError(
            'Method not allowed. Allowed: ' . implode(', ', $methods),
            'METHOD_NOT_ALLOWED',
            405
        );
    }
}

/**
 * Validate CSRF token from the request.
 *
 * Checks the X-CSRF-Token header and the csrf_token body/query field.
 * Sends a 403 response on failure.
 *
 * @return void
 */
function requireCsrf(): void
{
    $token = getInput('csrf_token');
    if (is_string($token) && validateCsrfToken($token)) {
        return;
    }

    // Also check header (validateCsrfToken checks it when called with null)
    if (validateCsrfToken(null)) {
        return;
    }

    jsonError('Invalid or missing CSRF token', 'CSRF_ERROR', 403);
}
