<?php

declare(strict_types=1);

/**
 * GET /api/v1/persons/view.php?id=
 *
 * Public endpoint to view a person's profile with content counts by section.
 *
 * No authentication required.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

$id = getInput('id');
if ($id === null || $id === '') {
    jsonError('Person ID is required', 'VALIDATION_ERROR', 400);
}
$id = (int) $id;

$db = getDb();

// Fetch person from persons table
$stmt = $db->prepare(
    'SELECT Persons_id AS id, NameRus, SurNameRus, FullNameRus,
            NameEngl, SurNameEngl, FullNameEngl,
            DateIn, DateOut, AllUrlInSity, NamePhoto, Epigraph,
            famous_for, age, popularity
     FROM persons
     WHERE Persons_id = :id'
);
$stmt->execute([':id' => $id]);
$person = $stmt->fetch();

if (!$person) {
    jsonError('Person not found', 'NOT_FOUND', 404);
}

// Convert from cp1251 to UTF-8
$person = fromDbArray($person);

// Get content counts from peoples_section
// Each section has a table_name and id_name (the FK column name in that table)
$sectStmt = $db->prepare(
    'SELECT id, nameRus, nameEng, path, table_name, id_name
     FROM peoples_section
     WHERE working = 1'
);
$sectStmt->execute();
$sections = $sectStmt->fetchAll();

$contentCounts = [];
foreach ($sections as $section) {
    $tableName = $section['table_name'];
    $idName    = $section['id_name'] ?: 'KodPersons';

    if (empty($tableName)) {
        continue;
    }

    // Use a safe query to count content for this person in each section table
    // Table and column names come from our own DB, not user input
    try {
        $countStmt = $db->prepare(
            "SELECT COUNT(*) FROM `{$tableName}` WHERE `{$idName}` = :pid"
        );
        $countStmt->execute([':pid' => $id]);
        $count = (int) $countStmt->fetchColumn();

        if ($count > 0) {
            $contentCounts[] = [
                'section_id'   => (int) $section['id'],
                'section_name' => fromDb($section['nameRus']),
                'section_eng'  => fromDb($section['nameEng']),
                'path'         => fromDb($section['path']),
                'count'        => $count,
            ];
        }
    } catch (\PDOException $e) {
        // Table might not exist yet; skip silently
        continue;
    }
}

$person['content_counts'] = $contentCounts;

jsonSuccess($person);
