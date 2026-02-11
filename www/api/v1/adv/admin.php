<?php

declare(strict_types=1);

/**
 * GET|POST /api/v1/adv/admin.php
 *
 * Admin endpoint — list and manage advertising requests.
 * Requires admin role.
 *
 * GET: List requests with filters (status, page, per_page)
 * POST: Update request (action: update_status, add_note)
 *   - update_status: id, status, note
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
    $validStatuses = ['new', 'in_progress', 'completed', 'rejected', 'spam'];
    if (in_array($statusFilter, $validStatuses, true)) {
        $where[] = 'ar.status = :status';
        $params[':status'] = $statusFilter;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM user_ad_requests ar {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $sql = "SELECT ar.*,
                   u.display_name AS reviewer_name
            FROM user_ad_requests ar
            LEFT JOIN users u ON u.id = ar.reviewed_by
            {$whereClause}
            ORDER BY ar.created_at DESC
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

        $validStatuses = ['new', 'in_progress', 'completed', 'rejected', 'spam'];
        if (!in_array($status, $validStatuses, true)) {
            jsonError('Некорректный статус.', 'VALIDATION_ERROR', 400);
        }

        // Get current request
        $existing = $db->prepare('SELECT id, status FROM user_ad_requests WHERE id = :id');
        $existing->execute([':id' => $id]);
        $request = $existing->fetch();
        if (!$request) {
            jsonError('Заявка не найдена.', 'NOT_FOUND', 404);
        }

        $oldStatus = $request['status'];

        // Update status + reviewed_by + optional note
        $updateParams = [':status' => $status, ':reviewed_by' => (int) $currentUser['id'], ':id' => $id];
        $updateSql = 'UPDATE user_ad_requests SET status = :status, reviewed_by = :reviewed_by';
        if ($note !== '') {
            $updateSql .= ', admin_note = :note';
            $updateParams[':note'] = toDb($note);
        }
        $updateSql .= ' WHERE id = :id';
        $db->prepare($updateSql)->execute($updateParams);

        jsonSuccess(['id' => $id, 'old_status' => $oldStatus, 'new_status' => $status]);
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

        $db->prepare('UPDATE user_ad_requests SET admin_note = :note, reviewed_by = :reviewed_by WHERE id = :id')
           ->execute([':note' => toDb($note), ':reviewed_by' => (int) $currentUser['id'], ':id' => $id]);

        jsonSuccess(['id' => $id, 'note' => $note]);
    }

    default:
        jsonError('Неизвестное действие. Допустимые: update_status, add_note.', 'INVALID_ACTION', 400);
}
