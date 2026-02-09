<?php

declare(strict_types=1);

/**
 * POST /api/v1/moderate/person-push.php
 *
 * Admin-only: push an approved person suggestion to the real `persons` table.
 * Also inserts biography into `histories`.
 *
 * This is the ONLY place where user-suggested data enters the production
 * `persons` table — and only after both moderator AND admin approval.
 *
 * Tables used:
 * - user_person_suggestions: read data, update status to 'published'
 * - persons: INSERT new person (approve='NO', admin sets path/URL later)
 * - histories: INSERT biography linked to new Persons_id
 * - users: reputation update (+10 for published person)
 * - users_moderation_log: audit trail
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$admin = requireRole('admin');
requireCsrf();

$db = getDb();
$adminId = (int) $admin['id'];

$suggestionId = getInput('suggestion_id');
if ($suggestionId === null || $suggestionId === '') {
    jsonError('Suggestion ID is required', 'VALIDATION_ERROR', 400);
}
$suggestionId = (int) $suggestionId;

// Fetch the approved suggestion
$stmt = $db->prepare('SELECT * FROM user_person_suggestions WHERE id = :id');
$stmt->execute([':id' => $suggestionId]);
$suggestion = $stmt->fetch();

if (!$suggestion) {
    jsonError('Person suggestion not found', 'NOT_FOUND', 404);
}

if ($suggestion['status'] !== 'approved') {
    jsonError('Only approved suggestions can be pushed to persons table', 'STATUS_ERROR', 400);
}

if (!empty($suggestion['published_person_id'])) {
    jsonError('This suggestion has already been published', 'ALREADY_PUBLISHED', 400);
}

$suggestion = fromDbArray($suggestion);
$submitterUserId = (int) $suggestion['user_id'];

$db->beginTransaction();

try {
    // Build person fields
    $nameRus     = $suggestion['NameRus'] ?? '';
    $surNameRus  = $suggestion['SurNameRus'] ?? '';
    $fullNameRus = $nameRus . ' ' . $surNameRus;
    $nameEngl     = $suggestion['NameEngl'] ?? '';
    $surNameEngl  = $suggestion['SurNameEngl'] ?? '';
    $fullNameEngl = ($nameEngl !== '' && $surNameEngl !== '')
        ? $nameEngl . ' ' . $surNameEngl
        : '';

    $biography = $suggestion['biography'] ?? '';
    $title     = $suggestion['title'] ?? '';
    // Use user-provided epigraph, fall back to first 200 chars of biography
    $epigraph  = !empty($suggestion['epigraph'])
        ? $suggestion['epigraph']
        : mb_substr($biography, 0, 200, 'UTF-8');

    // INSERT into persons table with approve='NO'
    // Admin will set AllUrlInSity, path, KodStructure separately
    $personStmt = $db->prepare(
        "INSERT INTO persons (
            NameRus, SurNameRus, FullNameRus,
            NameEngl, SurNameEngl, FullNameEngl,
            Epigraph, DateIn, DateOut, gender,
            TownIn, cc2born, cc2dead, cc2,
            approve
        ) VALUES (
            :nameRus, :surNameRus, :fullNameRus,
            :nameEngl, :surNameEngl, :fullNameEngl,
            :epigraph, :dateIn, :dateOut, :gender,
            :townIn, :cc2born, :cc2dead, :cc2,
            'NO'
        )"
    );
    $personStmt->execute([
        ':nameRus'      => toDb($nameRus),
        ':surNameRus'   => toDb($surNameRus),
        ':fullNameRus'  => toDb($fullNameRus),
        ':nameEngl'     => toDb($nameEngl),
        ':surNameEngl'  => toDb($surNameEngl),
        ':fullNameEngl' => toDb($fullNameEngl),
        ':epigraph'     => toDb($epigraph),
        ':dateIn'       => !empty($suggestion['DateIn']) ? $suggestion['DateIn'] : null,
        ':dateOut'      => !empty($suggestion['DateOut']) ? $suggestion['DateOut'] : null,
        ':gender'       => !empty($suggestion['gender']) ? $suggestion['gender'] : null,
        ':townIn'       => !empty($suggestion['TownIn']) ? toDb($suggestion['TownIn']) : null,
        ':cc2born'      => !empty($suggestion['cc2born']) ? $suggestion['cc2born'] : null,
        ':cc2dead'      => !empty($suggestion['cc2dead']) ? $suggestion['cc2dead'] : null,
        ':cc2'          => !empty($suggestion['cc2']) ? $suggestion['cc2'] : null,
    ]);

    $newPersonId = (int) $db->lastInsertId();

    // INSERT biography into histories
    $bioStmt = $db->prepare(
        'INSERT INTO histories (KodPersons, Content, Epigraph, NameURLArticle, date_pub)
         VALUES (:kod, :content, :epigraph, :title, NOW())'
    );
    $bioStmt->execute([
        ':kod'      => $newPersonId,
        ':content'  => toDb($biography),
        ':epigraph' => toDb($epigraph),
        ':title'    => !empty($title) ? toDb($title) : null,
    ]);

    $historiesId = (int) $db->lastInsertId();

    // Update suggestion: mark as published
    $updateStmt = $db->prepare(
        'UPDATE user_person_suggestions
         SET status = :status, published_person_id = :pid,
             published_at = NOW(), updated_at = NOW()
         WHERE id = :id'
    );
    $updateStmt->execute([
        ':status' => 'published',
        ':pid'    => $newPersonId,
        ':id'     => $suggestionId,
    ]);

    // Award extra reputation for published person
    $repStmt = $db->prepare(
        'UPDATE users SET reputation = reputation + 10 WHERE id = :uid'
    );
    $repStmt->execute([':uid' => $submitterUserId]);

    // Log to moderation log
    $logStmt = $db->prepare(
        'INSERT INTO users_moderation_log (moderator_id, action, target_type, target_id, note, created_at)
         VALUES (:mod_id, :action, :target_type, :target_id, :note, NOW())'
    );
    $logStmt->execute([
        ':mod_id'      => $adminId,
        ':action'      => 'approve',
        ':target_type' => 'person_suggestion',
        ':target_id'   => $suggestionId,
        ':note'        => toDb('Персона создана: Persons_id=' . $newPersonId),
    ]);

    $db->commit();

} catch (\Throwable $e) {
    $db->rollBack();
    jsonError('Failed to create person: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}

jsonSuccess([
    'person_id'    => $newPersonId,
    'histories_id' => $historiesId,
    'suggestion_id' => $suggestionId,
]);
