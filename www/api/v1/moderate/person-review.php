<?php

declare(strict_types=1);

/**
 * POST /api/v1/moderate/person-review.php
 *
 * Moderator reviews a person suggestion: approve, reject, or request revision.
 * This only changes the status in `user_person_suggestions` — does NOT touch `persons`.
 *
 * Tables used:
 * - user_person_suggestions: status update
 * - users: reputation update
 * - users_moderation_log: audit trail
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$moderator = requireRole('moderator');
requireCsrf();

$db = getDb();
$moderatorId = (int) $moderator['id'];

$suggestionId = getInput('suggestion_id');
$action       = getInput('action');
$note         = getInput('note', '');

if ($suggestionId === null || $suggestionId === '') {
    jsonError('Suggestion ID is required', 'VALIDATION_ERROR', 400);
}
$suggestionId = (int) $suggestionId;

$validActions = ['approve', 'reject', 'request_revision'];
if (!in_array($action, $validActions, true)) {
    jsonError('Action must be one of: approve, reject, request_revision', 'VALIDATION_ERROR', 400);
}

$note = trim((string) $note);

// Fetch the suggestion
$stmt = $db->prepare('SELECT * FROM user_person_suggestions WHERE id = :id');
$stmt->execute([':id' => $suggestionId]);
$suggestion = $stmt->fetch();

if (!$suggestion) {
    jsonError('Person suggestion not found', 'NOT_FOUND', 404);
}

if ($suggestion['status'] !== 'pending') {
    jsonError('Only pending suggestions can be reviewed', 'STATUS_ERROR', 400);
}

$submitterUserId = (int) $suggestion['user_id'];

$db->beginTransaction();

try {
    if ($action === 'approve') {
        $updateStmt = $db->prepare(
            'UPDATE user_person_suggestions
             SET status = :status, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'approved',
            ':mod_id' => $moderatorId,
            ':note'   => $note !== '' ? toDb($note) : null,
            ':id'     => $suggestionId,
        ]);

        // Award reputation for approved suggestion
        $repStmt = $db->prepare(
            'UPDATE users SET reputation = reputation + 5 WHERE id = :uid'
        );
        $repStmt->execute([':uid' => $submitterUserId]);

    } elseif ($action === 'reject') {
        $updateStmt = $db->prepare(
            'UPDATE user_person_suggestions
             SET status = :status, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'rejected',
            ':mod_id' => $moderatorId,
            ':note'   => $note !== '' ? toDb($note) : null,
            ':id'     => $suggestionId,
        ]);

        // Deduct reputation
        $repStmt = $db->prepare(
            'UPDATE users SET reputation = GREATEST(0, reputation - 2) WHERE id = :uid'
        );
        $repStmt->execute([':uid' => $submitterUserId]);

    } else {
        // request_revision
        if ($note === '') {
            $db->rollBack();
            jsonError('A note is required when requesting revision', 'VALIDATION_ERROR', 400);
        }

        $updateStmt = $db->prepare(
            'UPDATE user_person_suggestions
             SET status = :status, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'revision_requested',
            ':mod_id' => $moderatorId,
            ':note'   => toDb($note),
            ':id'     => $suggestionId,
        ]);
    }

    // Log to moderation log
    $logStmt = $db->prepare(
        'INSERT INTO users_moderation_log (moderator_id, action, target_type, target_id, note, created_at)
         VALUES (:mod_id, :action, :target_type, :target_id, :note, NOW())'
    );
    $logStmt->execute([
        ':mod_id'      => $moderatorId,
        ':action'      => $action,
        ':target_type' => 'person_suggestion',
        ':target_id'   => $suggestionId,
        ':note'        => $note !== '' ? toDb($note) : null,
    ]);

    $db->commit();

} catch (\Throwable $e) {
    $db->rollBack();
    jsonError('Review failed: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}

jsonSuccess(['id' => $suggestionId, 'action' => $action]);
