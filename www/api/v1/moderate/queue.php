<?php

declare(strict_types=1);

/**
 * GET /api/v1/moderate/queue.php
 *
 * Moderation queue: list submissions awaiting review.
 * Default filter: status='pending'. Supports ?status= filter.
 * Sorted by created_at ASC (oldest first). Paginated.
 *
 * Requires moderator role.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$user = requireRole('moderator');
$db = getDb();

$status  = getInput('status', 'pending');
$page    = max(1, (int) getInput('page', 1));
$perPage = min(100, max(1, (int) getInput('per_page', 20)));
$offset  = ($page - 1) * $perPage;

// Build query
$where  = [];
$params = [];

// Status filter
$validStatuses = ['draft', 'pending', 'approved', 'rejected', 'revision_requested'];
if (in_array($status, $validStatuses, true)) {
    $where[] = 's.status = :status';
    $params[':status'] = $status;
}

// Optional section filter
$sectionId = getInput('section_id');
if ($sectionId !== null && $sectionId !== '') {
    $where[] = 's.section_id = :section_id';
    $params[':section_id'] = (int) $sectionId;
}

// Optional user filter
$filterUserId = getInput('user_id');
if ($filterUserId !== null && $filterUserId !== '') {
    $where[] = 's.user_id = :filter_uid';
    $params[':filter_uid'] = (int) $filterUserId;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count total
$countSql = "SELECT COUNT(*) FROM user_submissions s {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

// Fetch queue
$sql = "SELECT s.id, s.user_id, s.section_id, s.KodPersons, s.title, s.content,
               s.epigraph, s.source_url, s.photo_path, s.status,
               s.moderator_id, s.moderator_note, s.reviewed_at,
               s.published_id, s.created_at, s.updated_at,
               u.username AS submitter_username, u.display_name AS submitter_display_name,
               u.reputation AS submitter_reputation,
               p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
               p.NamePhoto AS person_photo,
               ps.nameRus AS section_name, ps.nameEng AS section_name_eng
        FROM user_submissions s
        INNER JOIN users u ON u.id = s.user_id
        LEFT JOIN persons p ON p.Persons_id = s.KodPersons
        LEFT JOIN peoples_section ps ON ps.id = s.section_id
        {$whereClause}
        ORDER BY s.created_at ASC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$items = fromDbRows($rows);

jsonPaginated($items, $total, $page, $perPage);
