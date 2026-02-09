<?php

declare(strict_types=1);

/**
 * POST /api/v1/moderate/person-check-slug.php
 *
 * Admin-only: preview the URL slug for a person suggestion before push.
 * Checks for duplicate AllUrlInSity in the persons table.
 *
 * Input: suggestion_id, kod_structure, custom_slug (optional)
 * Output: slug, full_url, conflicts (existing persons with same/similar slug)
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$admin = requireRole('admin');
requireCsrf();

$db = getDb();

$suggestionId = getInput('suggestion_id');
if ($suggestionId === null || $suggestionId === '') {
    jsonError('Suggestion ID is required', 'VALIDATION_ERROR', 400);
}
$suggestionId = (int) $suggestionId;

$kodStructure = getInput('kod_structure');
if ($kodStructure === null || $kodStructure === '') {
    jsonError('Structure is required', 'VALIDATION_ERROR', 400);
}
$kodStructure = (int) $kodStructure;

$customSlug = getInput('custom_slug');

// Look up structure
$structStmt = $db->prepare('SELECT Structure_id, URL, NameURL FROM structure WHERE Structure_id = :id');
$structStmt->execute([':id' => $kodStructure]);
$structure = $structStmt->fetch();

if (!$structure) {
    jsonError('Structure not found', 'NOT_FOUND', 404);
}
$structure = fromDbArray($structure);

// Fetch suggestion
$stmt = $db->prepare('SELECT * FROM user_person_suggestions WHERE id = :id');
$stmt->execute([':id' => $suggestionId]);
$suggestion = $stmt->fetch();

if (!$suggestion) {
    jsonError('Person suggestion not found', 'NOT_FOUND', 404);
}
$suggestion = fromDbArray($suggestion);

// Build slug
$structureUrl = trim($structure['URL'] ?? '', '/');

if (!empty($customSlug)) {
    // Admin provided a custom slug — sanitize it
    $slug = strtolower(trim($customSlug));
    $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');
} else {
    // Auto-generate: prefer English name, fall back to transliterated Russian
    $nameEngl = $suggestion['NameEngl'] ?? '';
    $surNameEngl = $suggestion['SurNameEngl'] ?? '';

    if ($nameEngl !== '' && $surNameEngl !== '') {
        $slug = strtolower($nameEngl . ' ' . $surNameEngl);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');
    } else {
        // Transliterate Russian
        $slug = transliterateSlug(
            ($suggestion['NameRus'] ?? '') . ' ' . ($suggestion['SurNameRus'] ?? '')
        );
    }
}

if ($slug === '') {
    $slug = 'person-' . $suggestionId;
}

$fullUrl = 'https://www.peoples.ru/' . $structureUrl . '/' . $slug . '/';

// Check for conflicts — exact match and similar slugs
$likePattern = '%/' . $slug . '%';
$conflictStmt = $db->prepare(
    "SELECT Persons_id, FullNameRus, FullNameEngl, AllUrlInSity, Epigraph, approve
     FROM persons
     WHERE AllUrlInSity LIKE :pattern
     ORDER BY Persons_id
     LIMIT 20"
);
$conflictStmt->execute([':pattern' => toDb($likePattern)]);
$conflicts = fromDbRows($conflictStmt->fetchAll());

$exactMatch = false;
foreach ($conflicts as $c) {
    if (fromDb($c['AllUrlInSity'] ?? '') === $fullUrl || $c['AllUrlInSity'] === $fullUrl) {
        $exactMatch = true;
        break;
    }
}

// If exact match, suggest alternatives
$suggestedSlug = null;
if ($exactMatch) {
    for ($i = 2; $i <= 20; $i++) {
        $altSlug = $slug . '-' . $i;
        $altUrl = 'https://www.peoples.ru/' . $structureUrl . '/' . $altSlug . '/';
        $checkStmt = $db->prepare(
            "SELECT COUNT(*) FROM persons WHERE AllUrlInSity = :url"
        );
        $checkStmt->execute([':url' => toDb($altUrl)]);
        if ((int) $checkStmt->fetchColumn() === 0) {
            $suggestedSlug = $altSlug;
            break;
        }
    }
}

jsonSuccess([
    'slug'           => $slug,
    'full_url'       => $fullUrl,
    'structure_name' => trim($structure['NameURL'] ?? '', ' >'),
    'exact_match'    => $exactMatch,
    'suggested_slug' => $suggestedSlug,
    'conflicts'      => array_map(function ($c) {
        return [
            'id'        => (int) $c['Persons_id'],
            'name_rus'  => $c['FullNameRus'] ?? '',
            'name_eng'  => $c['FullNameEngl'] ?? '',
            'url'       => $c['AllUrlInSity'] ?? '',
            'epigraph'  => $c['Epigraph'] ?? '',
            'approved'  => ($c['approve'] ?? '') === 'YES',
        ];
    }, $conflicts),
]);

/**
 * Transliterate Cyrillic to Latin and build URL slug.
 */
function transliterateSlug(string $str): string
{
    $map = [
        'а' => 'a',  'б' => 'b',  'в' => 'v',  'г' => 'g',  'д' => 'd',
        'е' => 'e',  'ё' => 'yo', 'ж' => 'zh', 'з' => 'z',  'и' => 'i',
        'й' => 'y',  'к' => 'k',  'л' => 'l',  'м' => 'm',  'н' => 'n',
        'о' => 'o',  'п' => 'p',  'р' => 'r',  'с' => 's',  'т' => 't',
        'у' => 'u',  'ф' => 'f',  'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
        'ш' => 'sh', 'щ' => 'shch', 'ъ' => '',  'ы' => 'y',  'ь' => '',
        'э' => 'e',  'ю' => 'yu', 'я' => 'ya',
        'А' => 'A',  'Б' => 'B',  'В' => 'V',  'Г' => 'G',  'Д' => 'D',
        'Е' => 'E',  'Ё' => 'Yo', 'Ж' => 'Zh', 'З' => 'Z',  'И' => 'I',
        'Й' => 'Y',  'К' => 'K',  'Л' => 'L',  'М' => 'M',  'Н' => 'N',
        'О' => 'O',  'П' => 'P',  'Р' => 'R',  'С' => 'S',  'Т' => 'T',
        'У' => 'U',  'Ф' => 'F',  'Х' => 'Kh', 'Ц' => 'Ts', 'Ч' => 'Ch',
        'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '',  'Ы' => 'Y',  'Ь' => '',
        'Э' => 'E',  'Ю' => 'Yu', 'Я' => 'Ya',
    ];

    $slug = strtr($str, $map);
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    return trim($slug, '-');
}
