<?php

declare(strict_types=1);

/**
 * POST /api/v1/moderate/person-push.php
 *
 * Admin-only: push an approved person suggestion to the real `persons` table.
 * Also inserts biography into `histories`.
 *
 * This is the ONLY place where user-suggested data enters the production
 * `persons` table — and only after both moderator AND admin approval.
 *
 * Tables used:
 * - user_person_suggestions: read data, update status to 'published'
 * - persons: INSERT new person (approve='YES' — fully moderated at this point)
 * - histories: INSERT biography linked to new Persons_id
 * - photo: INSERT article photo linked to new Persons_id
 * - users: reputation update (+10 for published person)
 * - users_moderation_log: audit trail
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../../includes/upload.php';

requireMethod('POST');

$admin = requireRole('admin');
requireCsrf();

$db = getDb();
$adminId = (int) $admin['id'];

$suggestionId = getInput('suggestion_id');
if ($suggestionId === null || $suggestionId === '') {
    jsonError('Suggestion ID is required', 'VALIDATION_ERROR', 400);
}
$suggestionId = (int) $suggestionId;

$kodStructure = getInput('kod_structure');
if ($kodStructure === null || $kodStructure === '') {
    jsonError('Structure (раздел на сайте) is required', 'VALIDATION_ERROR', 400);
}
$kodStructure = (int) $kodStructure;

// Look up the structure
$structStmt = $db->prepare('SELECT Structure_id, URL, path FROM structure WHERE Structure_id = :id');
$structStmt->execute([':id' => $kodStructure]);
$structure = $structStmt->fetch();

if (!$structure) {
    jsonError('Structure not found', 'NOT_FOUND', 404);
}
$structure = fromDbArray($structure);

// Fetch the approved suggestion
$stmt = $db->prepare('SELECT * FROM user_person_suggestions WHERE id = :id');
$stmt->execute([':id' => $suggestionId]);
$suggestion = $stmt->fetch();

if (!$suggestion) {
    jsonError('Person suggestion not found', 'NOT_FOUND', 404);
}

if ($suggestion['status'] !== 'approved') {
    jsonError('Only approved suggestions can be pushed to persons table', 'STATUS_ERROR', 400);
}

if (!empty($suggestion['published_person_id'])) {
    jsonError('This suggestion has already been published', 'ALREADY_PUBLISHED', 400);
}

$suggestion = fromDbArray($suggestion);
$submitterUserId = (int) $suggestion['user_id'];

$db->beginTransaction();

try {
    // Build person fields
    $nameRus     = $suggestion['NameRus'] ?? '';
    $surNameRus  = $suggestion['SurNameRus'] ?? '';
    $fullNameRus = $nameRus . ' ' . $surNameRus;
    $nameEngl     = $suggestion['NameEngl'] ?? '';
    $surNameEngl  = $suggestion['SurNameEngl'] ?? '';
    $fullNameEngl = ($nameEngl !== '' && $surNameEngl !== '')
        ? $nameEngl . ' ' . $surNameEngl
        : '';

    $biography = $suggestion['biography'] ?? '';
    // title = Звание (person's rank/occupation) → persons.Epigraph
    $zvanie    = $suggestion['title'] ?? '';
    // epigraph = article description → histories.Epigraph
    $articleEpigraph = !empty($suggestion['epigraph'])
        ? $suggestion['epigraph']
        : mb_substr($biography, 0, 200, 'UTF-8');
    // Person epigraph = zvanie, fall back to article epigraph
    $personEpigraph = !empty($zvanie) ? $zvanie : $articleEpigraph;

    // Person photo filename (just the basename, stored in temp)
    $personPhoto = '';
    if (!empty($suggestion['person_photo_path'])) {
        $personPhoto = basename($suggestion['person_photo_path']);
    }

    // Build AllUrlInSity and path from structure + person name
    $structureUrl = trim($structure['URL'] ?? '', '/');  // e.g. "art/cinema/producer"

    // Use custom slug if admin provided one (from the preview step),
    // otherwise prefer English name, fall back to transliterated Russian
    $customSlug = $suggestion['_custom_slug'] ?? '';
    if (empty($customSlug)) {
        // Check if custom_slug was passed in the request
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $customSlug = trim($rawInput['custom_slug'] ?? '');
    }

    if (!empty($customSlug)) {
        $personSlug = strtolower($customSlug);
        $personSlug = preg_replace('/[^a-z0-9\-]+/', '-', $personSlug) ?? $personSlug;
        $personSlug = trim($personSlug, '-');
    } elseif ($nameEngl !== '' && $surNameEngl !== '') {
        $personSlug = strtolower($nameEngl . ' ' . $surNameEngl);
        $personSlug = preg_replace('/[^a-z0-9]+/', '-', $personSlug) ?? $personSlug;
        $personSlug = trim($personSlug, '-');
    } else {
        $personSlug = transliteratePersonName($nameRus, $surNameRus);
    }

    $allUrlInSity = 'https://www.peoples.ru/' . $structureUrl . '/' . $personSlug . '/';
    $personPath = $personSlug . '_' . str_replace('/', '_', $structureUrl);

    // Verify uniqueness
    $checkStmt = $db->prepare("SELECT COUNT(*) FROM persons WHERE AllUrlInSity = :url");
    $checkStmt->execute([':url' => toDb($allUrlInSity)]);
    if ((int) $checkStmt->fetchColumn() > 0) {
        jsonError('URL ' . $allUrlInSity . ' already exists. Use the preview to pick a unique slug.', 'DUPLICATE_URL', 400);
    }

    // INSERT into persons table with approve='YES' (fully moderated by this point)
    $personStmt = $db->prepare(
        "INSERT INTO persons (
            NameRus, SurNameRus, FullNameRus,
            NameEngl, SurNameEngl, FullNameEngl,
            Epigraph, NamePhoto, DateIn, DateOut, gender,
            TownIn, cc2born, cc2dead, cc2,
            KodStructure, AllUrlInSity, path,
            approve
        ) VALUES (
            :nameRus, :surNameRus, :fullNameRus,
            :nameEngl, :surNameEngl, :fullNameEngl,
            :epigraph, :namePhoto, :dateIn, :dateOut, :gender,
            :townIn, :cc2born, :cc2dead, :cc2,
            :kodStructure, :allUrl, :path,
            'YES'
        )"
    );
    $personStmt->execute([
        ':nameRus'      => toDb($nameRus),
        ':surNameRus'   => toDb($surNameRus),
        ':fullNameRus'  => toDb($fullNameRus),
        ':nameEngl'     => toDb($nameEngl),
        ':surNameEngl'  => toDb($surNameEngl),
        ':fullNameEngl' => toDb($fullNameEngl),
        ':epigraph'     => toDb($personEpigraph),
        ':namePhoto'    => !empty($personPhoto) ? toDb($personPhoto) : null,
        ':dateIn'       => !empty($suggestion['DateIn']) ? $suggestion['DateIn'] : null,
        ':dateOut'      => !empty($suggestion['DateOut']) ? $suggestion['DateOut'] : null,
        ':gender'       => !empty($suggestion['gender']) ? $suggestion['gender'] : null,
        ':townIn'       => !empty($suggestion['TownIn']) ? toDb($suggestion['TownIn']) : null,
        ':cc2born'      => !empty($suggestion['cc2born']) ? $suggestion['cc2born'] : null,
        ':cc2dead'      => !empty($suggestion['cc2dead']) ? $suggestion['cc2dead'] : null,
        ':cc2'          => !empty($suggestion['cc2']) ? $suggestion['cc2'] : null,
        ':kodStructure' => $kodStructure,
        ':allUrl'       => toDb($allUrlInSity),
        ':path'         => toDb($personPath),
    ]);

    $newPersonId = (int) $db->lastInsertId();

    // Move person photo from temp to production
    if (!empty($suggestion['person_photo_path'])) {
        try {
            $prodPhotoPath = moveToProduction($suggestion['person_photo_path'], $newPersonId);
            $movedFilename = basename($prodPhotoPath);
            // Update persons.NamePhoto with the production filename
            $photoUpdateStmt = $db->prepare(
                'UPDATE persons SET NamePhoto = :photo WHERE Persons_id = :id'
            );
            $photoUpdateStmt->execute([
                ':photo' => toDb($movedFilename),
                ':id'    => $newPersonId,
            ]);
        } catch (\RuntimeException $e) {
            // Photo move failed — not critical, person is still created
        }
    }

    // Move article photo and insert into photo table
    if (!empty($suggestion['photo_path'])) {
        $articleProdPath = $suggestion['photo_path'];
        try {
            $articleProdPath = moveToProduction($suggestion['photo_path'], $newPersonId);
        } catch (\RuntimeException $e) {
            // Keep temp path if move fails
        }
        $photoStmt = $db->prepare(
            'INSERT INTO photo (KodPersons, NamePhoto, path_photo, date_registration)
             VALUES (:kod, :name_photo, :path_photo, NOW())'
        );
        $photoStmt->execute([
            ':kod'        => $newPersonId,
            ':name_photo' => basename($articleProdPath),
            ':path_photo' => dirname($articleProdPath),
        ]);
    }

    // Convert markdown biography to HTML before saving
    $biographyHtml = markdownToHtml($biography);

    // INSERT biography into histories
    $bioStmt = $db->prepare(
        'INSERT INTO histories (KodPersons, Content, Epigraph, date_pub)
         VALUES (:kod, :content, :epigraph, NOW())'
    );
    $bioStmt->execute([
        ':kod'      => $newPersonId,
        ':content'  => toDb($biographyHtml),
        ':epigraph' => toDb($articleEpigraph),
    ]);

    $historiesId = (int) $db->lastInsertId();

    // Update suggestion: mark as published
    $updateStmt = $db->prepare(
        'UPDATE user_person_suggestions
         SET status = :status, published_person_id = :pid,
             published_at = NOW(), updated_at = NOW()
         WHERE id = :id'
    );
    $updateStmt->execute([
        ':status' => 'published',
        ':pid'    => $newPersonId,
        ':id'     => $suggestionId,
    ]);

    // Award extra reputation for published person
    $repStmt = $db->prepare(
        'UPDATE users SET reputation = reputation + 10 WHERE id = :uid'
    );
    $repStmt->execute([':uid' => $submitterUserId]);

    // Log to moderation log
    $logStmt = $db->prepare(
        'INSERT INTO users_moderation_log (moderator_id, action, target_type, target_id, note, created_at)
         VALUES (:mod_id, :action, :target_type, :target_id, :note, NOW())'
    );
    $logStmt->execute([
        ':mod_id'      => $adminId,
        ':action'      => 'approve',
        ':target_type' => 'person_suggestion',
        ':target_id'   => $suggestionId,
        ':note'        => toDb('Персона создана: Persons_id=' . $newPersonId),
    ]);

    $db->commit();

} catch (\Throwable $e) {
    $db->rollBack();
    jsonError('Failed to create person: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}

jsonSuccess([
    'person_id'     => $newPersonId,
    'histories_id'  => $historiesId,
    'suggestion_id' => $suggestionId,
    'all_url'       => $allUrlInSity,
]);

/**
 * Transliterate person name (Cyrillic → Latin) and build a URL slug.
 * "Табита" "Джексон" → "tabita-dzhekson"
 */
