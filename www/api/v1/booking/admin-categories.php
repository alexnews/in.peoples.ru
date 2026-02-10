<?php

declare(strict_types=1);

/**
 * POST /api/v1/booking/admin-categories.php
 *
 * Admin endpoint — CRUD for booking categories.
 * Requires admin role + CSRF.
 *
 * Actions (via "action" field):
 *   - create: name, slug, description, icon, sort_order
 *   - update: id, name, slug, description, icon, sort_order, is_active
 *   - delete: id
 *   - reorder: items [{id, sort_order}, ...]
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');
$user = requireRole('admin');
requireCsrf();

$db = getDb();
$action = trim((string) getInput('action', ''));

switch ($action) {

    case 'create': {
        $name = trim((string) getInput('name', ''));
        $slug = trim((string) getInput('slug', ''));
        $description = trim((string) getInput('description', ''));
        $icon = trim((string) getInput('icon', ''));
        $sortOrder = (int) getInput('sort_order', 0);

        if ($name === '') {
            jsonError('Укажите название категории.', 'VALIDATION_ERROR', 400, ['name' => 'Обязательное поле']);
        }
        if ($slug === '') {
            jsonError('Укажите slug категории.', 'VALIDATION_ERROR', 400, ['slug' => 'Обязательное поле']);
        }
        if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
            jsonError('Slug может содержать только латинские буквы, цифры и дефис.', 'VALIDATION_ERROR', 400, ['slug' => 'Некорректный формат']);
        }

        // Check unique slug
        $chk = $db->prepare('SELECT id FROM booking_categories WHERE slug = :slug');
        $chk->execute([':slug' => $slug]);
        if ($chk->fetch()) {
            jsonError('Категория с таким slug уже существует.', 'DUPLICATE', 409, ['slug' => 'Уже существует']);
        }

        $stmt = $db->prepare(
            'INSERT INTO booking_categories (name, slug, description, icon, sort_order)
             VALUES (:name, :slug, :desc, :icon, :sort)'
        );
        $stmt->execute([
            ':name' => toDb($name),
            ':slug' => $slug,
            ':desc' => $description !== '' ? toDb($description) : null,
            ':icon' => $icon !== '' ? $icon : null,
            ':sort' => $sortOrder,
        ]);

        $id = (int) $db->lastInsertId();

        $row = $db->prepare('SELECT * FROM booking_categories WHERE id = :id');
        $row->execute([':id' => $id]);
        jsonSuccess(fromDbArray($row->fetch()));
    }

    case 'update': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID категории.', 'VALIDATION_ERROR', 400);
        }

        $existing = $db->prepare('SELECT * FROM booking_categories WHERE id = :id');
        $existing->execute([':id' => $id]);
        $cat = $existing->fetch();
        if (!$cat) {
            jsonError('Категория не найдена.', 'NOT_FOUND', 404);
        }

        $updates = [];
        $params = [':id' => $id];

        $name = getInput('name');
        if ($name !== null) {
            $name = trim((string) $name);
            if ($name === '') {
                jsonError('Название не может быть пустым.', 'VALIDATION_ERROR', 400);
            }
            $updates[] = 'name = :name';
            $params[':name'] = toDb($name);
        }

        $slug = getInput('slug');
        if ($slug !== null) {
            $slug = trim((string) $slug);
            if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
                jsonError('Некорректный формат slug.', 'VALIDATION_ERROR', 400);
            }
            $chk = $db->prepare('SELECT id FROM booking_categories WHERE slug = :slug AND id != :cid');
            $chk->execute([':slug' => $slug, ':cid' => $id]);
            if ($chk->fetch()) {
                jsonError('Slug уже занят.', 'DUPLICATE', 409);
            }
            $updates[] = 'slug = :slug';
            $params[':slug'] = $slug;
        }

        $description = getInput('description');
        if ($description !== null) {
            $updates[] = 'description = :desc';
            $params[':desc'] = trim((string) $description) !== '' ? toDb(trim((string) $description)) : null;
        }

        $icon = getInput('icon');
        if ($icon !== null) {
            $updates[] = 'icon = :icon';
            $params[':icon'] = trim((string) $icon) !== '' ? trim((string) $icon) : null;
        }

        $sortOrder = getInput('sort_order');
        if ($sortOrder !== null) {
            $updates[] = 'sort_order = :sort';
            $params[':sort'] = (int) $sortOrder;
        }

        $isActive = getInput('is_active');
        if ($isActive !== null) {
            $updates[] = 'is_active = :active';
            $params[':active'] = (int) (bool) $isActive;
        }

        if (empty($updates)) {
            jsonError('Нет полей для обновления.', 'VALIDATION_ERROR', 400);
        }

        $sql = 'UPDATE booking_categories SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $db->prepare($sql)->execute($params);

        $row = $db->prepare('SELECT * FROM booking_categories WHERE id = :id');
        $row->execute([':id' => $id]);
        jsonSuccess(fromDbArray($row->fetch()));
    }

    case 'delete': {
        $id = (int) getInput('id', 0);
        if ($id <= 0) {
            jsonError('Укажите ID категории.', 'VALIDATION_ERROR', 400);
        }

        // Check if category has persons
        $chk = $db->prepare('SELECT COUNT(*) FROM booking_persons WHERE category_id = :cid');
        $chk->execute([':cid' => $id]);
        $count = (int) $chk->fetchColumn();
        if ($count > 0) {
            jsonError("Нельзя удалить категорию с {$count} привязанными персонами. Сначала удалите или переместите их.", 'HAS_DEPENDENCIES', 400);
        }

        $db->prepare('DELETE FROM booking_categories WHERE id = :id')->execute([':id' => $id]);
        jsonSuccess(['deleted' => true]);
    }

    case 'reorder': {
        $items = getInput('items', []);
        if (!is_array($items) || empty($items)) {
            jsonError('Укажите массив items с id и sort_order.', 'VALIDATION_ERROR', 400);
        }

        $stmt = $db->prepare('UPDATE booking_categories SET sort_order = :sort WHERE id = :id');
        foreach ($items as $item) {
            if (isset($item['id'], $item['sort_order'])) {
                $stmt->execute([':sort' => (int) $item['sort_order'], ':id' => (int) $item['id']]);
            }
        }
        jsonSuccess(['reordered' => true]);
    }

    default:
        jsonError('Неизвестное действие. Допустимые: create, update, delete, reorder.', 'INVALID_ACTION', 400);
}
