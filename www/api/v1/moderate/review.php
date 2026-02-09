<?php

declare(strict_types=1);

/**
 * POST /api/v1/moderate/review.php
 *
 * Review a submission: approve, reject, or request revision.
 *
 * On APPROVE: inserts content into the appropriate production table based
 * on section_id, updates submission status, awards reputation points.
 *
 * On REJECT: updates status and deducts reputation.
 *
 * On REQUEST_REVISION: updates status with moderator note.
 *
 * All actions are logged to users_moderation_log.
 *
 * Requires moderator role and CSRF validation.
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

$moderator = requireRole('moderator');
requireCsrf();

$db = getDb();
$moderatorId = (int) $moderator['id'];

// Get input
$submissionId = getInput('submission_id');
$action       = getInput('action');
$note         = getInput('note', '');

if ($submissionId === null || $submissionId === '') {
    jsonError('Submission ID is required', 'VALIDATION_ERROR', 400);
}
$submissionId = (int) $submissionId;

$validActions = ['approve', 'reject', 'request_revision'];
if (!in_array($action, $validActions, true)) {
    jsonError(
        'Action must be one of: approve, reject, request_revision',
        'VALIDATION_ERROR',
        400
    );
}

$note = trim((string) $note);

// Fetch the submission
$stmt = $db->prepare(
    'SELECT s.*, ps.table_name, ps.nameRus AS section_name
     FROM user_submissions s
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    jsonError('Submission not found', 'NOT_FOUND', 404);
}

// Only pending submissions can be reviewed (approve/reject)
// revision_requested can also be acted on by moderators
$reviewableStatuses = ['pending'];
if (!in_array($submission['status'], $reviewableStatuses, true)) {
    jsonError(
        'Only submissions with pending status can be reviewed',
        'STATUS_ERROR',
        400
    );
}

$submitterUserId = (int) $submission['user_id'];

// Begin transaction for data integrity
$db->beginTransaction();

try {
    if ($action === 'approve') {
        // -----------------------------------------------------------------
        // APPROVE: Insert into the correct production table
        // -----------------------------------------------------------------
        $sectionId = (int) $submission['section_id'];
        $kodPersons = $submission['KodPersons'] ? (int) $submission['KodPersons'] : null;
        $title   = $submission['title'];   // Already in cp1251
        $content = $submission['content'];  // Already in cp1251
        $epigraph = $submission['epigraph'];

        $publishedId = null;

        // Generate URL-safe article name from title
        $titleUtf8 = fromDb($title);
        $nameUrlArticle = generateUrlSlug($titleUtf8);

        // Route to correct production table based on section_id
        $publishedId = match ($sectionId) {
            2 => insertIntoHistories($db, $kodPersons, $content, $epigraph, $nameUrlArticle),
            3 => insertIntoPhoto($db, $kodPersons, $submission['photo_path']),
            4 => insertIntoNews($db, $kodPersons, $title, $content),
            5 => insertIntoForum($db, $kodPersons, $title, $content),
            7 => insertIntoSongs($db, $kodPersons, $title, $content),
            8 => insertIntoFacts($db, $kodPersons, $title, $content),
            19 => insertIntoPoetry($db, $kodPersons, $title, $content),
            default => insertGeneric($db, $submission),
        };

        // Update submission
        $updateStmt = $db->prepare(
            'UPDATE user_submissions
             SET status = :status, published_id = :pub_id, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'approved',
            ':pub_id' => $publishedId,
            ':mod_id' => $moderatorId,
            ':note'   => $note !== '' ? toDb($note) : null,
            ':id'     => $submissionId,
        ]);

        // Award reputation points based on section type
        $reputationPoints = match ($sectionId) {
            2  => 15,  // histories/bio
            4  => 10,  // news
            3  => 5,   // photo
            default => 5,
        };

        $repStmt = $db->prepare(
            'UPDATE users SET reputation = reputation + :points WHERE id = :uid'
        );
        $repStmt->execute([
            ':points' => $reputationPoints,
            ':uid'    => $submitterUserId,
        ]);

    } elseif ($action === 'reject') {
        // -----------------------------------------------------------------
        // REJECT
        // -----------------------------------------------------------------
        $updateStmt = $db->prepare(
            'UPDATE user_submissions
             SET status = :status, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'rejected',
            ':mod_id' => $moderatorId,
            ':note'   => $note !== '' ? toDb($note) : null,
            ':id'     => $submissionId,
        ]);

        // Deduct reputation
        $repStmt = $db->prepare(
            'UPDATE users SET reputation = GREATEST(0, reputation - 2) WHERE id = :uid'
        );
        $repStmt->execute([':uid' => $submitterUserId]);

    } else {
        // -----------------------------------------------------------------
        // REQUEST_REVISION
        // -----------------------------------------------------------------
        if ($note === '') {
            $db->rollBack();
            jsonError(
                'A note is required when requesting a revision',
                'VALIDATION_ERROR',
                400
            );
        }

        $updateStmt = $db->prepare(
            'UPDATE user_submissions
             SET status = :status, moderator_id = :mod_id,
                 moderator_note = :note, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $updateStmt->execute([
            ':status' => 'revision_requested',
            ':mod_id' => $moderatorId,
            ':note'   => toDb($note),
            ':id'     => $submissionId,
        ]);
    }

    // Log to moderation log
    $logStmt = $db->prepare(
        'INSERT INTO users_moderation_log (moderator_id, action, target_type, target_id, note, created_at)
         VALUES (:mod_id, :action, :target_type, :target_id, :note, NOW())'
    );
    $logStmt->execute([
        ':mod_id'      => $moderatorId,
        ':action'      => $action,
        ':target_type' => 'submission',
        ':target_id'   => $submissionId,
        ':note'        => $note !== '' ? toDb($note) : null,
    ]);

    $db->commit();

} catch (\Throwable $e) {
    $db->rollBack();
    jsonError('Review failed: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}

// Fetch and return updated submission
$stmt = $db->prepare(
    'SELECT s.*, p.FullNameRus AS person_name, ps.nameRus AS section_name,
            u.username AS submitter_username, u.display_name AS submitter_display_name,
            u.reputation AS submitter_reputation
     FROM user_submissions s
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     LEFT JOIN users u ON u.id = s.user_id
     WHERE s.id = :id'
);
$stmt->execute([':id' => $submissionId]);
$result = $stmt->fetch();

jsonSuccess(fromDbArray($result));


// ===================================================================
// Production table insert helpers
// ===================================================================

/**
 * Generate a URL-safe slug from a UTF-8 title.
 */
