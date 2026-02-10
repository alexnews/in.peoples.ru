<?php

declare(strict_types=1);

/**
 * GET /api/v1/booking/persons.php
 *
 * Public endpoint — list bookable persons.
 * Filters: category (slug), q (name search), price_min, price_max,
 *          featured (1), page, per_page (max 50).
 *
 * No authentication required.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$db = getDb();

// Pagination
$page    = max(1, (int) getInput('page', 1));
$perPage = min(50, max(1, (int) getInput('per_page', 12)));
$offset  = ($page - 1) * $perPage;

// Filters
$where  = ['bp.is_active = 1', 'c.is_active = 1'];
$params = [];

// Category filter by slug
$categorySlug = trim((string) getInput('category', ''));
if ($categorySlug !== '') {
    $where[] = 'c.slug = :cat_slug';
    $params[':cat_slug'] = toDb($categorySlug);
}

// Name search
$q = trim((string) getInput('q', ''));
if ($q !== '' && mb_strlen($q, 'UTF-8') >= 2) {
    $qDb = '%' . toDb($q) . '%';
    $where[] = '(p.FullNameRus LIKE :q1 OR p.SurNameRus LIKE :q2 OR p.NameEngl LIKE :q3)';
    $params[':q1'] = $qDb;
    $params[':q2'] = $qDb;
    $params[':q3'] = $qDb;
}

// Price range
$priceMin = getInput('price_min');
if ($priceMin !== null && $priceMin !== '') {
    $where[] = 'bp.price_from >= :price_min';
    $params[':price_min'] = (int) $priceMin;
}

$priceMax = getInput('price_max');
if ($priceMax !== null && $priceMax !== '') {
    $where[] = '(bp.price_from <= :price_max OR bp.price_from IS NULL)';
    $params[':price_max'] = (int) $priceMax;
}

// Featured only
$featured = getInput('featured');
if ($featured === '1' || $featured === 1) {
    $where[] = 'bp.is_featured = 1';
}

// Only living persons
$where[] = 'p.DateOut IS NULL';

$whereClause = 'WHERE ' . implode(' AND ', $where);

// Count total
$countSql = "SELECT COUNT(*)
             FROM booking_persons bp
             INNER JOIN persons p ON p.Persons_id = bp.person_id
             INNER JOIN booking_categories c ON c.id = bp.category_id
             {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Fetch persons
$sql = "SELECT bp.id AS booking_person_id, bp.person_id, bp.price_from, bp.price_to,
               bp.short_desc, bp.is_featured, bp.sort_order,
               p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
               p.DateIn, p.Epigraph, p.famous_for,
               c.id AS category_id, c.name AS category_name, c.slug AS category_slug
        FROM booking_persons bp
        INNER JOIN persons p ON p.Persons_id = bp.person_id
        INNER JOIN booking_categories c ON c.id = bp.category_id
        {$whereClause}
        ORDER BY bp.is_featured DESC, bp.sort_order ASC, p.popularity DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

// Format output
$items = [];
foreach ($rows as $row) {
    $items[] = [
        'booking_person_id' => (int) $row['booking_person_id'],
        'person_id'         => (int) $row['person_id'],
        'name'              => fromDb($row['FullNameRus']),
        'name_eng'          => fromDb($row['FullNameEngl']),
        'photo'             => fromDb($row['NamePhoto']),
        'path'              => fromDb($row['AllUrlInSity']),
        'birth_date'        => fromDb($row['DateIn']),
        'epigraph'          => fromDb($row['Epigraph']),
        'famous_for'        => fromDb($row['famous_for']),
        'price_from'        => $row['price_from'] !== null ? (int) $row['price_from'] : null,
        'price_to'          => $row['price_to'] !== null ? (int) $row['price_to'] : null,
        'short_desc'        => fromDb($row['short_desc']),
        'is_featured'       => (bool) $row['is_featured'],
        'category'          => [
            'id'   => (int) $row['category_id'],
            'name' => fromDb($row['category_name']),
            'slug' => $row['category_slug'],
        ],
    ];
}

jsonPaginated($items, $total, $page, $perPage);
