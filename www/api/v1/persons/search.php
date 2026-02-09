<?php

declare(strict_types=1);

/**
 * GET /api/v1/persons/search.php?q=&limit=10
 *
 * Public endpoint for person autocomplete/search.
 * Searches the MEMORY table first for speed, falls back to persons table.
 *
 * No authentication required.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$query = trim((string) getInput('q', ''));
$limit = min(50, max(1, (int) getInput('limit', 10)));

if (mb_strlen($query, 'UTF-8') < 2) {
    jsonError('Search query must be at least 2 characters', 'VALIDATION_ERROR', 400);
}

$db = getDb();

// Convert query to cp1251 for database search
$queryDb = toDb($query);
$likeParam = '%' . $queryDb . '%';

$results = [];

// First try the MEMORY table (fast search)
try {
    $stmt = $db->prepare(
        'SELECT sp.KodPersons AS id, sp.title, sp.Epigraph, sp.search,
                sp.path, sp.NamePhoto, sp.pop,
                p.FullNameRus, p.FullNameEngl, p.DateIn, p.DateOut,
                p.famous_for, p.AllUrlInSity
         FROM peoplesru_search_person sp
         LEFT JOIN persons p ON p.Persons_id = sp.KodPersons
         WHERE sp.search LIKE :q1 OR sp.title LIKE :q2
         ORDER BY sp.pop DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':q1', $likeParam);
    $stmt->bindValue(':q2', $likeParam);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
} catch (\PDOException $e) {
    // MEMORY table might be empty or unavailable, fall back to persons
    $results = [];
}

// If MEMORY table returned no results, search persons directly
if (empty($results)) {
    $stmt = $db->prepare(
        'SELECT Persons_id AS id, FullNameRus, FullNameEngl,
                DateIn, DateOut, NamePhoto, famous_for, AllUrlInSity,
                Epigraph, popularity AS pop
         FROM persons
         WHERE FullNameRus LIKE :q1 OR SurNameRus LIKE :q2 OR NameEngl LIKE :q3
         ORDER BY popularity DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':q1', $likeParam);
    $stmt->bindValue(':q2', $likeParam);
    $stmt->bindValue(':q3', $likeParam);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $results = $stmt->fetchAll();
}

// Format output
$output = [];
foreach ($results as $row) {
    $name = fromDb($row['FullNameRus'] ?? $row['title'] ?? '');
    $nameEng = fromDb($row['FullNameEngl'] ?? '');
    $photo = fromDb($row['NamePhoto'] ?? '');
    $famousFor = fromDb($row['famous_for'] ?? '');
    $path = fromDb($row['AllUrlInSity'] ?? $row['path'] ?? '');
    $epigraph = fromDb($row['Epigraph'] ?? '');

    $output[] = [
        'id'         => (int) $row['id'],
        'name'       => $name,
        'name_eng'   => $nameEng,
        'dates'      => [
            'birth' => fromDb($row['DateIn'] ?? ''),
            'death' => fromDb($row['DateOut'] ?? ''),
        ],
        'photo'      => $photo,
        'famous_for' => $famousFor,
        'path'       => $path,
        'epigraph'   => $epigraph,
    ];
}

jsonSuccess($output);
