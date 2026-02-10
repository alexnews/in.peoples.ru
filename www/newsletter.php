<?php
/**
 * Public newsletter subscription page — /newsletter.php
 *
 * No authentication required. Displays a form with email, section checkboxes,
 * and frequency selector. Submits to /api/v1/newsletter/subscribe.php via AJAX.
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
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
    </style>
</head>
<body>

<div class="container">
    <div class="newsletter-card">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <div class="text-center mb-4">
                    <h3 class="brand-color mb-1">peoples.ru</h3>
                    <h5 class="mb-2">Подписка на рассылку</h5>
                    <p class="text-muted small mb-0">
                        Получайте свежие новости и материалы peoples.ru на вашу почту
                    </p>
                </div>

                <div id="alert-container"></div>

                <form id="newsletter-form" novalidate>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email-адрес</label>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="you@example.com" required autofocus>
                        <div class="invalid-feedback" id="email-error"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Разделы</label>
                        <div class="section-grid">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="4" id="sec-4" checked>
                                <label class="form-check-label" for="sec-4">Новости</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="2" id="sec-2">
                                <label class="form-check-label" for="sec-2">Истории</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="8" id="sec-8">
                                <label class="form-check-label" for="sec-8">Мир фактов</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="7" id="sec-7">
                                <label class="form-check-label" for="sec-7">Песни</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="19" id="sec-19">
                                <label class="form-check-label" for="sec-19">Стихи</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="29" id="sec-29">
                                <label class="form-check-label" for="sec-29">Цитаты</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="31" id="sec-31">
                                <label class="form-check-label" for="sec-31">Анекдоты</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="sections[]" value="13" id="sec-13">
                                <label class="form-check-label" for="sec-13">Интересное</label>
                            </div>
                        </div>
                        <div class="invalid-feedback d-block" id="sections-error" style="display:none!important;"></div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Частота</label>
                        <div class="d-flex gap-3">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="frequency" value="weekly" id="freq-weekly" checked>
                                <label class="form-check-label" for="freq-weekly">Раз в неделю</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="frequency" value="daily" id="freq-daily">
                                <label class="form-check-label" for="freq-daily">Каждый день</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-brand w-100" id="btn-subscribe">
                        <i class="bi bi-envelope-check me-1"></i>Подписаться
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
        body: JSON.stringify({ email: email, sections: sections, frequency: frequency })
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
