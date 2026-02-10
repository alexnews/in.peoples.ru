<?php

declare(strict_types=1);

/**
 * GET /api/v1/booking/categories.php
 *
 * Public endpoint — list active booking categories with person counts.
 * No authentication required.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$db = getDb();

$stmt = $db->query(
    'SELECT c.id, c.name, c.slug, c.description, c.icon, c.sort_order,
            COUNT(bp.id) AS person_count
     FROM booking_categories c
     LEFT JOIN booking_persons bp ON bp.category_id = c.id AND bp.is_active = 1
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY c.sort_order ASC'
);
$rows = $stmt->fetchAll();

jsonSuccess(fromDbRows($rows));
