<?php

declare(strict_types=1);

/**
 * POST /api/v1/adv/submit.php
 *
 * Public endpoint — submit an advertising inquiry.
 * Bot protection: honeypot + time-based token.
 * Rate limit: 3 requests per hour per IP.
 *
 * Input (JSON or POST):
 *   - ad_type: string (banner|content|sponsorship|other)
 *   - company_name: string (optional)
 *   - contact_name: string (required)
 *   - contact_phone: string (required)
 *   - contact_email: string (optional)
 *   - message: string (required)
 *   - budget: string (optional)
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (3 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/ad_request_rate_' . md5($ip);

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

// --- Valid ad types ---
$validAdTypes = ['banner', 'content', 'sponsorship', 'other'];

// --- Validate input ---
$contactName  = trim((string) getInput('contact_name', ''));
$contactPhone = trim((string) getInput('contact_phone', ''));
$contactEmail = trim((string) getInput('contact_email', ''));
$message      = trim((string) getInput('message', ''));

$errors = [];

if ($contactName === '' || mb_strlen($contactName, 'UTF-8') < 2) {
    $errors['contact_name'] = 'Укажите ваше имя';
}

if ($contactPhone === '' || mb_strlen($contactPhone, 'UTF-8') < 6) {
    $errors['contact_phone'] = 'Укажите номер телефона';
}

if ($message === '' || mb_strlen($message, 'UTF-8') < 10) {
    $errors['message'] = 'Опишите ваш запрос (минимум 10 символов)';
}

if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
    $errors['contact_email'] = 'Некорректный email';
}

if (!empty($errors)) {
    jsonError('Проверьте заполнение полей.', 'VALIDATION_ERROR', 400, $errors);
}

// Optional fields
$adType      = trim((string) getInput('ad_type', ''));
$companyName = trim((string) getInput('company_name', ''));
$budget      = trim((string) getInput('budget', ''));

// Validate ad_type
if ($adType !== '' && !in_array($adType, $validAdTypes, true)) {
    $adType = 'other';
}

$db = getDb();

// --- Insert request ---
$stmt = $db->prepare(
    'INSERT INTO user_ad_requests
        (ad_type, company_name, contact_name, contact_phone, contact_email,
         message, budget, status, ip_address, user_agent)
     VALUES
        (:ad_type, :company_name, :contact_name, :contact_phone, :contact_email,
         :message, :budget, :status, :ip, :ua)'
);

$stmt->execute([
    ':ad_type'       => $adType !== '' ? $adType : 'other',
    ':company_name'  => $companyName !== '' ? toDb($companyName) : null,
    ':contact_name'  => toDb($contactName),
    ':contact_phone' => toDb($contactPhone),
    ':contact_email' => $contactEmail !== '' ? toDb($contactEmail) : null,
    ':message'       => toDb($message),
    ':budget'        => $budget !== '' ? toDb($budget) : null,
    ':status'        => 'new',
    ':ip'            => $ip,
    ':ua'            => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
]);

$requestId = (int) $db->lastInsertId();

// --- Send admin notification email ---
$adTypeLabels = [
    'banner'      => 'Баннерная реклама',
    'content'     => 'Размещение материалов',
    'sponsorship' => 'Спонсорство',
    'other'       => 'Другое',
];
$adTypeLabel = $adTypeLabels[$adType] ?? 'Другое';

$env = parseEnvFile();
$adminEmail = $env['BOOKING_ADMIN_EMAIL'] ?? $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #d92228;">Запрос на рекламу #{$requestId}</h2>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td style="padding:5px 10px; font-weight:bold;">Тип:</td><td style="padding:5px 10px;">{$adTypeLabel}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Компания:</td><td style="padding:5px 10px;">{$companyName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Имя:</td><td style="padding:5px 10px;">{$contactName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Телефон:</td><td style="padding:5px 10px;">{$contactPhone}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Email:</td><td style="padding:5px 10px;">{$contactEmail}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Бюджет:</td><td style="padding:5px 10px;">{$budget}</td></tr>
    </table>
    <div style="margin:15px 10px; padding:10px; background:#f5f5f5; border-radius:5px;">
        {$message}
    </div>
    <p style="margin-top:15px;"><a href="{$siteUrl}/moderate/ad-requests.php">Открыть панель управления</a></p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru — реклама</p>
</body>
</html>
HTML;

sendMail($adminEmail, "Запрос на рекламу #{$requestId}", $html);

jsonSuccess(['message' => 'Заявка успешно отправлена! Мы рассмотрим ваш запрос и свяжемся с вами.', 'request_id' => $requestId], 201);
