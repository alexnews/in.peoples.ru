<?php

declare(strict_types=1);

/**
 * POST /api/v1/fan/join.php
 *
 * Public endpoint — join a fan club for a person.
 * Bot protection: honeypot + time-based token.
 * Rate limit: 5 requests per hour per IP.
 *
 * Input (JSON or POST):
 *   - person_id: int (required)
 *   - name: string (required)
 *   - email: string (required)
 *   - message: string (optional)
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (5 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/fan_join_rate_' . md5($ip);

if (is_file($rateLimitFile)) {
    $rateData = json_decode(file_get_contents($rateLimitFile), true);
    if (is_array($rateData) && ($rateData['time'] ?? 0) > time() - 3600) {
        if (($rateData['count'] ?? 0) >= 5) {
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

// --- Bot protection: honeypot + time-based token ---
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

// --- Validate input ---
$personIdInput = getInput('person_id');
$name    = trim((string) getInput('name', ''));
$email   = trim((string) getInput('email', ''));
$message = trim((string) getInput('message', ''));

$errors = [];

if ($personIdInput === null || $personIdInput === '' || (int) $personIdInput <= 0) {
    $errors['person_id'] = 'Не указана персона';
}

if ($name === '' || mb_strlen($name, 'UTF-8') < 2) {
    $errors['name'] = 'Укажите ваше имя';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Укажите корректный email';
}

if (!empty($errors)) {
    jsonError('Проверьте заполнение полей.', 'VALIDATION_ERROR', 400, $errors);
}

$personId = (int) $personIdInput;

$db = getDb();

// --- Verify person exists ---
$pStmt = $db->prepare('SELECT Persons_id, FullNameRus FROM persons WHERE Persons_id = :pid');
$pStmt->execute([':pid' => $personId]);
$personRow = $pStmt->fetch();

if (!$personRow) {
    jsonError('Персона не найдена.', 'NOT_FOUND', 404);
}

$personName = fromDb($personRow['FullNameRus'] ?? '');

// --- Check for existing membership ---
$emailDb = toDb($email);
$existStmt = $db->prepare(
    'SELECT id, status, confirm_token FROM user_fan_club_members WHERE person_id = :pid AND email = :email'
);
$existStmt->execute([':pid' => $personId, ':email' => $emailDb]);
$existing = $existStmt->fetch();

if ($existing) {
    if ($existing['status'] === 'confirmed') {
        jsonError('Вы уже состоите в фан-клубе.', 'ALREADY_MEMBER', 409);
    }

    if ($existing['status'] === 'unsubscribed') {
        // Re-subscribe
        $confirmToken = bin2hex(random_bytes(32));
        $unsubscribeToken = bin2hex(random_bytes(32));

        $resubStmt = $db->prepare(
            'UPDATE user_fan_club_members
             SET status = :status, name = :name, message = :msg,
                 confirm_token = :ct, unsubscribe_token = :ut,
                 confirmed_at = NULL, ip_address = :ip, user_agent = :ua
             WHERE id = :id'
        );
        $resubStmt->execute([
            ':status' => 'pending',
            ':name'   => toDb($name),
            ':msg'    => $message !== '' ? toDb($message) : null,
            ':ct'     => $confirmToken,
            ':ut'     => $unsubscribeToken,
            ':ip'     => $ip,
            ':ua'     => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
            ':id'     => $existing['id'],
        ]);

        sendFanConfirmationEmail($email, $confirmToken, $personName);
        jsonSuccess(['message' => 'Письмо с подтверждением отправлено на ' . $email . '.'], 200);
    }

    // status === 'pending' — resend confirmation
    sendFanConfirmationEmail($email, $existing['confirm_token'], $personName);
    jsonSuccess(['message' => 'Письмо с подтверждением повторно отправлено на ' . $email . '.'], 200);
}

// --- Create new member ---
$confirmToken = bin2hex(random_bytes(32));
$unsubscribeToken = bin2hex(random_bytes(32));

$insertStmt = $db->prepare(
    'INSERT INTO user_fan_club_members
        (person_id, email, name, message, status, confirm_token, unsubscribe_token, ip_address, user_agent)
     VALUES
        (:person_id, :email, :name, :message, :status, :ct, :ut, :ip, :ua)'
);
$insertStmt->execute([
    ':person_id' => $personId,
    ':email'     => $emailDb,
    ':name'      => toDb($name),
    ':message'   => $message !== '' ? toDb($message) : null,
    ':status'    => 'pending',
    ':ct'        => $confirmToken,
    ':ut'        => $unsubscribeToken,
    ':ip'        => $ip,
    ':ua'        => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
]);

$memberId = (int) $db->lastInsertId();

// --- Send confirmation email ---
sendFanConfirmationEmail($email, $confirmToken, $personName);

// --- Send admin notification ---
$env = parseEnvFile();
$adminEmail = $env['BOOKING_ADMIN_EMAIL'] ?? $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

$messageBlock = $message !== ''
    ? '<div style="margin:15px 10px; padding:10px; background:#f5f5f5; border-radius:5px;">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>'
    : '';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #d92228;">Новый участник фан-клуба #{$memberId}</h2>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td style="padding:5px 10px; font-weight:bold;">Персона:</td><td style="padding:5px 10px;">{$personName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Имя:</td><td style="padding:5px 10px;">{$name}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Email:</td><td style="padding:5px 10px;">{$email}</td></tr>
    </table>
    {$messageBlock}
    <p style="margin-top:15px;"><a href="{$siteUrl}/moderate/fan-clubs.php">Открыть панель управления</a></p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru — фан-клуб</p>
</body>
</html>
HTML;

sendMail($adminEmail, "Фан-клуб: {$personName} — новый участник #{$memberId}", $html);

jsonSuccess(['message' => 'Письмо с подтверждением отправлено на ' . $email . '. Проверьте папку «Спам», если не видите письмо.'], 201);

// ---------------------------------------------------------------------------

/**
 * Send a confirmation email to the fan.
 */
function sendFanConfirmationEmail(string $email, string $token, string $personName): void
{
    $env = parseEnvFile();
    $siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';
    $confirmUrl = $siteUrl . '/api/v1/fan/confirm.php?token=' . urlencode($token);
    $personNameSafe = htmlspecialchars($personName, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h1 style="color: #d92228;">Peoples.ru</h1>
    <p>Здравствуйте!</p>
    <p>Вы подали заявку на вступление в фан-клуб <strong>{$personNameSafe}</strong>. Для подтверждения нажмите на кнопку:</p>
    <p style="text-align: center; margin: 30px 0;">
        <a href="{$confirmUrl}"
           style="background-color: #d92228; color: #fff; padding: 12px 30px; text-decoration: none;
                  border-radius: 5px; font-size: 16px;">
            Подтвердить участие
        </a>
    </p>
    <p style="font-size: 13px; color: #888;">
        Если вы не подавали заявку, просто проигнорируйте это письмо.
    </p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru</p>
</body>
</html>
HTML;

    sendMail($email, "Подтверждение участия в фан-клубе: {$personName} — peoples.ru", $html);
}
