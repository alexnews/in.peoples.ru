<?php

declare(strict_types=1);

/**
 * GET|PUT /api/v1/auth/profile
 *
 * GET:  Return the current user's profile with submission stats.
 * PUT:  Update the current user's profile fields.
 *
 * Requires authentication.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET', 'PUT', 'POST');

$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

// Treat POST with _method=PUT as PUT
if ($method === 'POST' && getInput('_method') === 'PUT') {
    $method = 'PUT';
}

if ($method === 'GET') {
    // ------------------------------------------------------------------
    // GET: Return profile with submission statistics
    // ------------------------------------------------------------------
    $db = getDb();
    $userId = (int) $user['id'];

    // Count submissions by status
    $stmt = $db->prepare(
        'SELECT status, COUNT(*) AS cnt
         FROM user_submissions
         WHERE user_id = :uid
         GROUP BY status'
    );
    $stmt->execute([':uid' => $userId]);
    $rows = $stmt->fetchAll();

    $stats = [
        'draft'              => 0,
        'pending'            => 0,
        'approved'           => 0,
        'rejected'           => 0,
        'revision_requested' => 0,
        'total'              => 0,
    ];

    foreach ($rows as $row) {
        $stats[$row['status']] = (int) $row['cnt'];
        $stats['total'] += (int) $row['cnt'];
    }

    $user['submissions_stats'] = $stats;
    $user['csrf_token'] = generateCsrfToken();

    jsonSuccess($user);

} else {
    // ------------------------------------------------------------------
    // PUT: Update profile
    // ------------------------------------------------------------------
    requireCsrf();

    $data = [];

    $displayName = getInput('display_name');
    if ($displayName !== null) {
        $data['display_name'] = (string) $displayName;
    }

    $bio = getInput('bio');
    if ($bio !== null) {
        $data['bio'] = $bio === '' ? null : (string) $bio;
    }

    $email = getInput('email');
    if ($email !== null) {
        $data['email'] = (string) $email;
    }

    $avatarPath = getInput('avatar_path');
    if ($avatarPath !== null) {
        $data['avatar_path'] = $avatarPath === '' ? null : (string) $avatarPath;
    }

    // Handle password change separately
    $currentPassword = getInput('current_password');
    $newPassword = getInput('new_password');

    if ($currentPassword !== null && $newPassword !== null) {
        try {
            changePassword((int) $user['id'], (string) $currentPassword, (string) $newPassword);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);
        }
    }

    if (empty($data) && $currentPassword === null) {
        jsonError('No fields provided for update', 'VALIDATION_ERROR', 400);
    }

    try {
        $updatedUser = !empty($data)
            ? updateProfile((int) $user['id'], $data)
            : getUserById((int) $user['id']);

        $updatedUser['csrf_token'] = generateCsrfToken();

        jsonSuccess($updatedUser);

    } catch (InvalidArgumentException $e) {
        $fields = json_decode($e->getMessage(), true);
        if (is_array($fields)) {
            jsonError('Validation failed', 'VALIDATION_ERROR', 400, $fields);
        }
        jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);

    } catch (RuntimeException $e) {
        $fields = json_decode($e->getMessage(), true);
        if (is_array($fields)) {
            jsonError('Update failed', 'DUPLICATE_ERROR', 409, $fields);
        }
        jsonError($e->getMessage(), 'SERVER_ERROR', 500);
    }
}
