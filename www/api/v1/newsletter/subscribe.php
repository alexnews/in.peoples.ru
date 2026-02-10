<?php

declare(strict_types=1);

/**
 * POST /api/v1/newsletter/subscribe.php
 *
 * Public endpoint — subscribes an email to the newsletter.
 * No auth required, but rate-limited by IP.
 *
 * Input (JSON or POST):
 *   - email: string
 *   - sections: array of section IDs
 *   - frequency: 'daily' | 'weekly'
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token — 'ok_' + timestamp, 1s-10min old)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (10 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/newsletter_rate_' . md5($ip);

if (is_file($rateLimitFile)) {
    $rateData = json_decode(file_get_contents($rateLimitFile), true);
    if (is_array($rateData) && ($rateData['time'] ?? 0) > time() - 3600) {
        if (($rateData['count'] ?? 0) >= 10) {
            jsonError('Слишком много запросов. Попробуйте позже.', 'RATE_LIMIT', 429);
        }
        $rateData['count'] = ($rateData['count'] ?? 0) + 1;
    } else {
        $rateData = ['time' => time(), 'count' => 1];
    }
} else {
    $rateData = ['time' => time(), 'count' => 1];
}
file_put_contents($rateLimitFile, json_encode($rateData));

// --- Check for manage token (skip bot protection if valid) ---
$manageToken = trim((string) getInput('manage_token', ''));
$isManage = false;
if ($manageToken !== '' && preg_match('/^[0-9a-f]{64}$/', $manageToken)) {
    $db = getDb();
    $mStmt = $db->prepare('SELECT id FROM user_newsletter_subscribers WHERE unsubscribe_token = :token AND status != :unsub');
    $mStmt->execute([':token' => $manageToken, ':unsub' => 'unsubscribed']);
    $isManage = (bool) $mStmt->fetch();
}

// --- Bot protection: honeypot + time-based token (skip for valid manage token) ---
if (!$isManage) {
    $honeypot = trim((string) getInput('website', ''));
    if ($honeypot !== '') {
        jsonError('Ошибка отправки формы.', 'BOT_DETECTED', 400);
    }

    $botToken = trim((string) getInput('bot_token', ''));
    if ($botToken === '' || !preg_match('/^ok_\d{10,13}$/', $botToken)) {
        jsonError('Подтвердите, что вы не робот.', 'BOT_DETECTED', 400);
    }
    $tokenTime = (int) substr($botToken, 3);
    $nowMs = (int) (microtime(true) * 1000);
    if (($nowMs - $tokenTime) < 60000 || ($nowMs - $tokenTime) > 600000) {
        jsonError('Подтвердите, что вы не робот.', 'BOT_DETECTED', 400);
    }
}

// --- Allowed newsletter sections ---
$allowedSections = [
    4  => 'Новости',
    2  => 'Истории',
    8  => 'Мир фактов',
    7  => 'Песни',
    19 => 'Стихи',
    29 => 'Цитаты',
    31 => 'Анекдоты',
    13 => 'Интересное',
];

// --- Validate input ---
$email     = trim((string) getInput('email', ''));
$sections  = getInput('sections', []);
$frequency = trim((string) getInput('frequency', 'weekly'));

if ($email === '' || !validateEmail($email)) {
    jsonError('Укажите корректный email-адрес.', 'VALIDATION_ERROR', 400, ['email' => 'Некорректный email']);
}

if (!is_array($sections) || empty($sections)) {
    jsonError('Выберите хотя бы один раздел.', 'VALIDATION_ERROR', 400, ['sections' => 'Не выбраны разделы']);
}

$sectionIds = array_map('intval', $sections);
$sectionIds = array_filter($sectionIds, fn(int $id) => isset($allowedSections[$id]));

if (empty($sectionIds)) {
    jsonError('Выберите хотя бы один корректный раздел.', 'VALIDATION_ERROR', 400, ['sections' => 'Некорректные разделы']);
}

if (!in_array($frequency, ['daily', 'weekly'], true)) {
    $frequency = 'weekly';
}

$db = getDb();
$emailDb = toDb($email);

// --- Check for existing subscriber ---
$stmt = $db->prepare('SELECT id, status, confirm_token FROM user_newsletter_subscribers WHERE email = :email');
$stmt->execute([':email' => $emailDb]);
$existing = $stmt->fetch();

if ($existing) {
    if ($existing['status'] === 'confirmed') {
        jsonError('Этот email уже подписан на рассылку.', 'ALREADY_SUBSCRIBED', 409);
    }

    if ($existing['status'] === 'unsubscribed') {
        // Re-subscribe: reset to pending, generate new tokens
        $confirmToken     = bin2hex(random_bytes(32));
        $unsubscribeToken = bin2hex(random_bytes(32));

        $stmt = $db->prepare('
            UPDATE user_newsletter_subscribers
            SET status = :status, frequency = :freq,
                confirm_token = :ct, unsubscribe_token = :ut,
                confirmed_at = NULL, last_sent_at = NULL
            WHERE id = :id
        ');
        $stmt->execute([
            ':status' => 'pending',
            ':freq'   => $frequency,
            ':ct'     => $confirmToken,
            ':ut'     => $unsubscribeToken,
            ':id'     => $existing['id'],
        ]);

        // Update sections
        $db->prepare('DELETE FROM user_newsletter_sections WHERE subscriber_id = :sid')
           ->execute([':sid' => $existing['id']]);

        $insertSec = $db->prepare('INSERT INTO user_newsletter_sections (subscriber_id, section_id) VALUES (:sid, :secid)');
        foreach ($sectionIds as $secId) {
            $insertSec->execute([':sid' => $existing['id'], ':secid' => $secId]);
        }

        sendConfirmationEmail($email, $confirmToken);
        jsonSuccess(['message' => 'Письмо с подтверждением отправлено на ' . $email . '. Проверьте папку «Спам», если не видите письмо.']);
    }

    // status === 'pending' — resend confirmation
    sendConfirmationEmail($email, $existing['confirm_token']);
    jsonSuccess(['message' => 'Письмо с подтверждением повторно отправлено на ' . $email . '. Проверьте папку «Спам», если не видите письмо.']);
}

// --- Create new subscriber ---
$confirmToken     = bin2hex(random_bytes(32));
$unsubscribeToken = bin2hex(random_bytes(32));

$stmt = $db->prepare('
    INSERT INTO user_newsletter_subscribers (email, frequency, status, confirm_token, unsubscribe_token)
    VALUES (:email, :freq, :status, :ct, :ut)
');
$stmt->execute([
    ':email'  => $emailDb,
    ':freq'   => $frequency,
    ':status' => 'pending',
    ':ct'     => $confirmToken,
    ':ut'     => $unsubscribeToken,
]);

$subscriberId = (int) $db->lastInsertId();

$insertSec = $db->prepare('INSERT INTO user_newsletter_sections (subscriber_id, section_id) VALUES (:sid, :secid)');
foreach ($sectionIds as $secId) {
    $insertSec->execute([':sid' => $subscriberId, ':secid' => $secId]);
}

sendConfirmationEmail($email, $confirmToken);

jsonSuccess(['message' => 'Письмо с подтверждением отправлено на ' . $email . '. Проверьте папку «Спам», если не видите письмо.']);

// ---------------------------------------------------------------------------

/**
 * Send a confirmation email to the subscriber.
 */
function sendConfirmationEmail(string $email, string $token): void
{
    $env = parseEnvFile();
    $siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';
    $confirmUrl = $siteUrl . '/api/v1/newsletter/confirm.php?token=' . urlencode($token);

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #d92228;">Peoples.ru</h1>
    <p>Здравствуйте!</p>
    <p>Вы подписались на рассылку peoples.ru. Для подтверждения подписки нажмите на кнопку:</p>
    <p style="text-align: center; margin: 30px 0;">
        <a href="{$confirmUrl}"
           style="background-color: #d92228; color: #fff; padding: 12px 30px; text-decoration: none;
                  border-radius: 5px; font-size: 16px;">
            Подтвердить подписку
        </a>
    </p>
    <p style="font-size: 13px; color: #888;">
        Если вы не подписывались на рассылку, просто проигнорируйте это письмо.
    </p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru</p>
</body>
</html>
HTML;

    sendMail($email, 'Подтверждение подписки — peoples.ru', $html);
}
