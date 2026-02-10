<?php

declare(strict_types=1);

/**
 * POST /api/v1/booking/admin-persons.php
 *
 * Admin endpoint — manage bookable persons.
 * Requires admin role + CSRF.
 *
 * Actions:
 *   - add: person_id, category_id, price_from, price_to, description, short_desc, is_featured, sort_order
 *   - update: id, (any fields)
 *   - remove: id
 *   - toggle_active: id
 *   - toggle_featured: id
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');
$user = requireRole('admin');
requireCsrf();

$db = getDb();
$action = trim((string) getInput('action', ''));

switch ($action) {

    case 'add': {
        $personId   = (int) getInput('person_id', 0);
        $categoryId = (int) getInput('category_id', 0);

        if ($personId <= 0) {
            jsonError('Укажите person_id.', 'VALIDATION_ERROR', 400, ['person_id' => 'Обязательное поле']);
        }
        if ($categoryId <= 0) {
            jsonError('Укажите category_id.', 'VALIDATION_ERROR', 400, ['category_id' => 'Обязательное поле']);
        }

        // Verify person exists
        $pChk = $db->prepare('SELECT Persons_id, FullNameRus FROM persons WHERE Persons_id = :pid');
        $pChk->execute([':pid' => $personId]);
        if (!$pChk->fetch()) {
            jsonError('Персона не найдена в базе.', 'NOT_FOUND', 404);
        }

        // Verify category exists
        $cChk = $db->prepare('SELECT id FROM booking_categories WHERE id = :cid');
        $cChk->execute([':cid' => $categoryId]);
        if (!$cChk->fetch()) {
            jsonError('Категория не найдена.', 'NOT_FOUND', 404);
        }

        // Check duplicate
        $dChk = $db->prepare('SELECT id FROM booking_persons WHERE person_id = :pid AND category_id = :cid');
        $dChk->execute([':pid' => $personId, ':cid' => $categoryId]);
        if ($dChk->fetch()) {
            jsonError('Эта персона уже добавлена в данную категорию.', 'DUPLICATE', 409);
        }

        $priceFrom  = getInput('price_from');
        $priceTo    = getInput('price_to');
        $description = trim((string) getInput('description', ''));
        $shortDesc  = trim((string) getInput('short_desc', ''));
        $isFeatured = (int) (bool) getInput('is_featured', 0);
        $sortOrder  = (int) getInput('sort_order', 0);

        $stmt = $db->prepare(
            'INSERT INTO booking_persons
                (person_id, category_id, price_from, price_to, description, short_desc,
                 is_featured, sort_order, added_by)
             VALUES
                (:pid, :cid, :pf, :pt, :desc, :sd, :feat, :sort, :uid)'
        );
        $stmt->execute([
            ':pid'  => $personId,
            ':cid'  => $categoryId,
            ':pf'   => $priceFrom !== null && $priceFrom !== '' ? (int) $priceFrom : null,
            ':pt'   => $priceTo !== null && $priceTo !== '' ? (int) $priceTo : null,
            ':desc' => $description !== '' ? toDb($description) : null,
            ':sd'   => $shortDesc !== '' ? toDb($shortDesc) : null,
            ':feat' => $isFeatured,
            ':sort' => $sortOrder,
            ':uid'  => (int) $user['id'],
        ]);

        $id = (int) $db->lastInsertId();

        // Return with person info
        $row = $db->prepare(
            'SELECT bp.*, p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
                    c.name AS category_name, c.slug AS category_slug
             FROM booking_persons bp
             INNER JOIN persons p ON p.Persons_id = bp.person_id
             INNER JOIN booking_categories c ON c.id = bp.category_id
             WHERE bp.id = :id'
        );
        $row->execute([':id' => $id]);
        jsonSuccess(fromDbArray($row->fetch()));
    }

    case 'update': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID записи.', 'VALIDATION_ERROR', 400);
        }

        $existing = $db->prepare('SELECT * FROM booking_persons WHERE id = :id');
        $existing->execute([':id' => $id]);
        if (!$existing->fetch()) {
            jsonError('Запись не найдена.', 'NOT_FOUND', 404);
        }

        $updates = [];
        $params = [':id' => $id];

        $categoryId = getInput('category_id');
        if ($categoryId !== null) {
            $updates[] = 'category_id = :cid';
            $params[':cid'] = (int) $categoryId;
        }

        $priceFrom = getInput('price_from');
        if ($priceFrom !== null) {
            $updates[] = 'price_from = :pf';
            $params[':pf'] = $priceFrom !== '' ? (int) $priceFrom : null;
        }

        $priceTo = getInput('price_to');
        if ($priceTo !== null) {
            $updates[] = 'price_to = :pt';
            $params[':pt'] = $priceTo !== '' ? (int) $priceTo : null;
        }

        $description = getInput('description');
        if ($description !== null) {
            $updates[] = 'description = :desc';
            $params[':desc'] = trim((string) $description) !== '' ? toDb(trim((string) $description)) : null;
        }

        $shortDesc = getInput('short_desc');
        if ($shortDesc !== null) {
            $updates[] = 'short_desc = :sd';
            $params[':sd'] = trim((string) $shortDesc) !== '' ? toDb(trim((string) $shortDesc)) : null;
        }

        $isFeatured = getInput('is_featured');
        if ($isFeatured !== null) {
            $updates[] = 'is_featured = :feat';
            $params[':feat'] = (int) (bool) $isFeatured;
        }

        $isActive = getInput('is_active');
        if ($isActive !== null) {
            $updates[] = 'is_active = :active';
            $params[':active'] = (int) (bool) $isActive;
        }

        $sortOrder = getInput('sort_order');
        if ($sortOrder !== null) {
            $updates[] = 'sort_order = :sort';
            $params[':sort'] = (int) $sortOrder;
        }

        if (empty($updates)) {
            jsonError('Нет полей для обновления.', 'VALIDATION_ERROR', 400);
        }

        $sql = 'UPDATE booking_persons SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $db->prepare($sql)->execute($params);

        $row = $db->prepare(
            'SELECT bp.*, p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
                    c.name AS category_name, c.slug AS category_slug
             FROM booking_persons bp
             INNER JOIN persons p ON p.Persons_id = bp.person_id
             INNER JOIN booking_categories c ON c.id = bp.category_id
             WHERE bp.id = :id'
        );
        $row->execute([':id' => $id]);
        jsonSuccess(fromDbArray($row->fetch()));
    }

    case 'remove': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID записи.', 'VALIDATION_ERROR', 400);
        }

        $db->prepare('DELETE FROM booking_persons WHERE id = :id')->execute([':id' => $id]);
        jsonSuccess(['deleted' => true]);
    }

    case 'toggle_active': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID записи.', 'VALIDATION_ERROR', 400);
        }

        $db->prepare('UPDATE booking_persons SET is_active = NOT is_active WHERE id = :id')
           ->execute([':id' => $id]);

        $row = $db->prepare('SELECT id, is_active FROM booking_persons WHERE id = :id');
        $row->execute([':id' => $id]);
        jsonSuccess($row->fetch());
    }

    case 'toggle_featured': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID записи.', 'VALIDATION_ERROR', 400);
        }

        $db->prepare('UPDATE booking_persons SET is_featured = NOT is_featured WHERE id = :id')
           ->execute([':id' => $id]);

        $row = $db->prepare('SELECT id, is_featured FROM booking_persons WHERE id = :id');
        $row->execute([':id' => $id]);
        jsonSuccess($row->fetch());
    }

    default:
        jsonError('Неизвестное действие. Допустимые: add, update, remove, toggle_active, toggle_featured.', 'INVALID_ACTION', 400);
}
