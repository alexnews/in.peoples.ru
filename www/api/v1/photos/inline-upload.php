<?php

declare(strict_types=1);

/**
 * POST /api/v1/photos/inline-upload.php (multipart/form-data)
 *
 * Upload a photo for inline insertion into article content.
 * Does not require a submission_id — the image URL is inserted
 * directly into the textarea as a <figure> block.
 *
 * Input: photo (file), csrf_token (form field)
 * Output: { file_path, width, height }
 *
 * Requires authentication and CSRF validation.
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$user = requireAuth();

// CSRF: check form field (multipart doesn't send JSON body)
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!is_string($csrfToken) || !validateCsrfToken($csrfToken)) {
    jsonError('Invalid or missing CSRF token', 'CSRF_ERROR', 403);
}

$userId = (int) $user['id'];

if (empty($_FILES['photo'])) {
    jsonError('No photo file provided', 'VALIDATION_ERROR', 400);
}

try {
    $fileInfo = processUpload($_FILES['photo'], $userId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 'UPLOAD_ERROR', 500);
}

jsonSuccess([
    'file_path' => $fileInfo['file_path'],
    'width'     => $fileInfo['width'],
    'height'    => $fileInfo['height'],
], 201);
