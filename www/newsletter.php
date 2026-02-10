<?php
/**
 * Public newsletter subscription page — /newsletter.php
 *
 * No authentication required. Displays a form with email, section checkboxes,
 * and frequency selector. Submits to /api/v1/newsletter/subscribe.php via AJAX.
 *
 * Bot protection: honeypot field + time-based bot token (same pattern as register.php).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/encoding.php';

header('Content-Type: text/html; charset=UTF-8');

$prefillEmail = '';
$prefillSections = [];
$prefillFrequency = 'weekly';
$isManage = false;

// If token provided — look up existing subscriber
$token = trim($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[0-9a-f]{64}$/', $token)) {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, email, frequency, status FROM user_newsletter_subscribers WHERE unsubscribe_token = :token');
    $stmt->execute([':token' => $token]);
    $subscriber = $stmt->fetch();

    if ($subscriber && $subscriber['status'] !== 'unsubscribed') {
        $isManage = true;
        $prefillEmail = htmlspecialchars(fromDb($subscriber['email']) ?? '', ENT_QUOTES, 'UTF-8');
        $prefillFrequency = $subscriber['frequency'];

        $secStmt = $db->prepare('SELECT section_id FROM user_newsletter_sections WHERE subscriber_id = :sid');
        $secStmt->execute([':sid' => $subscriber['id']]);
        $prefillSections = $secStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

// Fallback: email from query param (from peoples.ru footer redirect)
if (!$prefillEmail && isset($_GET['email'])) {
    $prefillEmail = htmlspecialchars(trim($_GET['email']), ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Подписка на рассылку — peoples.ru</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f5f5f5; }
        .newsletter-card { max-width: 560px; margin: 40px auto; }
        .brand-color { color: #d92228; }
        .btn-brand { background-color: #d92228; border-color: #d92228; color: #fff; }
        .btn-brand:hover { background-color: #b81e23; border-color: #b81e23; color: #fff; }
        .section-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        @media (max-width: 400px) { .section-grid { grid-template-columns: 1fr; } }
        .hp-field { position: absolute; left: -9999px; }
    </style>
</head>
<body>

<div class="container">
    <div class="newsletter-card">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <h3 class="brand-color mb-1">peoples.ru</h3>
                    <h5 class="mb-2"><?= $isManage ? 'Настройка подписки' : 'Подписка на рассылку' ?></h5>
                    <p class="text-muted small mb-0">
                        <?= $isManage ? 'Измените разделы или частоту рассылки' : 'Получайте свежие новости и материалы peoples.ru на вашу почту' ?>
                    </p>
                </div>

                <div id="alert-container"></div>

                <form id="newsletter-form" novalidate>
                    <!-- Honeypot — hidden from humans, bots fill it -->
                    <div class="hp-field">
                        <label for="website">Website</label>
                        <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Bot token — set by JS after delay -->
                    <input type="hidden" name="bot_token" id="bot_token" value="">

                    <div class="mb-3">
                        <label for="email" class="form-label">Email-адрес</label>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="you@example.com" required
                               value="<?= $prefillEmail ?>"<?= $isManage ? ' readonly' : '' ?>>
                        <div class="invalid-feedback" id="email-error"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Разделы</label>
                        <div class="section-grid">
                            <?php
                            $sections = [
                                4  => 'Новости',
                                2  => 'Истории',
                                8  => 'Мир фактов',
                                7  => 'Песни',
                                19 => 'Стихи',
                                29 => 'Цитаты',
                                31 => 'Анекдоты',
                                13 => 'Интересное',
                            ];
                            foreach ($sections as $secId => $secName):
                                $checked = $isManage
                                    ? (in_array($secId, $prefillSections) ? 'checked' : '')
                                    : ($secId === 4 ? 'checked' : '');
                            ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="<?= $secId ?>" id="sec-<?= $secId ?>" <?= $checked ?>>
                                <label class="form-check-label" for="sec-<?= $secId ?>"><?= htmlspecialchars($secName, ENT_QUOTES, 'UTF-8') ?></label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="invalid-feedback d-block" id="sections-error" style="display:none!important;"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Частота</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="frequency" value="weekly" id="freq-weekly" <?= $prefillFrequency === 'weekly' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="freq-weekly">Раз в неделю</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="frequency" value="daily" id="freq-daily" <?= $prefillFrequency === 'daily' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="freq-daily">Каждый день</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-brand w-100" id="btn-subscribe">
                        <i class="bi bi-envelope-check me-1"></i><?= $isManage ? 'Сохранить' : 'Подписаться' ?>
                    </button>
                </form>
            </div>
        </div>

        <p class="text-center text-muted small mt-3">
            <a href="https://peoples.ru" class="text-decoration-none">peoples.ru</a>
        </p>
    </div>
</div>

<script>
// Set bot token on page load — server checks that at least 1s passed before submit
document.getElementById('bot_token').value = 'ok_' + Date.now();

document.getElementById('newsletter-form').addEventListener('submit', function(e) {
    e.preventDefault();

    var form = this;
    var btn = document.getElementById('btn-subscribe');
    var alertBox = document.getElementById('alert-container');
    var emailInput = document.getElementById('email');
    var emailError = document.getElementById('email-error');
    var sectionsError = document.getElementById('sections-error');

    // Reset
    alertBox.innerHTML = '';
    emailInput.classList.remove('is-invalid');
    sectionsError.style.setProperty('display', 'none', 'important');

    var email = emailInput.value.trim();
    var checkedSections = form.querySelectorAll('input[name="sections[]"]:checked');
    var frequency = form.querySelector('input[name="frequency"]:checked').value;

    // Client-side validation
    if (!email) {
        emailInput.classList.add('is-invalid');
        emailError.textContent = 'Укажите email-адрес';
        return;
    }

    if (checkedSections.length === 0) {
        sectionsError.textContent = 'Выберите хотя бы один раздел';
        sectionsError.style.setProperty('display', 'block', 'important');
        return;
    }

    var sections = [];
    checkedSections.forEach(function(cb) { sections.push(parseInt(cb.value)); });

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Отправка...';

    fetch('/api/v1/newsletter/subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email: email,
            sections: sections,
            frequency: frequency,
            website: document.getElementById('website').value,
            bot_token: document.getElementById('bot_token').value
        })
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-envelope-check me-1"></i>Подписаться';

        if (data.success) {
            alertBox.innerHTML = '<div class="alert alert-success">' +
                '<i class="bi bi-check-circle me-1"></i>' + data.data.message + '</div>';
            form.reset();
        } else {
            var msg = data.error ? data.error.message : 'Произошла ошибка';
            alertBox.innerHTML = '<div class="alert alert-danger">' +
                '<i class="bi bi-exclamation-triangle me-1"></i>' + msg + '</div>';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-envelope-check me-1"></i>Подписаться';
        alertBox.innerHTML = '<div class="alert alert-danger">Ошибка сети. Попробуйте позже.</div>';
    });
});
</script>

</body>
</html>