function transliteratePersonName(string $name, string $surname): string
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

    $full = trim($name . ' ' . $surname);
    $slug = strtr($full, $map);
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
    $slug = trim($slug, '-');

    return $slug ?: 'person-' . time();
}

/**
 * Convert simple markdown to HTML.
 *
 * Supported syntax:
 * - # Heading 1, ## Heading 2, ### Heading 3
 * - > Blockquote
 * - **bold**, *italic*
 * - Blank-line-separated paragraphs → <p>
 * - Line breaks within a paragraph → <br>
 */
function markdownToHtml(string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    $text = trim($text);

    if ($text === '') {
        return '';
    }

    // Split into blocks by blank lines
    $blocks = preg_split('/\n{2,}/', $text);
    $html = '';

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') {
            continue;
        }

        // Headings
        if (preg_match('/^(#{1,3})\s+(.+)$/', $block, $m)) {
            $level = strlen($m[1]);
            $content = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
            $content = applyInlineFormatting($content);
            $html .= "<h{$level}>{$content}</h{$level}>\n";
            continue;
        }

        // Blockquote (lines starting with >)
        if (preg_match('/^>\s/', $block)) {
            $lines = explode("\n", $block);
            $quoteLines = [];
            foreach ($lines as $line) {
                $quoteLines[] = htmlspecialchars(preg_replace('/^>\s?/', '', $line), ENT_QUOTES, 'UTF-8');
            }
            $quoteContent = applyInlineFormatting(implode('<br>', $quoteLines));
            $html .= "<blockquote>{$quoteContent}</blockquote>\n";
            continue;
        }

        // Regular paragraph
        $escaped = htmlspecialchars($block, ENT_QUOTES, 'UTF-8');
        $escaped = applyInlineFormatting($escaped);
        // Preserve single line breaks as <br>
        $escaped = str_replace("\n", "<br>\n", $escaped);
        $html .= "<p>{$escaped}</p>\n";
    }

    return $html;
}

/**
 * Apply inline markdown formatting: **bold** and *italic*.
 */
function applyInlineFormatting(string $text): string
{
    // **bold**
    $text = preg_replace('/\*\*(.+?)\*\*/', '<b>$1</b>', $text) ?? $text;
    // *italic*
    $text = preg_replace('/\*(.+?)\*/', '<i>$1</i>', $text) ?? $text;
    return $text;
}
