<?php

declare(strict_types=1);

/**
 * POST /api/v1/booking/register.php
 *
 * Public endpoint — celebrity self-registration for booking catalog.
 * Bot protection: honeypot + time-based token.
 * Rate limit: 3 requests per hour per IP.
 *
 * Input (JSON or POST):
 *   - full_name: string (required, min 2 chars)
 *   - phone: string (required, min 6 chars)
 *   - email: string (optional, validated if provided)
 *   - city: string (optional)
 *   - category_id: int (optional, must match booking_categories)
 *   - activity_description: string (optional)
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (3 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/booking_reg_rate_' . md5($ip);

if (is_file($rateLimitFile)) {
    $rateData = json_decode(file_get_contents($rateLimitFile), true);
    if (is_array($rateData) && ($rateData['time'] ?? 0) > time() - 3600) {
        if (($rateData['count'] ?? 0) >= 3) {
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
$fullName    = trim((string) getInput('full_name', ''));
$phone       = trim((string) getInput('phone', ''));
$email       = trim((string) getInput('email', ''));
$city        = trim((string) getInput('city', ''));
$categoryId  = getInput('category_id');
$activityDesc = trim((string) getInput('activity_description', ''));

$errors = [];

if ($fullName === '' || mb_strlen($fullName, 'UTF-8') < 2) {
    $errors['full_name'] = 'Укажите ваше имя (минимум 2 символа)';
}

if ($phone === '' || mb_strlen($phone, 'UTF-8') < 6) {
    $errors['phone'] = 'Укажите номер телефона';
}

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Некорректный email';
}

if (!empty($errors)) {
    jsonError('Проверьте заполнение полей.', 'VALIDATION_ERROR', 400, $errors);
}

$db = getDb();

// Validate category_id if provided
if ($categoryId !== null && $categoryId !== '') {
    $categoryId = (int) $categoryId;
    $cChk = $db->prepare('SELECT id FROM booking_categories WHERE id = :cid');
    $cChk->execute([':cid' => $categoryId]);
    if (!$cChk->fetch()) {
        $categoryId = null;
    }
} else {
    $categoryId = null;
}

// --- Insert application ---
$stmt = $db->prepare(
    'INSERT INTO booking_applications
        (full_name, phone, email, city, category_id, activity_description,
         status, ip_address, user_agent)
     VALUES
        (:full_name, :phone, :email, :city, :category_id, :activity_desc,
         :status, :ip, :ua)'
);

$stmt->execute([
    ':full_name'      => toDb($fullName),
    ':phone'          => toDb($phone),
    ':email'          => $email !== '' ? toDb($email) : null,
    ':city'           => $city !== '' ? toDb($city) : null,
    ':category_id'    => $categoryId,
    ':activity_desc'  => $activityDesc !== '' ? toDb($activityDesc) : null,
    ':status'         => 'new',
    ':ip'             => $ip,
    ':ua'             => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
]);

$applicationId = (int) $db->lastInsertId();

// --- Send admin notification email ---
$env = parseEnvFile();
$adminEmail = $env['BOOKING_ADMIN_EMAIL'] ?? $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

$categoryName = '';
if ($categoryId !== null) {
    $catStmt = $db->prepare('SELECT name FROM booking_categories WHERE id = :cid');
    $catStmt->execute([':cid' => $categoryId]);
    $categoryName = fromDb($catStmt->fetchColumn() ?: '');
}

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #d92228;">Новая заявка артиста #{$applicationId}</h2>
    <p>Артист оставил заявку на добавление в каталог приглашений.</p>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td style="padding:5px 10px; font-weight:bold;">Имя:</td><td style="padding:5px 10px;">{$fullName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Телефон:</td><td style="padding:5px 10px;">{$phone}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Email:</td><td style="padding:5px 10px;">{$email}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Город:</td><td style="padding:5px 10px;">{$city}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Категория:</td><td style="padding:5px 10px;">{$categoryName}</td></tr>
    </table>
    <p style="margin-top:15px;"><a href="{$siteUrl}/moderate/booking-applications.php">Открыть панель управления</a></p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru — приглашения</p>
</body>
</html>
HTML;

sendMail($adminEmail, "Новая заявка артиста #{$applicationId}", $html);

jsonSuccess(['message' => 'Заявка успешно отправлена! Мы рассмотрим её и свяжемся с вами.', 'application_id' => $applicationId], 201);
