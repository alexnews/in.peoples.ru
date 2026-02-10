<?php

declare(strict_types=1);

/**
 * GET|POST /api/v1/booking/admin-requests.php
 *
 * Admin endpoint — list and manage booking requests.
 * Requires admin role.
 *
 * GET: List requests with filters (status, page, per_page, assigned_to)
 * POST: Update request (action: update_status, assign, add_note)
 *   - update_status: id, status, note
 *   - assign: id, assigned_to
 *   - add_note: id, note
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET', 'POST');
$currentUser = requireRole('admin');

$db = getDb();

// --- GET: List requests ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page    = max(1, (int) getInput('page', 1));
    $perPage = min(100, max(1, (int) getInput('per_page', 30)));
    $offset  = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    $statusFilter = trim((string) getInput('status', ''));
    $validStatuses = ['new', 'in_progress', 'contacted', 'completed', 'cancelled', 'spam'];
    if (in_array($statusFilter, $validStatuses, true)) {
        $where[] = 'br.status = :status';
        $params[':status'] = $statusFilter;
    }

    $assignedTo = getInput('assigned_to');
    if ($assignedTo !== null && $assignedTo !== '') {
        $where[] = 'br.assigned_to = :assigned_to';
        $params[':assigned_to'] = (int) $assignedTo;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM booking_requests br {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $sql = "SELECT br.*,
                   p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
                   p.NamePhoto AS person_photo,
                   u.display_name AS assigned_name
            FROM booking_requests br
            LEFT JOIN persons p ON p.Persons_id = br.person_id
            LEFT JOIN users u ON u.id = br.assigned_to
            {$whereClause}
            ORDER BY br.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    jsonPaginated(fromDbRows($rows), $total, $page, $perPage);
}

// --- POST: Update request ---
requireCsrf();

$action = trim((string) getInput('action', ''));

switch ($action) {

    case 'update_status': {
        $id     = (int) getInput('id', 0);
        $status = trim((string) getInput('status', ''));
        $note   = trim((string) getInput('note', ''));

        if ($id <= 0) {
            jsonError('Укажите ID заявки.', 'VALIDATION_ERROR', 400);
        }

        $validStatuses = ['new', 'in_progress', 'contacted', 'completed', 'cancelled', 'spam'];
        if (!in_array($status, $validStatuses, true)) {
            jsonError('Некорректный статус.', 'VALIDATION_ERROR', 400);
        }

        // Get current request
        $existing = $db->prepare('SELECT id, status FROM booking_requests WHERE id = :id');
        $existing->execute([':id' => $id]);
        $request = $existing->fetch();
        if (!$request) {
            jsonError('Заявка не найдена.', 'NOT_FOUND', 404);
        }

        $oldStatus = $request['status'];

        // Update status
        $updateParams = [':status' => $status, ':id' => $id];
        $updateSql = 'UPDATE booking_requests SET status = :status';
        if ($note !== '') {
            $updateSql .= ', admin_note = :note';
            $updateParams[':note'] = toDb($note);
        }
        $updateSql .= ' WHERE id = :id';
        $db->prepare($updateSql)->execute($updateParams);

        // Log status change
        $logStmt = $db->prepare(
            'INSERT INTO booking_request_status_log (request_id, old_status, new_status, note, changed_by)
             VALUES (:rid, :old, :new, :note, :uid)'
        );
        $logStmt->execute([
            ':rid'  => $id,
            ':old'  => $oldStatus,
            ':new'  => $status,
            ':note' => $note !== '' ? toDb($note) : null,
            ':uid'  => (int) $currentUser['id'],
        ]);

        jsonSuccess(['id' => $id, 'old_status' => $oldStatus, 'new_status' => $status]);
    }

    case 'assign': {
        $id         = (int) getInput('id', 0);
        $assignedTo = getInput('assigned_to');

        if ($id <= 0) {
            jsonError('Укажите ID заявки.', 'VALIDATION_ERROR', 400);
        }

        $db->prepare('UPDATE booking_requests SET assigned_to = :uid WHERE id = :id')
           ->execute([
               ':uid' => $assignedTo !== null && $assignedTo !== '' ? (int) $assignedTo : null,
               ':id'  => $id,
           ]);

        jsonSuccess(['id' => $id, 'assigned_to' => $assignedTo !== null && $assignedTo !== '' ? (int) $assignedTo : null]);
    }

    case 'add_note': {
        $id   = (int) getInput('id', 0);
        $note = trim((string) getInput('note', ''));

        if ($id <= 0) {
            jsonError('Укажите ID заявки.', 'VALIDATION_ERROR', 400);
        }
        if ($note === '') {
            jsonError('Укажите текст заметки.', 'VALIDATION_ERROR', 400);
        }

        $db->prepare('UPDATE booking_requests SET admin_note = :note WHERE id = :id')
           ->execute([':note' => toDb($note), ':id' => $id]);

        jsonSuccess(['id' => $id, 'note' => $note]);
    }

    default:
        jsonError('Неизвестное действие. Допустимые: update_status, assign, add_note.', 'INVALID_ACTION', 400);
}
