<?php

declare(strict_types=1);

/**
 * GET /api/v1/submissions/view.php?id=
 *
 * View a single submission. Users can view their own; moderators can view any.
 *
 * Requires authentication.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$user = requireAuth();
$db = getDb();

$id = getInput('id');
if ($id === null || $id === '') {
    jsonError('Submission ID is required', 'VALIDATION_ERROR', 400);
}
$id = (int) $id;

// Fetch submission with person and section info
$stmt = $db->prepare(
    'SELECT s.*,
            p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
            p.NamePhoto AS person_photo, p.AllUrlInSity AS person_path,
            p.DateIn AS person_date_birth, p.DateOut AS person_date_death,
            p.famous_for AS person_famous_for,
            ps.nameRus AS section_name, ps.nameEng AS section_name_eng, ps.path AS section_path,
            u.username AS moderator_username, u.display_name AS moderator_display_name
     FROM user_submissions s
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     LEFT JOIN users u ON u.id = s.moderator_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $id]);
$submission = $stmt->fetch();

if (!$submission) {
    jsonError('Submission not found', 'NOT_FOUND', 404);
}

// Permission check: owner or moderator
$userId = (int) $user['id'];
$isOwner = (int) $submission['user_id'] === $userId;
$isMod = isModerator();

if (!$isOwner && !$isMod) {
    jsonError('You do not have permission to view this submission', 'FORBIDDEN', 403);
}

// Convert all fields from cp1251 to UTF-8
$result = fromDbArray($submission);

jsonSuccess($result);