function generateUrlSlug(string $title): string
{
    // Transliterate Cyrillic
    $slug = transliterateCyrillic($title);
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');

    if ($slug === '') {
        $slug = 'article-' . time();
    }

    return $slug;
}

/**
 * Basic Cyrillic-to-Latin transliteration.
 */
function transliterateCyrillic(string $str): string
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

    return strtr($str, $map);
}

/**
 * Section 2: histories (biographies)
 */
function insertIntoHistories(PDO $db, ?int $kodPersons, ?string $content, ?string $epigraph, string $nameUrl): int
{
    $stmt = $db->prepare(
        'INSERT INTO histories (KodPersons, Content, Epigraph, NameURLArticle, date_pub)
         VALUES (:kod, :content, :epigraph, :name_url, NOW())'
    );
    $stmt->execute([
        ':kod'      => $kodPersons,
        ':content'  => $content,
        ':epigraph' => $epigraph,
        ':name_url' => $nameUrl,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 3: photo
 */
function insertIntoPhoto(PDO $db, ?int $kodPersons, ?string $photoPath): int
{
    // Move temp photo to production if possible
    $prodPath = $photoPath;
    if ($kodPersons !== null && $photoPath !== null) {
        try {
            $prodPath = moveToProduction($photoPath, $kodPersons);
        } catch (\RuntimeException $e) {
            // Keep temp path if move fails
            $prodPath = $photoPath;
        }
    }

    $stmt = $db->prepare(
        'INSERT INTO photo (KodPersons, NamePhoto, path_photo, date_registration)
         VALUES (:kod, :name_photo, :path_photo, NOW())'
    );
    $fileName = basename($prodPath ?? '');
    $pathDir  = $prodPath !== null ? dirname($prodPath) : '';

    $stmt->execute([
        ':kod'        => $kodPersons,
        ':name_photo' => $fileName,
        ':path_photo' => $pathDir,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 4: news
 */
function insertIntoNews(PDO $db, ?int $kodPersons, ?string $title, ?string $content): int
{
    $stmt = $db->prepare(
        "INSERT INTO news (KodPersons, title, content, approve, date_registration)
         VALUES (:kod, :title, :content, 'YES', NOW())"
    );
    $stmt->execute([
        ':kod'     => $kodPersons,
        ':title'   => $title,
        ':content' => $content,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 5: peoples_forum
 */
function insertIntoForum(PDO $db, ?int $kodPersons, ?string $title, ?string $content): int
{
    $stmt = $db->prepare(
        'INSERT INTO peoples_forum (KodPersons, Title, Message, date_registration)
         VALUES (:kod, :title, :message, NOW())'
    );
    $stmt->execute([
        ':kod'     => $kodPersons,
        ':title'   => $title,
        ':message' => $content,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 7: songs
 */
function insertIntoSongs(PDO $db, ?int $kodPersons, ?string $title, ?string $content): int
{
    $stmt = $db->prepare(
        'INSERT INTO songs (KodPersons, title, content, date_registration)
         VALUES (:kod, :title, :content, NOW())'
    );
    $stmt->execute([
        ':kod'     => $kodPersons,
        ':title'   => $title,
        ':content' => $content,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 8: Facts
 */
function insertIntoFacts(PDO $db, ?int $kodPersons, ?string $title, ?string $content): int
{
    $stmt = $db->prepare(
        'INSERT INTO Facts (KodPersons, title, content, date_registration)
         VALUES (:kod, :title, :content, NOW())'
    );
    $stmt->execute([
        ':kod'     => $kodPersons,
        ':title'   => $title,
        ':content' => $content,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Section 19: poetry
 */
function insertIntoPoetry(PDO $db, ?int $kodPersons, ?string $title, ?string $content): int
{
    $stmt = $db->prepare(
        'INSERT INTO poetry (KodPersons, title, content, date_registration)
         VALUES (:kod, :title, :content, NOW())'
    );
    $stmt->execute([
        ':kod'     => $kodPersons,
        ':title'   => $title,
        ':content' => $content,
    ]);
    return (int) $db->lastInsertId();
}

/**
 * Generic insert for sections without a specific handler.
 * Uses the table_name from peoples_section if available.
 */
function insertGeneric(PDO $db, array $submission): ?int
{
    $tableName = $submission['table_name'] ?? null;
    if (empty($tableName)) {
        return null;
    }

    try {
        $stmt = $db->prepare(
            "INSERT INTO `{$tableName}` (KodPersons, title, content, date_registration)
             VALUES (:kod, :title, :content, NOW())"
        );
        $stmt->execute([
            ':kod'     => $submission['KodPersons'],
            ':title'   => $submission['title'],
            ':content' => $submission['content'],
        ]);
        return (int) $db->lastInsertId();
    } catch (\PDOException $e) {
        // If the generic insert fails (columns don't match), return null
        return null;
    }
}
