<?php

declare(strict_types=1);

/**
 * GET|POST /api/v1/booking/admin-applications.php
 *
 * Admin endpoint — list and manage celebrity self-registration applications.
 * Requires admin role.
 *
 * GET: List applications with filters (status, category_id, page, per_page)
 * POST: Manage applications (action: update_status, link_person, add_note, add_to_booking)
 *   - update_status: id, status, note
 *   - link_person: id, person_id
 *   - add_note: id, note
 *   - add_to_booking: id, category_id, price_from, price_to
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET', 'POST');
$currentUser = requireRole('admin');

$db = getDb();

// --- GET: List applications ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page    = max(1, (int) getInput('page', 1));
    $perPage = min(100, max(1, (int) getInput('per_page', 30)));
    $offset  = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    $statusFilter = trim((string) getInput('status', ''));
    $validStatuses = ['new', 'contacted', 'approved', 'rejected', 'spam'];
    if (in_array($statusFilter, $validStatuses, true)) {
        $where[] = 'ba.status = :status';
        $params[':status'] = $statusFilter;
    }

    $categoryFilter = getInput('category_id');
    if ($categoryFilter !== null && $categoryFilter !== '') {
        $where[] = 'ba.category_id = :category_id';
        $params[':category_id'] = (int) $categoryFilter;
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count
    $countStmt = $db->prepare("SELECT COUNT(*) FROM booking_applications ba {$whereClause}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch
    $sql = "SELECT ba.*,
                   c.name AS category_name,
                   p.FullNameRus AS person_name, p.NamePhoto AS person_photo,
                   u.display_name AS reviewer_name,
                   bp_link.id AS linked_booking_id
            FROM booking_applications ba
            LEFT JOIN booking_categories c ON c.id = ba.category_id
            LEFT JOIN persons p ON p.Persons_id = ba.person_id
            LEFT JOIN users u ON u.id = ba.reviewed_by
            LEFT JOIN booking_persons bp_link ON bp_link.id = ba.booking_person_id
            {$whereClause}
            ORDER BY ba.created_at DESC
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

// --- POST: Manage applications ---
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

        $validStatuses = ['new', 'contacted', 'approved', 'rejected', 'spam'];
        if (!in_array($status, $validStatuses, true)) {
            jsonError('Некорректный статус.', 'VALIDATION_ERROR', 400);
        }

        $existing = $db->prepare('SELECT id, status FROM booking_applications WHERE id = :id');
        $existing->execute([':id' => $id]);
        $app = $existing->fetch();
        if (!$app) {
            jsonError('Заявка не найдена.', 'NOT_FOUND', 404);
        }

        $oldStatus = $app['status'];

        $updateParams = [':status' => $status, ':uid' => (int) $currentUser['id'], ':id' => $id];
        $updateSql = 'UPDATE booking_applications SET status = :status, reviewed_by = :uid';
        if ($note !== '') {
            $updateSql .= ', admin_note = :note';
            $updateParams[':note'] = toDb($note);
        }
        $updateSql .= ' WHERE id = :id';
        $db->prepare($updateSql)->execute($updateParams);

        jsonSuccess(['id' => $id, 'old_status' => $oldStatus, 'new_status' => $status]);
    }

    case 'link_person': {
        $id       = (int) getInput('id', 0);
        $personId = (int) getInput('person_id', 0);

        if ($id <= 0) {
            jsonError('Укажите ID заявки.', 'VALIDATION_ERROR', 400);
        }
        if ($personId <= 0) {
            jsonError('Укажите person_id.', 'VALIDATION_ERROR', 400);
        }

        // Verify person exists
        $pChk = $db->prepare('SELECT Persons_id, FullNameRus FROM persons WHERE Persons_id = :pid');
        $pChk->execute([':pid' => $personId]);
        $person = $pChk->fetch();
        if (!$person) {
            jsonError('Персона не найдена в базе.', 'NOT_FOUND', 404);
        }

        $db->prepare('UPDATE booking_applications SET person_id = :pid, reviewed_by = :uid WHERE id = :id')
           ->execute([':pid' => $personId, ':uid' => (int) $currentUser['id'], ':id' => $id]);

        jsonSuccess([
            'id' => $id,
            'person_id' => $personId,
            'person_name' => fromDb($person['FullNameRus'] ?? ''),
        ]);
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

        $db->prepare('UPDATE booking_applications SET admin_note = :note, reviewed_by = :uid WHERE id = :id')
           ->execute([':note' => toDb($note), ':uid' => (int) $currentUser['id'], ':id' => $id]);

        jsonSuccess(['id' => $id, 'note' => $note]);
    }

    case 'add_to_booking': {
        $id         = (int) getInput('id', 0);
        $categoryId = (int) getInput('category_id', 0);
        $priceFrom  = getInput('price_from');
        $priceTo    = getInput('price_to');

        if ($id <= 0) {
            jsonError('Укажите ID заявки.', 'VALIDATION_ERROR', 400);
        }

        // Get application
        $appStmt = $db->prepare('SELECT * FROM booking_applications WHERE id = :id');
        $appStmt->execute([':id' => $id]);
        $app = $appStmt->fetch();
        if (!$app) {
            jsonError('Заявка не найдена.', 'NOT_FOUND', 404);
        }

        if ($app['status'] !== 'approved') {
            jsonError('Заявка должна быть одобрена (approved) перед добавлением в каталог.', 'VALIDATION_ERROR', 400);
        }

        if (empty($app['person_id'])) {
            jsonError('Сначала привяжите заявку к персоне (link_person).', 'VALIDATION_ERROR', 400);
        }

        if ($categoryId <= 0) {
            // Use category from application, or require explicit
            $categoryId = (int) ($app['category_id'] ?? 0);
            if ($categoryId <= 0) {
                jsonError('Укажите category_id.', 'VALIDATION_ERROR', 400);
            }
        }

        // Check duplicate in booking_persons
        $dChk = $db->prepare('SELECT id FROM booking_persons WHERE person_id = :pid AND category_id = :cid');
        $dChk->execute([':pid' => (int) $app['person_id'], ':cid' => $categoryId]);
        if ($dChk->fetch()) {
            jsonError('Эта персона уже добавлена в данную категорию приглашений.', 'DUPLICATE', 409);
        }

        // Insert into booking_persons
        $bpStmt = $db->prepare(
            'INSERT INTO booking_persons
                (person_id, category_id, price_from, price_to, added_by)
             VALUES
                (:pid, :cid, :pf, :pt, :uid)'
        );
        $bpStmt->execute([
            ':pid' => (int) $app['person_id'],
            ':cid' => $categoryId,
            ':pf'  => $priceFrom !== null && $priceFrom !== '' ? (int) $priceFrom : null,
            ':pt'  => $priceTo !== null && $priceTo !== '' ? (int) $priceTo : null,
            ':uid' => (int) $currentUser['id'],
        ]);

        $bookingPersonId = (int) $db->lastInsertId();

        // Update application with booking_person_id and status
        $db->prepare(
            'UPDATE booking_applications SET booking_person_id = :bpid, status = :status, reviewed_by = :uid WHERE id = :id'
        )->execute([
            ':bpid'   => $bookingPersonId,
            ':status' => 'approved',
            ':uid'    => (int) $currentUser['id'],
            ':id'     => $id,
        ]);

        jsonSuccess([
            'id' => $id,
            'booking_person_id' => $bookingPersonId,
            'person_id' => (int) $app['person_id'],
            'category_id' => $categoryId,
        ]);
    }

    default:
        jsonError('Неизвестное действие. Допустимые: update_status, link_person, add_note, add_to_booking.', 'INVALID_ACTION', 400);
}
