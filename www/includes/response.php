<?php

declare(strict_types=1);

/**
 * JSON response helpers.
 *
 * All API endpoints use these functions for consistent response format:
 * { "success": true/false, "data": ..., "error": { "code": "...", "message": "..." } }
 */

/**
 * Send a successful JSON response and exit.
 *
 * @param mixed $data Response payload (will be JSON-encoded)
 * @param int $code HTTP status code (default 200)
 * @return never
 */
function jsonSuccess(mixed $data = null, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $response = ['success' => true];
    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Send an error JSON response and exit.
 *
 * @param string $message Human-readable error description
 * @param string $errorCode Machine-readable error code (e.g., VALIDATION_ERROR, NOT_FOUND)
 * @param int $httpCode HTTP status code (default 400)
 * @param array $fields Per-field validation errors, e.g. ['email' => 'Already taken']
 * @return never
 */
function jsonError(string $message, string $errorCode = 'ERROR', int $httpCode = 400, array $fields = []): never
{
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $error = [
        'code'    => $errorCode,
        'message' => $message,
    ];

    if (!empty($fields)) {
        $error['fields'] = $fields;
    }

    $response = [
        'success' => false,
        'error'   => $error,
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Send a paginated JSON response and exit.
 *
 * @param array $data Array of items for the current page
 * @param int $total Total number of items across all pages
 * @param int $page Current page number (1-based)
 * @param int $perPage Number of items per page
 * @return never
 */
function jsonPaginated(array $data, int $total, int $page, int $perPage): never
{
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $pages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

    $response = [
        'success'    => true,
        'data'       => $data,
        'pagination' => [
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
            'pages'    => $pages,
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
