<?php

declare(strict_types=1);

/**
 * POST /api/v1/submissions/update.php (acts as PUT)
 *
 * Update a submission. Only the owner can update, and only submissions
 * in 'draft' or 'revision_requested' status can be modified.
 *
 * Can change status from draft->pending or revision_requested->pending.
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

// Only owner can update
if ((int) $submission['user_id'] !== $userId) {
    jsonError('You do not have permission to update this submission', 'FORBIDDEN', 403);
}

// Only draft or revision_requested can be updated
$editableStatuses = ['draft', 'revision_requested'];
if (!in_array($submission['status'], $editableStatuses, true)) {
    jsonError(
        'Only submissions in draft or revision_requested status can be updated',
        'STATUS_ERROR',
        400
    );
}

// Collect updates
$updates = [];
$params  = [':id' => $id];

$sectionId = getInput('section_id');
if ($sectionId !== null) {
    $sectionId = (int) $sectionId;
    $checkStmt = $db->prepare('SELECT id FROM peoples_section WHERE id = :id LIMIT 1');
    $checkStmt->execute([':id' => $sectionId]);
    if (!$checkStmt->fetch()) {
        jsonError('Section not found', 'NOT_FOUND', 404, ['section_id' => 'Invalid section']);
    }
    $updates[] = 'section_id = :section_id';
    $params[':section_id'] = $sectionId;
}

$kodPersons = getInput('KodPersons');
if ($kodPersons !== null) {
    if ($kodPersons === '' || $kodPersons === 0) {
        $updates[] = 'KodPersons = NULL';
    } else {
        $kodPersons = (int) $kodPersons;
        $checkStmt = $db->prepare('SELECT Persons_id FROM persons WHERE Persons_id = :id LIMIT 1');
        $checkStmt->execute([':id' => $kodPersons]);
        if (!$checkStmt->fetch()) {
            jsonError('Person not found', 'NOT_FOUND', 404, ['KodPersons' => 'Invalid person']);
        }
        $updates[] = 'KodPersons = :kod_persons';
        $params[':kod_persons'] = $kodPersons;
    }
}

$title = getInput('title');
if ($title !== null) {
    $title = trim((string) $title);
    if ($title === '') {
        jsonError('Title cannot be empty', 'VALIDATION_ERROR', 400, ['title' => 'Title is required']);
    }
    $updates[] = 'title = :title';
    $params[':title'] = toDb($title);
}

$content = getInput('content');
if ($content !== null) {
    $content = trim((string) $content);
    if ($content === '') {
        jsonError('Content cannot be empty', 'VALIDATION_ERROR', 400, ['content' => 'Content is required']);
    }
    $content = sanitizeHtml($content);
    $updates[] = 'content = :content';
    $params[':content'] = toDb($content);
}

$epigraph = getInput('epigraph');
if ($epigraph !== null) {
    $updates[] = 'epigraph = :epigraph';
    $params[':epigraph'] = $epigraph === '' ? null : toDb(trim((string) $epigraph));
}

$sourceUrl = getInput('source_url');
if ($sourceUrl !== null) {
    $updates[] = 'source_url = :source_url';
    $params[':source_url'] = $sourceUrl === '' ? null : trim((string) $sourceUrl);
}

// Handle status change: draft->pending or revision_requested->pending
$newStatus = getInput('status');
if ($newStatus !== null) {
    $currentStatus = $submission['status'];
    $allowedTransitions = [
        'draft'              => ['pending'],
        'revision_requested' => ['pending'],
    ];

    if (!in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
        jsonError(
            "Cannot change status from '{$currentStatus}' to '{$newStatus}'",
            'STATUS_ERROR',
            400
        );
    }

    $updates[] = 'status = :status';
    $params[':status'] = $newStatus;
}

if (empty($updates)) {
    jsonError('No fields provided for update', 'VALIDATION_ERROR', 400);
}

// Always update updated_at
$updates[] = 'updated_at = NOW()';

$sql = 'UPDATE user_submissions SET ' . implode(', ', $updates) . ' WHERE id = :id';
$stmt = $db->prepare($sql);
$stmt->execute($params);

// Fetch updated submission
$stmt = $db->prepare(
    'SELECT s.*, p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
            ps.nameRus AS section_name
     FROM user_submissions s
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$result = $stmt->fetch();

jsonSuccess(fromDbArray($result));
