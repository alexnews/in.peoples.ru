<?php

declare(strict_types=1);

/**
 * Booking Landing Page — /booking/
 *
 * Hero + search, category grid, featured persons, "how it works", general inquiry form.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

// Active categories with person counts
$catStmt = $db->query(
    'SELECT c.id, c.name, c.slug, c.description, c.icon,
            COUNT(bp.id) AS person_count
     FROM booking_categories c
     LEFT JOIN booking_persons bp ON bp.category_id = c.id AND bp.is_active = 1
     WHERE c.is_active = 1
     GROUP BY c.id
     ORDER BY c.sort_order ASC'
);
$categories = fromDbRows($catStmt->fetchAll());

// Featured persons (max 8)
$featStmt = $db->query(
    'SELECT bp.id AS booking_person_id, bp.person_id, bp.price_from, bp.price_to,
            bp.short_desc, bp.is_featured,
            p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
            p.Epigraph, p.famous_for,
            c.name AS category_name, c.slug AS category_slug
     FROM booking_persons bp
     INNER JOIN persons p ON p.Persons_id = bp.person_id
     INNER JOIN booking_categories c ON c.id = bp.category_id
     WHERE bp.is_active = 1 AND bp.is_featured = 1 AND c.is_active = 1 AND p.DateOut IS NULL
     ORDER BY bp.sort_order ASC, p.popularity DESC
     LIMIT 8'
);
$featured = fromDbRows($featStmt->fetchAll());

$pageTitle = 'Пригласить знаменитость на мероприятие';
$pageDesc = 'Пригласите знаменитость на мероприятие. Помогаем организовать встречи с известными людьми — в том числе с теми, кого незаслуженно забыли.';

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
<section class="booking-hero">
    <div class="container">
        <h1>Пригласить знаменитость на мероприятие</h1>
        <p>Помогаем организовать встречи со спортсменами, писателями, музыкантами и другими известными людьми. Мы вновь открываем публике незаслуженно забытых людей и поддерживаем память о них.</p>
        <div class="search-wrapper">
            <input type="text" id="heroPersonSearch" class="form-control" placeholder="Введите имя знаменитости..." autocomplete="off">
            <i class="bi bi-search search-icon"></i>
            <div id="heroSearchResults" class="search-results list-group" style="display:none;"></div>
        </div>
        <div class="mt-3">
            <a href="#inquiry-form" class="btn btn-brand btn-lg">
                <i class="bi bi-envelope me-1"></i>Оставить заявку
            </a>
        </div>
    </div>
</section>

<!-- Categories -->
<?php if (!empty($categories)): ?>
<section class="booking-categories py-5">
    <div class="container">
        <h2 class="text-center mb-4">Категории</h2>
        <div class="row g-3">
            <?php foreach ($categories as $cat): ?>
            <div class="col-6 col-md-3">
                <a href="/booking/category/<?= htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') ?>/" class="category-card">
                    <div class="cat-icon">
                        <i class="bi <?= htmlspecialchars($cat['icon'] ?? 'bi-star', ENT_QUOTES, 'UTF-8') ?>"></i>
                    </div>
                    <div class="cat-name"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="cat-count"><?= (int)$cat['person_count'] ?> человек</div>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Featured persons -->
<?php if (!empty($featured)): ?>
<section class="py-5 bg-light">
    <div class="container">
        <h2 class="text-center mb-4">Известные люди</h2>
        <div class="row g-4">
            <?php foreach ($featured as $fp): ?>
            <div class="col-6 col-md-3">
                <div class="booking-card">
                    <?php if (!empty($fp['NamePhoto'])): ?>
                        <img src="<?= htmlspecialchars(($fp['AllUrlInSity'] ?? '') . $fp['NamePhoto'], ENT_QUOTES, 'UTF-8') ?>"
                             class="card-img-top" alt="<?= htmlspecialchars($fp['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="card-img-placeholder"><i class="bi bi-person"></i></div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="person-name"><?= htmlspecialchars($fp['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="person-desc"><?= htmlspecialchars($fp['short_desc'] ?? $fp['famous_for'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="category-tag"><?= htmlspecialchars($fp['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($fp['price_from']): ?>
                        <div class="price-badge mt-2">
                            от <?= number_format((int)$fp['price_from'], 0, '', ' ') ?> &#8381;
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <?php if (!empty($fp['AllUrlInSity'])): ?>
                        <a href="<?= htmlspecialchars($fp['AllUrlInSity'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill" title="Профиль на peoples.ru">
                            <i class="bi bi-person-lines-fill me-1"></i>Профиль
                        </a>
                        <?php endif; ?>
                        <a href="/booking/person/<?= (int)$fp['person_id'] ?>/#booking-form" class="btn btn-sm btn-brand flex-fill">Пригласить</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- How it works -->
<section class="booking-steps">
    <div class="container">
        <h2 class="text-center mb-4">Как это работает</h2>
        <div class="row">
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">1</div>
                    <h5>Найдите человека</h5>
                    <p>Воспользуйтесь поиском или каталогом по категориям.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">2</div>
                    <h5>Оставьте заявку</h5>
                    <p>Заполните форму с деталями мероприятия и контактными данными.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="step">
                    <div class="step-number">3</div>
                    <h5>Мы организуем</h5>
                    <p>Наш менеджер свяжется с вами и поможет организовать встречу.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- General inquiry form -->
<section class="py-5" id="inquiry-form">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <h2 class="text-center mb-4">Оставить заявку</h2>
                <p class="text-center text-muted mb-4">Не нашли нужного человека? Опишите, кого вы ищете, и мы постараемся помочь.</p>
                <div class="booking-form">
                    <form class="booking-request-form">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ваше имя <span class="text-danger">*</span></label>
                                <input type="text" name="client_name" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Телефон <span class="text-danger">*</span></label>
                                <input type="tel" name="client_phone" class="form-control" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="client_email" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Тип мероприятия</label>
                                <select name="event_type" class="form-select">
                                    <option value="">— Выберите —</option>
                                    <option value="corporate">Корпоратив</option>
                                    <option value="wedding">Свадьба</option>
                                    <option value="birthday">День рождения</option>
                                    <option value="concert">Концерт</option>
                                    <option value="private">Частная вечеринка</option>
                                    <option value="city_event">Городское мероприятие</option>
                                    <option value="charity">Благотворительное мероприятие</option>
                                    <option value="opening">Открытие / презентация</option>
                                    <option value="other">Другое</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Дата мероприятия</label>
                                <input type="date" name="event_date" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Город</label>
                                <input type="text" name="event_city" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Сообщение</label>
                            <textarea name="message" class="form-control" rows="3" placeholder="Опишите, с кем вы хотите встретиться и детали мероприятия..."></textarea>
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

<!-- CTA for artists -->
<section class="booking-register-cta">
    <div class="container text-center">
        <h2>Вы — известный человек?</h2>
        <p>Если вы спортсмен, писатель, музыкант, актёр или другая известная личность — оставьте заявку, и мы добавим вас в каталог.</p>
        <a href="/booking/register/" class="btn btn-brand btn-lg">
            <i class="bi bi-person-plus me-1"></i>Оставить заявку
        </a>
    </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-5">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
