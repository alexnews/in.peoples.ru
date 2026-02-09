<?php

declare(strict_types=1);

/**
 * Input validation and sanitization helpers.
 *
 * Each validator returns `true` on success or a string error message on failure.
 */

/**
 * Validate an email address.
 *
 * @param string $email Email to validate
 * @return bool True if valid
 */
function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate a password against strength requirements.
 *
 * Requirements:
 * - At least 8 characters
 * - At most 128 characters
 * - Contains at least one letter
 * - Contains at least one digit
 *
 * @param string $password Password to validate
 * @return string|true Error message string on failure, true on success
 */
function validatePassword(string $password)
{
    $length = mb_strlen($password, 'UTF-8');

    if ($length < 8) {
        return 'Password must be at least 8 characters long';
    }

    if ($length > 128) {
        return 'Password must not exceed 128 characters';
    }

    if (!preg_match('/[a-zA-Za-яА-ЯёЁ]/u', $password)) {
        return 'Password must contain at least one letter';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one digit';
    }

    return true;
}

/**
 * Validate a username.
 *
 * Requirements:
 * - 3 to 50 characters
 * - Only letters (including Cyrillic), digits, underscores, and hyphens
 * - Must start with a letter or digit
 *
 * @param string $username Username to validate
 * @return string|true Error message string on failure, true on success
 */
function validateUsername(string $username)
{
    $length = mb_strlen($username, 'UTF-8');

    if ($length < 3) {
        return 'Username must be at least 3 characters long';
    }

    if ($length > 50) {
        return 'Username must not exceed 50 characters';
    }

    if (!preg_match('/^[\p{L}0-9][\p{L}0-9_\-]*$/u', $username)) {
        return 'Username may contain only letters, digits, underscores, and hyphens, and must start with a letter or digit';
    }

    return true;
}

/**
 * Sanitize HTML content by stripping all tags except a safe whitelist.
 *
 * Allowed tags: p, b, i, strong, em, a, br, ul, ol, li, h2, h3, blockquote
 *
 * @param string $html Raw HTML string
 * @return string Sanitized HTML
 */
function sanitizeHtml(string $html): string
{
    $allowedTags = '<p><b><i><strong><em><a><br><ul><ol><li><h2><h3><blockquote>';
    $cleaned = strip_tags($html, $allowedTags);

    // Remove dangerous attributes (on*, javascript:, data:, vbscript:)
    // Process all allowed tags to strip event handlers and dangerous protocols
    $cleaned = preg_replace(
        '/(<[^>]+)\s+on\w+\s*=\s*["\'][^"\']*["\']/iu',
        '$1',
        $cleaned
    ) ?? $cleaned;

    // Remove javascript:, vbscript:, data: from href/src attributes
    $cleaned = preg_replace(
        '/(<a\s[^>]*href\s*=\s*["\'])\s*(javascript|vbscript|data)\s*:/iu',
        '$1#blocked:',
        $cleaned
    ) ?? $cleaned;

    // Remove style attributes to prevent CSS-based attacks
    $cleaned = preg_replace(
        '/(<[^>]+)\s+style\s*=\s*["\'][^"\']*["\']/iu',
        '$1',
        $cleaned
    ) ?? $cleaned;

    return $cleaned;
}

/**
 * Validate an uploaded file.
 *
 * Checks:
 * - Upload error status
 * - File size against maximum
 * - MIME type against allowed list (verified via finfo)
 *
 * @param array $file Entry from $_FILES
 * @param int $maxSize Maximum file size in bytes (default 10 MB)
 * @param array $allowedTypes Allowed MIME types
 * @return string|true Error message string on failure, true on success
 */
function validateUploadedFile(
    array $file,
    int $maxSize = 10485760,
    array $allowedTypes = ['image/jpeg', 'image/png', 'image/webp']
) {
    // Check for upload errors
    if (!isset($file['error']) || !isset($file['tmp_name'])) {
        return 'Invalid file upload data';
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return match ($file['error']) {
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE   => 'File exceeds the maximum allowed size',
            UPLOAD_ERR_PARTIAL     => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE     => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR  => 'Server configuration error: missing temporary directory',
            UPLOAD_ERR_CANT_WRITE  => 'Server error: failed to write file to disk',
            UPLOAD_ERR_EXTENSION   => 'File upload blocked by server extension',
            default                => 'Unknown upload error',
        };
    }

    // Verify the file actually exists (prevents path manipulation)
    if (!is_uploaded_file($file['tmp_name'])) {
        return 'Invalid file upload';
    }

    // Check file size
    $fileSize = filesize($file['tmp_name']);
    if ($fileSize === false || $fileSize > $maxSize) {
        $maxMb = round($maxSize / 1048576, 1);
        return "File size exceeds the maximum of {$maxMb} MB";
    }

    if ($fileSize === 0) {
        return 'Uploaded file is empty';
    }

    // Verify MIME type using finfo (not relying on client-reported type)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedType = $finfo->file($file['tmp_name']);

    if ($detectedType === false || !in_array($detectedType, $allowedTypes, true)) {
        $allowed = implode(', ', $allowedTypes);
        return "File type '{$detectedType}' is not allowed. Accepted types: {$allowed}";
    }

    return true;
}
