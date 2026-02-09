<?php

declare(strict_types=1);

/**
 * POST /api/v1/submissions/delete.php (acts as DELETE)
 *
 * Delete a submission. Only the owner can delete, and only submissions
 * in 'draft' or 'pending' status can be deleted.
 *
 * If the submission has an associated photo, it is also removed.
 *
 * Requires authentication and CSRF validation.
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$user = requireAuth();
requireCsrf();

$db = getDb();
$userId = (int) $user['id'];

$id = getInput('id');
if ($id === null || $id === '') {
    jsonError('Submission ID is required', 'VALIDATION_ERROR', 400);
}
$id = (int) $id;

// Fetch submission
$stmt = $db->prepare('SELECT * FROM user_submissions WHERE id = :id');
$stmt->execute([':id' => $id]);
$submission = $stmt->fetch();

if (!$submission) {
    jsonError('Submission not found', 'NOT_FOUND', 404);
}

// Only owner can delete
if ((int) $submission['user_id'] !== $userId) {
    jsonError('You do not have permission to delete this submission', 'FORBIDDEN', 403);
}

// Only draft or pending can be deleted
$deletableStatuses = ['draft', 'pending'];
if (!in_array($submission['status'], $deletableStatuses, true)) {
    jsonError(
        'Only submissions in draft or pending status can be deleted',
        'STATUS_ERROR',
        400
    );
}

// Delete associated photo if exists
if (!empty($submission['photo_path'])) {
    deleteUpload($submission['photo_path']);
}

// Delete the submission
$deleteStmt = $db->prepare('DELETE FROM user_submissions WHERE id = :id');
$deleteStmt->execute([':id' => $id]);

jsonSuccess(['message' => 'Submission deleted successfully']);
