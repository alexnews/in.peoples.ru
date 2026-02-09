<?php

declare(strict_types=1);

/**
 * POST /api/v1/photos/upload.php (multipart/form-data)
 *
 * Upload a photo for a submission. The file is processed via processUpload()
 * which validates, resizes, and generates thumbnails.
 *
 * Input: submission_id (form field), photo (file)
 * Output: file info array
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

// Verify the submission belongs to the current user
$stmt = $db->prepare('SELECT id, user_id, photo_path FROM user_submissions WHERE id = :id');
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    jsonError('Submission not found', 'NOT_FOUND', 404);
}

if ((int) $submission['user_id'] !== $userId) {
    jsonError('You do not have permission to upload photos for this submission', 'FORBIDDEN', 403);
}

// Check that a file was uploaded
if (empty($_FILES['photo'])) {
    jsonError('No photo file provided. Use field name "photo"', 'VALIDATION_ERROR', 400);
}

$file = $_FILES['photo'];

// Handle multiple files if the input field is an array
if (is_array($file['name'])) {
    // Multiple files uploaded
    $fileInfos = [];
    $fileCount = count($file['name']);

    for ($i = 0; $i < $fileCount; $i++) {
        $singleFile = [
            'name'     => $file['name'][$i],
            'type'     => $file['type'][$i],
            'tmp_name' => $file['tmp_name'][$i],
            'error'    => $file['error'][$i],
            'size'     => $file['size'][$i],
        ];

        try {
            $info = processUpload($singleFile, $userId, $submissionId);
            $fileInfos[] = $info;
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);
        } catch (RuntimeException $e) {
            jsonError($e->getMessage(), 'UPLOAD_ERROR', 500);
        }
    }

    // Update submission with the last uploaded photo path
    if (!empty($fileInfos)) {
        $lastFile = end($fileInfos);
        $updateStmt = $db->prepare(
            'UPDATE user_submissions SET photo_path = :path, updated_at = NOW() WHERE id = :id'
        );
        $updateStmt->execute([
            ':path' => $lastFile['file_path'],
            ':id'   => $submissionId,
        ]);
    }

    jsonSuccess($fileInfos, 201);

} else {
    // Single file upload
    try {
        $fileInfo = processUpload($file, $userId, $submissionId);
    } catch (InvalidArgumentException $e) {
        jsonError($e->getMessage(), 'VALIDATION_ERROR', 400);
    } catch (RuntimeException $e) {
        jsonError($e->getMessage(), 'UPLOAD_ERROR', 500);
    }

    // Update submission with the photo path
    $updateStmt = $db->prepare(
        'UPDATE user_submissions SET photo_path = :path, updated_at = NOW() WHERE id = :id'
    );
    $updateStmt->execute([
        ':path' => $fileInfo['file_path'],
        ':id'   => $submissionId,
    ]);

    jsonSuccess($fileInfo, 201);
}
