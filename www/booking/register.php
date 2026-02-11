<?php

declare(strict_types=1);

/**
 * Celebrity Self-Registration Page — /booking/register/
 *
 * Simple form for celebrities to leave their contact info for booking catalog.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

// Active categories for dropdown
$catStmt = $db->query(
    'SELECT id, name FROM booking_categories WHERE is_active = 1 ORDER BY sort_order ASC'
);
$categories = fromDbRows($catStmt->fetchAll());

$pageTitle = 'Заявка — Приглашения';
$pageDesc = 'Вы — известный человек? Спортсмен, писатель, музыкант? Оставьте заявку и мы добавим вас в каталог peoples.ru';

header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — peoples.ru</title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/booking.css" rel="stylesheet">
</head>
<body>

<!-- Hero -->
<section class="booking-register-hero">
    <div class="container">
        <h1>Вы — известный человек? Оставьте заявку!</h1>
        <p>Мы помогаем спортсменам, писателям, музыкантам и другим известным людям оставаться на виду. Заполните простую форму — и мы свяжемся с вами.</p>
    </div>
</section>

<!-- Registration form -->
<section class="py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <div class="booking-form">
                    <form class="booking-register-form">
                        <div class="mb-3">
                            <label class="form-label">Ваше имя <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control form-control-lg" required placeholder="Фамилия Имя Отчество">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Телефон <span class="text-danger">*</span></label>
                            <input type="tel" name="phone" class="form-control form-control-lg" required placeholder="+7 (___) ___-__-__">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Город</label>
                            <input type="text" name="city" class="form-control" placeholder="Где вы находитесь">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Категория</label>
                            <select name="category_id" class="form-select">
                                <option value="">— Выберите категорию —</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= (int)$cat['id'] ?>">
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Расскажите о себе</label>
                            <textarea name="activity_description" class="form-control" rows="4" placeholder="Чем вы занимаетесь? Какой у вас опыт? Какие выступления проводите?"></textarea>
                        </div>
                        <!-- Honeypot -->
                        <div class="booking-hp">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-brand btn-lg w-100">
                            <i class="bi bi-send me-1"></i>Отправить заявку
                        </button>
                        <div class="form-result mt-3" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- What happens next -->
<section class="booking-steps">
    <div class="container">
        <h2 class="text-center mb-4">Что дальше?</h2>
        <div class="row">
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">1</div>
                    <h5>Рассмотрим заявку</h5>
                    <p>Наши менеджеры изучат вашу заявку и подготовят предложение.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">2</div>
                    <h5>Свяжемся с вами</h5>
                    <p>Перезвоним, чтобы обсудить условия и детали сотрудничества.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">3</div>
                    <h5>Добавим в каталог</h5>
                    <p>Ваш профиль появится в каталоге приглашений, и клиенты смогут вас найти.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <p class="mb-1"><a href="/booking/" class="text-light text-decoration-none opacity-75">Каталог приглашений</a></p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
