<?php

declare(strict_types=1);

/**
 * GET|POST /api/v1/submissions/
 *
 * GET:  List current user's submissions (paginated, filterable).
 * POST: Create a new submission.
 *
 * Requires authentication.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET', 'POST');

$user = requireAuth();
$db = getDb();
$userId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ------------------------------------------------------------------
    // GET: List submissions with optional filters
    // ------------------------------------------------------------------
    $status    = getInput('status');
    $sectionId = getInput('section_id');
    $page      = max(1, (int) getInput('page', 1));
    $perPage   = min(100, max(1, (int) getInput('per_page', 20)));
    $offset    = ($page - 1) * $perPage;

    // Build WHERE clauses
    $where  = ['s.user_id = :uid'];
    $params = [':uid' => $userId];

    if ($status !== null && $status !== '') {
        $validStatuses = ['draft', 'pending', 'approved', 'rejected', 'revision_requested'];
        if (in_array($status, $validStatuses, true)) {
            $where[] = 's.status = :status';
            $params[':status'] = $status;
        }
    }

    if ($sectionId !== null && $sectionId !== '') {
        $where[] = 's.section_id = :section_id';
        $params[':section_id'] = (int) $sectionId;
    }

    $whereClause = implode(' AND ', $where);

    // Count total
    $countSql = "SELECT COUNT(*) FROM user_submissions s WHERE {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch page
    $sql = "SELECT s.id, s.user_id, s.section_id, s.KodPersons, s.title, s.content,
                   s.epigraph, s.source_url, s.photo_path, s.status,
                   s.moderator_id, s.moderator_note, s.reviewed_at,
                   s.published_id, s.created_at, s.updated_at,
                   p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
                   ps.nameRus AS section_name
            FROM user_submissions s
            LEFT JOIN persons p ON p.Persons_id = s.KodPersons
            LEFT JOIN peoples_section ps ON ps.id = s.section_id
            WHERE {$whereClause}
            ORDER BY s.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Convert all text fields from cp1251 to UTF-8
    $items = fromDbRows($rows);

    jsonPaginated($items, $total, $page, $perPage);

} else {
    // ------------------------------------------------------------------
    // POST: Create a new submission
    // ------------------------------------------------------------------
    requireCsrf();

    $sectionId  = getInput('section_id');
    $kodPersons = getInput('KodPersons');
    $title      = getInput('title');
    $content    = getInput('content');
    $epigraph   = getInput('epigraph');
    $sourceUrl  = getInput('source_url');
    $status     = getInput('status', 'draft');

    // Validate required fields
    $errors = [];

    if ($sectionId === null || $sectionId === '') {
        $errors['section_id'] = 'Section is required';
    }

    if ($title === null || trim((string) $title) === '') {
        $errors['title'] = 'Title is required';
    }

    if ($content === null || trim((string) $content) === '') {
        $errors['content'] = 'Content is required';
    }

    // Validate status (only draft or pending allowed on creation)
    if (!in_array($status, ['draft', 'pending'], true)) {
        $errors['status'] = 'Status must be draft or pending';
    }

    if (!empty($errors)) {
        jsonError('Validation failed', 'VALIDATION_ERROR', 400, $errors);
    }

    $sectionId = (int) $sectionId;
    $title     = trim((string) $title);
    $content   = trim((string) $content);

    // Validate section exists
    $stmt = $db->prepare('SELECT id FROM peoples_section WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $sectionId]);
    if (!$stmt->fetch()) {
        jsonError('Section not found', 'NOT_FOUND', 404, ['section_id' => 'Invalid section']);
    }

    // Validate person exists if provided
    if ($kodPersons !== null && $kodPersons !== '') {
        $kodPersons = (int) $kodPersons;
        $stmt = $db->prepare('SELECT Persons_id FROM persons WHERE Persons_id = :id LIMIT 1');
        $stmt->execute([':id' => $kodPersons]);
        if (!$stmt->fetch()) {
            jsonError('Person not found', 'NOT_FOUND', 404, ['KodPersons' => 'Invalid person']);
        }
    } else {
        $kodPersons = null;
    }

    // Sanitize HTML content
    $content = sanitizeHtml($content);

    // Convert text to cp1251 for storage
    $insertStmt = $db->prepare(
        'INSERT INTO user_submissions
            (user_id, section_id, KodPersons, title, content, epigraph, source_url, status, created_at, updated_at)
         VALUES
            (:user_id, :section_id, :kod_persons, :title, :content, :epigraph, :source_url, :status, NOW(), NOW())'
    );
    $insertStmt->execute([
        ':user_id'     => $userId,
        ':section_id'  => $sectionId,
        ':kod_persons' => $kodPersons,
        ':title'       => toDb($title),
        ':content'     => toDb($content),
        ':epigraph'    => $epigraph !== null ? toDb(trim((string) $epigraph)) : null,
        ':source_url'  => $sourceUrl !== null ? trim((string) $sourceUrl) : null,
        ':status'      => $status,
    ]);

    $newId = (int) $db->lastInsertId();

    // Fetch the created submission
    $stmt = $db->prepare(
        'SELECT s.*, p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
                ps.nameRus AS section_name
         FROM user_submissions s
         LEFT JOIN persons p ON p.Persons_id = s.KodPersons
         LEFT JOIN peoples_section ps ON ps.id = s.section_id
         WHERE s.id = :id'
    );
    $stmt->execute([':id' => $newId]);
    $submission = $stmt->fetch();

    jsonSuccess(fromDbArray($submission), 201);
}
