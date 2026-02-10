<?php

declare(strict_types=1);

/**
 * POST /api/v1/booking/request.php
 *
 * Public endpoint — submit a booking inquiry.
 * Bot protection: honeypot + time-based token.
 * Rate limit: 5 requests per hour per IP.
 *
 * Input (JSON or POST):
 *   - person_id: int (optional)
 *   - booking_person_id: int (optional)
 *   - client_name: string (required)
 *   - client_phone: string (required)
 *   - client_email: string
 *   - client_company: string
 *   - event_type: string
 *   - event_date: string (YYYY-MM-DD)
 *   - event_city: string
 *   - event_venue: string
 *   - guest_count: int
 *   - budget_from: int
 *   - budget_to: int
 *   - message: string
 *   - website: string (honeypot — must be empty)
 *   - bot_token: string (time-based token)
 */

require_once __DIR__ . '/../config.php';
require_once dirname(__DIR__, 3) . '/includes/mail.php';

requireMethod('POST');

// --- Rate limiting by IP (5 requests per hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$rateLimitFile = sys_get_temp_dir() . '/booking_rate_' . md5($ip);

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

// --- Valid event types ---
$validEventTypes = [
    'corporate'  => 'Корпоратив',
    'wedding'    => 'Свадьба',
    'birthday'   => 'День рождения',
    'concert'    => 'Концерт',
    'private'    => 'Частная вечеринка',
    'city_event' => 'Городское мероприятие',
    'charity'    => 'Благотворительное мероприятие',
    'opening'    => 'Открытие / презентация',
    'other'      => 'Другое',
];

// --- Validate input ---
$clientName  = trim((string) getInput('client_name', ''));
$clientPhone = trim((string) getInput('client_phone', ''));
$clientEmail = trim((string) getInput('client_email', ''));
$clientCompany = trim((string) getInput('client_company', ''));

$errors = [];

if ($clientName === '' || mb_strlen($clientName, 'UTF-8') < 2) {
    $errors['client_name'] = 'Укажите ваше имя';
}

if ($clientPhone === '' || mb_strlen($clientPhone, 'UTF-8') < 6) {
    $errors['client_phone'] = 'Укажите номер телефона';
}

if ($clientEmail !== '' && !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
    $errors['client_email'] = 'Некорректный email';
}

if (!empty($errors)) {
    jsonError('Проверьте заполнение полей.', 'VALIDATION_ERROR', 400, $errors);
}

// Optional fields
$personId        = getInput('person_id');
$bookingPersonId = getInput('booking_person_id');
$eventType       = trim((string) getInput('event_type', ''));
$eventDate       = trim((string) getInput('event_date', ''));
$eventCity       = trim((string) getInput('event_city', ''));
$eventVenue      = trim((string) getInput('event_venue', ''));
$guestCount      = getInput('guest_count');
$budgetFrom      = getInput('budget_from');
$budgetTo        = getInput('budget_to');
$message         = trim((string) getInput('message', ''));

// Validate event_type if provided
if ($eventType !== '' && !isset($validEventTypes[$eventType])) {
    $eventType = 'other';
}

// Validate event_date format
if ($eventDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
    $eventDate = null;
}

$db = getDb();

// --- Insert request ---
$stmt = $db->prepare(
    'INSERT INTO booking_requests
        (person_id, booking_person_id, client_name, client_phone, client_email,
         client_company, event_type, event_date, event_city, event_venue,
         guest_count, budget_from, budget_to, message, status, ip_address, user_agent)
     VALUES
        (:person_id, :booking_person_id, :client_name, :client_phone, :client_email,
         :client_company, :event_type, :event_date, :event_city, :event_venue,
         :guest_count, :budget_from, :budget_to, :message, :status, :ip, :ua)'
);

$stmt->execute([
    ':person_id'         => $personId !== null && $personId !== '' ? (int) $personId : null,
    ':booking_person_id' => $bookingPersonId !== null && $bookingPersonId !== '' ? (int) $bookingPersonId : null,
    ':client_name'       => toDb($clientName),
    ':client_phone'      => toDb($clientPhone),
    ':client_email'      => $clientEmail !== '' ? toDb($clientEmail) : null,
    ':client_company'    => $clientCompany !== '' ? toDb($clientCompany) : null,
    ':event_type'        => $eventType !== '' ? $eventType : null,
    ':event_date'        => $eventDate !== '' ? $eventDate : null,
    ':event_city'        => $eventCity !== '' ? toDb($eventCity) : null,
    ':event_venue'       => $eventVenue !== '' ? toDb($eventVenue) : null,
    ':guest_count'       => $guestCount !== null && $guestCount !== '' ? (int) $guestCount : null,
    ':budget_from'       => $budgetFrom !== null && $budgetFrom !== '' ? (int) $budgetFrom : null,
    ':budget_to'         => $budgetTo !== null && $budgetTo !== '' ? (int) $budgetTo : null,
    ':message'           => $message !== '' ? toDb($message) : null,
    ':status'            => 'new',
    ':ip'                => $ip,
    ':ua'                => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500, 'UTF-8'),
]);

$requestId = (int) $db->lastInsertId();

// --- Log initial status ---
$logStmt = $db->prepare(
    'INSERT INTO booking_request_status_log (request_id, old_status, new_status, note)
     VALUES (:rid, NULL, :status, :note)'
);
$logStmt->execute([
    ':rid'    => $requestId,
    ':status' => 'new',
    ':note'   => 'Заявка создана с сайта',
]);

// --- Send admin notification email ---
$personName = '';
if ($personId !== null && $personId !== '') {
    $pStmt = $db->prepare('SELECT FullNameRus FROM persons WHERE Persons_id = :pid');
    $pStmt->execute([':pid' => (int) $personId]);
    $personName = fromDb($pStmt->fetchColumn() ?: '') ?: 'ID ' . $personId;
}

$env = parseEnvFile();
$adminEmail = $env['BOOKING_ADMIN_EMAIL'] ?? $env['MAIL_REPLY_TO'] ?? 'alex@peoples.ru';
$siteUrl = $env['SITE_URL'] ?? 'https://in.peoples.ru';

$eventTypeLabel = $eventType !== '' ? ($validEventTypes[$eventType] ?? $eventType) : 'не указан';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <h2 style="color: #d92228;">Новая заявка на букинг #{$requestId}</h2>
    <table style="width:100%; border-collapse:collapse;">
        <tr><td style="padding:5px 10px; font-weight:bold;">Имя:</td><td style="padding:5px 10px;">{$clientName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Телефон:</td><td style="padding:5px 10px;">{$clientPhone}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Email:</td><td style="padding:5px 10px;">{$clientEmail}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Компания:</td><td style="padding:5px 10px;">{$clientCompany}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Знаменитость:</td><td style="padding:5px 10px;">{$personName}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Тип:</td><td style="padding:5px 10px;">{$eventTypeLabel}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Дата:</td><td style="padding:5px 10px;">{$eventDate}</td></tr>
        <tr><td style="padding:5px 10px; font-weight:bold;">Город:</td><td style="padding:5px 10px;">{$eventCity}</td></tr>
    </table>
    <p style="margin-top:15px;"><a href="{$siteUrl}/moderate/booking.php">Открыть панель управления</a></p>
    <hr>
    <p style="font-size: 12px; color: #888;">peoples.ru — букинг</p>
</body>
</html>
HTML;

sendMail($adminEmail, "Новая заявка на букинг #{$requestId}", $html);

jsonSuccess(['message' => 'Заявка успешно отправлена! Мы свяжемся с вами в ближайшее время.', 'request_id' => $requestId], 201);
