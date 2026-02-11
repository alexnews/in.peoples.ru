<?php

declare(strict_types=1);

/**
 * POST /api/v1/info-change/submit.php
 *
 * Public endpoint — submit a request to change person info.
 * Bot protection: honeypot + time-based token.
 * Rate limit: 3 requests per hour per IP.
 *
 * Input (JSON or POST):
 *   - person_id: int (optional, from autocomplete)
 *   - person_name_manual: string (optional, fallback if not in DB)
 *   - requester_name: string (required)
 *   - requester_phone: string (required)
 *   - requester_email: string (optional)
 *   - requester_role: string (self|manager|relative|fan|other)
 *   - change_fields: string (comma-separated)
 *   - description: string (required)
 *   - evidence_url: string (optional)
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (3 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/info_change_rate_' . md5($ip);

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

// --- Valid roles ---
$validRoles = ['self', 'manager', 'relative', 'fan', 'other'];

// --- Validate input ---
$requesterName  = trim((string) getInput('requester_name', ''));
$requesterPhone = trim((string) getInput('requester_phone', ''));
$requesterEmail = trim((string) getInput('requester_email', ''));
$description    = trim((string) getInput('description', ''));

$errors = [];

if ($requesterName === '' || mb_strlen($requesterName, 'UTF-8') < 2) {
    $errors['requester_name'] = 'Укажите ваше имя';
}

if ($requesterPhone === '' || mb_strlen($requesterPhone, 'UTF-8') < 6) {
    $errors['requester_phone'] = 'Укажите номер телефона';
}

if ($description === '' || mb_strlen($description, 'UTF-8') < 10) {
    $errors['description'] = 'Опишите изменения (минимум 10 символов)';
}

if ($requesterEmail !== '' && !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
    $errors['requester_email'] = 'Некорректный email';
}

if (!empty($errors)) {
    jsonError('Проверьте заполнение полей.', 'VALIDATION_ERROR', 400, $errors);
}

// Optional fields
$personId        = getInput('person_id');
$personNameManual = trim((string) getInput('person_name_manual', ''));
$requesterRole   = trim((string) getInput('requester_role', ''));
$changeFields    = trim((string) getInput('change_fields', ''));
$evidenceUrl     = trim((string) getInput('evidence_url', ''));

// Validate role
if ($requesterRole !== '' && !in_array($requesterRole, $validRoles, true)) {
    $requesterRole = 'other';
}

$db = getDb();

// Validate person_id if provided
$personName = '';
if ($personId !== null && $personId !== '') {
    $pStmt = $db->prepare('SELECT FullNameRus FROM persons WHERE Persons_id = :pid');
    $pStmt->execute([':pid' => (int) $personId]);
    $personName = fromDb($pStmt->fetchColumn() ?: '') ?: '';
    if ($personName === '') {
        // person_id invalid, clear it
        $personId = null;
    }
}

// --- Insert request ---
$stmt = $db->prepare(
    'INSERT INTO user_info_change_requests
        (person_id, person_name_manual, requester_name, requester_phone, requester_email,
         requester_role, change_fields, description, evidence_url, status, ip_address, user_agent)
     VALUES
        (:person_id, :person_name_manual, :requester_name, :requester_phone, :requester_email,
         :requester_role, :change_fields, :description, :evidence_url, :status, :ip, :ua)'
);

$stmt->execute([
    ':person_id'          => $personId !== null && $personId !== '' ? (int) $personId : null,
    ':person_name_manual' => $personNameManual !== '' ? toDb($personNameManual) : null,
    ':requester_name'     => toDb($requesterName),
    ':requester_phone'    => toDb($requesterPhone),
    ':requester_email'    => $requesterEmail !== '' ? toDb($requesterEmail) : null,
    ':requester_role'     => $requesterRole !== '' ? $requesterRole : 'other',
    ':change_fields'      => $changeFields !== '' ? toDb($changeFields) : null,
    ':description'        => toDb($description),
    ':evidence_url'       => $evidenceUrl !== '' ? $evidenceUrl : null,
    ':status'             => 'new',
    ':ip'                 => $ip,
    ':ua'                 => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
]);

$requestId = (int) $db->lastInsertId();

// --- Send admin notification email ---
$displayPerson = $personName !== '' ? $personName : ($personNameManual !== '' ? $personNameManual : 'не указано');

$roleLabels = [
    'self'     => 'Сам артист',
    'manager'  => 'Менеджер',
    'relative' => 'Родственник',
    'fan'      => 'Поклонник',
    'other'    => 'Другое',
];
$roleLabel = $roleLabels[$requesterRole] ?? 'Другое';

$env = parseEnvFile();
$adminEmail = $env['BOOKING_ADMIN_EMAIL'] ?? $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #d92228;">Запрос на изменение информации #{$requestId}</h2>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td style="padding:5px 10px; font-weight:bold;">Персона:</td><td style="padding:5px 10px;">{$displayPerson}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Кто:</td><td style="padding:5px 10px;">{$roleLabel}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Имя:</td><td style="padding:5px 10px;">{$requesterName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Телефон:</td><td style="padding:5px 10px;">{$requesterPhone}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Email:</td><td style="padding:5px 10px;">{$requesterEmail}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Что изменить:</td><td style="padding:5px 10px;">{$changeFields}</td></tr>
    </table>
    <div style="margin:15px 10px; padding:10px; background:#f5f5f5; border-radius:5px;">
        {$description}
    </div>
    <p style="margin-top:15px;"><a href="{$siteUrl}/moderate/info-changes.php">Открыть панель управления</a></p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru — изменение информации</p>
</body>
</html>
HTML;

sendMail($adminEmail, "Запрос на изменение информации #{$requestId}", $html);

jsonSuccess(['message' => 'Заявка успешно отправлена! Мы рассмотрим ваш запрос и внесём изменения.', 'request_id' => $requestId], 201);
