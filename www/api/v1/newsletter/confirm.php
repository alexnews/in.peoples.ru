<?php

declare(strict_types=1);

/**
 * GET /api/v1/newsletter/confirm.php?token=xxx
 *
 * Confirms a newsletter subscription via token link from email.
 * Outputs an HTML page (not JSON) since user clicks this from email.
 */

require_once dirname(__DIR__, 3) . '/includes/db.php';

header('Content-Type: text/html; charset=utf-8');

$token = trim($_GET['token'] ?? '');

if ($token === '' || !preg_match('/^[0-9a-f]{64}$/', $token)) {
    showPage('Ошибка', 'Неверная ссылка для подтверждения.', true);
}

$db = getDb();

$stmt = $db->prepare('SELECT id, status FROM user_newsletter_subscribers WHERE confirm_token = :token');
$stmt->execute([':token' => $token]);
$subscriber = $stmt->fetch();

if (!$subscriber) {
    showPage('Ошибка', 'Подписка не найдена. Возможно, ссылка устарела.', true);
}

if ($subscriber['status'] === 'confirmed') {
    showPage('Подписка уже подтверждена', 'Ваша подписка уже была подтверждена ранее.', false);
}

if ($subscriber['status'] === 'unsubscribed') {
    showPage('Ошибка', 'Эта подписка была отменена.', true);
}

$stmt = $db->prepare('
    UPDATE user_newsletter_subscribers
    SET status = :status, confirmed_at = NOW()
    WHERE id = :id
');
$stmt->execute([':status' => 'confirmed', ':id' => $subscriber['id']]);

showPage('Подписка подтверждена!', 'Спасибо! Вы успешно подписались на рассылку peoples.ru.', false);

// ---------------------------------------------------------------------------

/**
 * Render a simple HTML response page and exit.
 */
function showPage(string $title, string $message, bool $isError): never
{
    $color = $isError ? '#dc3545' : '#28a745';
    $icon  = $isError ? '&#10060;' : '&#10004;';

    echo <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title} — peoples.ru</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px;
               display: flex; justify-content: center; align-items: flex-start; min-height: 100vh; }
        .card { background: #fff; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,.1);
                padding: 40px; max-width: 480px; text-align: center; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { color: #333; font-size: 22px; margin: 0 0 12px; }
        p { color: #666; font-size: 15px; line-height: 1.5; margin: 0 0 20px; }
        a { color: #d92228; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon" style="color: {$color};">{$icon}</div>
        <h1>{$title}</h1>
        <p>{$message}</p>
        <a href="https://peoples.ru">Перейти на peoples.ru</a>
    </div>
</body>
</html>
HTML;
    exit;
}
