<?php

declare(strict_types=1);

/**
 * POST /api/v1/photos/delete.php
 *
 * Delete a photo from a submission. Removes the file from disk and clears
 * the photo_path field on the submission.
 *
 * Input: submission_id
 *
 * Requires authentication and CSRF validation.
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$user = requireAuth();
requireCsrf();

$db = getDb();
$userId = (int) $user['id'];

$submissionId = getInput('submission_id');
if ($submissionId === null || $submissionId === '') {
    jsonError('Submission ID is required', 'VALIDATION_ERROR', 400);
}
$submissionId = (int) $submissionId;

// Fetch submission
$stmt = $db->prepare('SELECT id, user_id, photo_path FROM user_submissions WHERE id = :id');
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    jsonError('Submission not found', 'NOT_FOUND', 404);
}

// Verify ownership
if ((int) $submission['user_id'] !== $userId) {
    jsonError('You do not have permission to delete photos for this submission', 'FORBIDDEN', 403);
}

if (empty($submission['photo_path'])) {
    jsonError('This submission has no photo to delete', 'NOT_FOUND', 404);
}

// Delete the file from disk (plus thumbnails)
deleteUpload($submission['photo_path']);

// Clear photo_path on the submission
$updateStmt = $db->prepare(
    'UPDATE user_submissions SET photo_path = NULL, updated_at = NOW() WHERE id = :id'
);
$updateStmt->execute([':id' => $submissionId]);

jsonSuccess(['message' => 'Photo deleted successfully']);
