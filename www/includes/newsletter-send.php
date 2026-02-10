<?php

declare(strict_types=1);

/**
 * Newsletter cron script — generates and sends digest emails.
 *
 * Run daily via cron:
 *   0 8 * * * /usr/bin/php /usr/local/www/in.peoples.ru/www/includes/newsletter-send.php
 *
 * Logic:
 *   - Daily subscribers: last_sent_at is NULL or older than 24h
 *   - Weekly subscribers: last_sent_at is NULL or older than 7 days, AND today is Monday
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/encoding.php';
require_once __DIR__ . '/mail.php';

$db = getDb();
$env = parseEnvFile();
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

// --- Section definitions ---
$sectionDefs = [
    4  => [
        'name'       => 'Новости',
        'query'      => "SELECT title, description, path FROM news WHERE approve='YES' AND date > :since ORDER BY date DESC LIMIT 5",
        'renderer'   => 'renderNews',
    ],
    2  => [
        'name'       => 'Истории',
        'query'      => "SELECT h.Epigraph, p.FullNameRus, p.AllUrlInSity FROM histories h JOIN persons p ON p.Persons_id=h.KodPersons WHERE h.date_pub > :since ORDER BY h.date_pub DESC LIMIT 5",
        'renderer'   => 'renderHistories',
    ],
    8  => [
        'name'       => 'Мир фактов',
        'query'      => "SELECT Facts_txt AS content, Title AS title FROM Facts WHERE date_registration > :since AND del='N' ORDER BY date_registration DESC LIMIT 5",
        'renderer'   => 'renderTitleDesc',
    ],
    7  => [
        'name'       => 'Песни',
        'query'      => "SELECT s.content, p.FullNameRus FROM songs s JOIN persons p ON p.Persons_id=s.KodPersons WHERE s.date_registration > :since ORDER BY s.date_registration DESC LIMIT 5",
        'renderer'   => 'renderWithPerson',
    ],
    19 => [
        'name'       => 'Стихи',
        'query'      => "SELECT s.content, p.FullNameRus FROM poetry s JOIN persons p ON p.Persons_id=s.KodPersons WHERE s.date_registration > :since ORDER BY s.date_registration DESC LIMIT 5",
        'renderer'   => 'renderWithPerson',
    ],
    29 => [
        'name'       => 'Цитаты',
        'query'      => "SELECT aphorism AS content FROM aphorism WHERE date_registration > :since AND deleted='N' ORDER BY date_registration DESC LIMIT 5",
        'renderer'   => 'renderContent',
    ],
    31 => [
        'name'       => 'Анекдоты',
        'query'      => "SELECT a.Anek_txt AS content, p.FullNameRus FROM Anekdot a JOIN persons p ON p.Persons_id=a.KodPersons WHERE a.date_registration > :since AND a.del='N' AND a.KodPersons > 0 ORDER BY a.date_registration DESC LIMIT 5",
        'renderer'   => 'renderWithPerson',
    ],
    13 => [
        'name'       => 'Интересное',
        'query'      => "SELECT title, description FROM interesting WHERE date_registration > :since ORDER BY date_registration DESC LIMIT 5",
        'renderer'   => 'renderTitleDesc',
    ],
];

// --- Test mode: php newsletter-send.php --test ---
$testMode = in_array('--test', $argv ?? [], true);

// --- Select subscribers to send to ---
$dayOfWeek = (int) date('N'); // 1=Monday
$now = date('Y-m-d H:i:s');

if ($testMode) {
    // Test mode: send to all confirmed subscribers, ignore timing
    $sql = "
        SELECT ns.id, ns.email, ns.frequency, ns.unsubscribe_token, ns.last_sent_at
        FROM user_newsletter_subscribers ns
        WHERE ns.status = 'confirmed'
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute();
} else {
    $sql = "
        SELECT ns.id, ns.email, ns.frequency, ns.unsubscribe_token, ns.last_sent_at
        FROM user_newsletter_subscribers ns
        WHERE ns.status = 'confirmed'
          AND (
              (ns.frequency = 'daily'  AND (ns.last_sent_at IS NULL OR ns.last_sent_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)))
              OR
              (ns.frequency = 'weekly' AND (ns.last_sent_at IS NULL OR ns.last_sent_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) AND :dow = 1)
          )
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([':dow' => $dayOfWeek]);
}
$subscribers = $stmt->fetchAll();

$sent = 0;
$errors = 0;

foreach ($subscribers as $sub) {
    $subscriberId = (int) $sub['id'];
    $email = fromDb($sub['email']);
    $frequency = $sub['frequency'];
    $unsubToken = $sub['unsubscribe_token'];

    // Determine "since" date for content lookup
    if ($testMode) {
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    } elseif ($sub['last_sent_at']) {
        $since = $sub['last_sent_at'];
    } else {
        $since = ($frequency === 'daily')
            ? date('Y-m-d H:i:s', strtotime('-1 day'))
            : date('Y-m-d H:i:s', strtotime('-7 days'));
    }

    // Fetch subscriber's section subscriptions
    $secStmt = $db->prepare('SELECT section_id FROM user_newsletter_sections WHERE subscriber_id = :sid');
    $secStmt->execute([':sid' => $subscriberId]);
    $sectionIds = $secStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($sectionIds)) {
        continue;
    }

    // Build email content for each section
    $htmlSections = '';
    $hasContent = false;

    foreach ($sectionIds as $secId) {
        $secId = (int) $secId;
        if (!isset($sectionDefs[$secId])) {
            continue;
        }

        $def = $sectionDefs[$secId];

        try {
            $secStmt = $db->prepare($def['query']);
            $secStmt->execute([':since' => $since]);
            $items = $secStmt->fetchAll();
        } catch (PDOException $e) {
            // Log error but continue with other sections
            error_log("Newsletter: failed to query section {$secId}: " . $e->getMessage());
            continue;
        }

        if (empty($items)) {
            continue;
        }

        $hasContent = true;
        $rendererFn = $def['renderer'];
        $itemsHtml = $rendererFn($items);

        $sectionName = htmlspecialchars($def['name'], ENT_QUOTES, 'UTF-8');
        $htmlSections .= "<h2 style=\"color:#333;font-size:18px;border-bottom:1px solid #eee;padding-bottom:8px;margin-top:24px;\">{$sectionName}</h2>\n";
        $htmlSections .= $itemsHtml;
    }

    if (!$hasContent) {
        // No new content for this subscriber, skip
        continue;
    }

    // Build full email
    $unsubUrl = $siteUrl . '/api/v1/newsletter/unsubscribe.php?token=' . urlencode($unsubToken);
    $freqLabel = ($frequency === 'daily') ? 'ежедневный' : 'еженедельный';

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color: #333;">
    <h1 style="color: #d92228; font-size: 24px; margin-bottom: 4px;">Peoples.ru — Дайджест</h1>
    <p style="color: #888; font-size: 13px; margin-top: 0;">Ваш {$freqLabel} дайджест с peoples.ru</p>

    {$htmlSections}

    <hr style="margin-top: 30px; border: none; border-top: 1px solid #ddd;">
    <p style="font-size: 12px; color: #888; line-height: 1.5;">
        Вы получаете это письмо, потому что подписались на рассылку peoples.ru<br>
        <a href="{$siteUrl}/newsletter.php?token={$unsubToken}" style="color: #888;">Настроить подписку</a> &nbsp;|&nbsp;
        <a href="{$unsubUrl}" style="color: #888;">Отписаться</a>
    </p>
</body>
</html>
HTML;

    $ruMonths = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
    $ruDate = (int)date('j') . ' ' . $ruMonths[(int)date('n')] . ' ' . date('Y') . ' года';
    $subject = "Peoples.ru — Дайджест за {$ruDate}";

    if (sendMail($email, $subject, $html)) {
        $sent++;
    } else {
        $errors++;
        error_log("Newsletter: failed to send to {$email}");
    }

    // Update last_sent_at (skip in test mode)
    if (!$testMode) {
        $updStmt = $db->prepare('UPDATE user_newsletter_subscribers SET last_sent_at = NOW() WHERE id = :id');
        $updStmt->execute([':id' => $subscriberId]);
    }
}

echo "Newsletter sent: {$sent} emails, {$errors} errors\n";

// ---------------------------------------------------------------------------
// Renderers
// ---------------------------------------------------------------------------

/**
 * Render news items (title + description with link).
 */
function renderNews(array $items): string
{
    $html = '<ul style="padding-left: 20px; margin: 12px 0;">';
    foreach ($items as $item) {
        $title = htmlspecialchars(fromDb($item['title'] ?? '') ?? '', ENT_QUOTES, 'UTF-8');
        $desc  = htmlspecialchars(mb_substr(fromDb($item['description'] ?? '') ?? '', 0, 120, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $path  = htmlspecialchars($item['path'] ?? '', ENT_QUOTES, 'UTF-8');
        $url   = $path ? "https://www.peoples.ru/news/{$path}" : '#';
        $html .= "<li style=\"margin-bottom:8px;\"><a href=\"{$url}\" style=\"color:#d92228;text-decoration:none;\">{$title}</a>";
        if ($desc && $desc !== $title) {
            $html .= " — <span style=\"color:#666;\">{$desc}</span>";
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Render histories (epigraph + person name).
 */
function renderHistories(array $items): string
{
    $html = '<ul style="padding-left: 20px; margin: 12px 0;">';
    foreach ($items as $item) {
        $epigraph = htmlspecialchars(mb_substr(fromDb($item['Epigraph'] ?? '') ?? '', 0, 150, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $person   = htmlspecialchars(fromDb($item['FullNameRus'] ?? '') ?? '', ENT_QUOTES, 'UTF-8');
        $url      = htmlspecialchars($item['AllUrlInSity'] ?? '', ENT_QUOTES, 'UTF-8');
        if ($url && str_starts_with($url, 'http')) {
            $link = $url;
        } elseif ($url) {
            $link = "https://peoples.ru{$url}";
        } else {
            $link = '#';
        }
        $html .= "<li style=\"margin-bottom:8px;\">";
        $html .= "<a href=\"{$link}\" style=\"color:#d92228;text-decoration:none;\">{$person}</a>";
        if ($epigraph) {
            $html .= " — <span style=\"color:#666;font-style:italic;\">{$epigraph}...</span>";
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}

/**
 * Render items with just a content field (facts, aphorisms, jokes).
 */
function renderContent(array $items): string
{
    $html = '';
    foreach ($items as $item) {
        $content = htmlspecialchars(mb_substr(fromDb($item['content'] ?? '') ?? '', 0, 200, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $html .= "<p style=\"margin:8px 0;padding:10px;background:#f9f9f9;border-left:3px solid #d92228;font-size:14px;\">{$content}...</p>";
    }
    return $html;
}

/**
 * Render items with content + person name (songs, poetry).
 */
function renderWithPerson(array $items): string
{
    $html = '';
    foreach ($items as $item) {
        $content = htmlspecialchars(mb_substr(fromDb($item['content'] ?? '') ?? '', 0, 150, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $person  = htmlspecialchars(fromDb($item['FullNameRus'] ?? '') ?? '', ENT_QUOTES, 'UTF-8');
        $html .= "<p style=\"margin:8px 0;padding:10px;background:#f9f9f9;border-left:3px solid #d92228;font-size:14px;\">";
        $html .= "<strong>{$person}</strong><br>{$content}...";
        $html .= '</p>';
    }
    return $html;
}

/**
 * Render items with title + description (interesting).
 */
function renderTitleDesc(array $items): string
{
    $html = '<ul style="padding-left: 20px; margin: 12px 0;">';
    foreach ($items as $item) {
        $title = htmlspecialchars(fromDb($item['title'] ?? '') ?? '', ENT_QUOTES, 'UTF-8');
        $desc  = htmlspecialchars(mb_substr(fromDb($item['description'] ?? '') ?? '', 0, 120, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $html .= "<li style=\"margin-bottom:8px;\"><strong>{$title}</strong>";
        if ($desc) {
            $html .= " — <span style=\"color:#666;\">{$desc}...</span>";
        }
        $html .= '</li>';
    }
    $html .= '</ul>';
    return $html;
}
