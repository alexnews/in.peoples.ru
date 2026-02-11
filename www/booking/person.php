<?php

declare(strict_types=1);

/**
 * Individual Booking Page — /booking/person/{id}/
 *
 * Person info, price, full inquiry form, similar artists, JSON-LD.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

$personId = (int) ($_GET['id'] ?? 0);
if ($personId <= 0) {
    http_response_code(404);
    echo '404 — Не найдено';
    exit;
}

// Fetch booking person(s) — may be in multiple categories
$bpStmt = $db->prepare(
    'SELECT bp.id AS booking_person_id, bp.category_id, bp.price_from, bp.price_to,
            bp.description, bp.short_desc,
            c.name AS category_name, c.slug AS category_slug
     FROM booking_persons bp
     INNER JOIN booking_categories c ON c.id = bp.category_id
     WHERE bp.person_id = :pid AND bp.is_active = 1 AND c.is_active = 1
     ORDER BY bp.sort_order ASC
     LIMIT 5'
);
$bpStmt->execute([':pid' => $personId]);
$bookingEntries = fromDbRows($bpStmt->fetchAll());

// Fetch person from main table regardless (to show even if not in booking_persons yet)
$pStmt = $db->prepare(
    'SELECT Persons_id, FullNameRus, FullNameEngl, NamePhoto, AllUrlInSity,
            DateIn, DateOut, Epigraph, famous_for, gender
     FROM persons
     WHERE Persons_id = :pid'
);
$pStmt->execute([':pid' => $personId]);
$person = $pStmt->fetch();

if (!$person) {
    http_response_code(404);
    echo '404 — Не найдено';
    exit;
}
$person = fromDbArray($person);

// Use first booking entry as primary
$primary = !empty($bookingEntries) ? $bookingEntries[0] : null;
$bookingPersonId = $primary ? (int)$primary['booking_person_id'] : null;
$priceFrom = $primary ? $primary['price_from'] : null;
$priceTo = $primary ? $primary['price_to'] : null;
$bookingDesc = $primary ? $primary['description'] : null;
$categoryName = $primary ? $primary['category_name'] : null;
$categorySlug = $primary ? $primary['category_slug'] : null;

$personName = $person['FullNameRus'] ?? '';
$personNameEng = $person['FullNameEngl'] ?? '';
$personPhoto = $person['NamePhoto'] ?? '';
$personPath = $person['AllUrlInSity'] ?? '';
$personEpigraph = $person['Epigraph'] ?? '';
$famousFor = $person['famous_for'] ?? '';
$isDeceased = !empty($person['DateOut']);

// Calculate age
$age = '';
if (!empty($person['DateIn'])) {
    try {
        $birthDate = new DateTime($person['DateIn']);
        $now = new DateTime();
        $diff = $now->diff($birthDate);
        $age = $diff->y;
    } catch (Exception $e) {
        $age = '';
    }
}

$pageTitle = "Пригласить {$personName} на мероприятие";
$pageDesc = $bookingDesc
    ? mb_substr(strip_tags($bookingDesc), 0, 160, 'UTF-8')
    : "Пригласить {$personName} на мероприятие. peoples.ru";

// Similar artists (same category)
$similar = [];
if ($primary) {
    $simStmt = $db->prepare(
        'SELECT bp.person_id, bp.price_from,
                p.FullNameRus, p.NamePhoto, p.AllUrlInSity, p.famous_for,
                c.name AS category_name
         FROM booking_persons bp
         INNER JOIN persons p ON p.Persons_id = bp.person_id
         INNER JOIN booking_categories c ON c.id = bp.category_id
         WHERE bp.category_id = :cid AND bp.person_id != :pid
               AND bp.is_active = 1 AND p.DateOut IS NULL
         ORDER BY bp.is_featured DESC, RAND()
         LIMIT 4'
    );
    $simStmt->execute([':cid' => (int)$primary['category_id'], ':pid' => $personId]);
    $similar = fromDbRows($simStmt->fetchAll());
}

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

    <!-- JSON-LD Structured Data -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Service",
        "name": <?= json_encode("Пригласить: {$personName}", JSON_UNESCAPED_UNICODE) ?>,
        "description": <?= json_encode($pageDesc, JSON_UNESCAPED_UNICODE) ?>,
        "provider": {
            "@type": "Organization",
            "name": "peoples.ru",
            "url": "https://www.peoples.ru"
        }
        <?php if ($priceFrom): ?>
        ,"offers": {
            "@type": "Offer",
            "priceCurrency": "RUB",
            "price": "<?= (int)$priceFrom ?>",
            "availability": "https://schema.org/InStock"
        }
        <?php endif; ?>
    }
    </script>
</head>
<body>

<!-- Breadcrumb -->
<div class="bg-light py-2">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="https://www.peoples.ru">peoples.ru</a></li>
                <li class="breadcrumb-item"><a href="/booking/">Приглашения</a></li>
                <?php if ($categorySlug): ?>
                <li class="breadcrumb-item"><a href="/booking/category/<?= htmlspecialchars($categorySlug, ENT_QUOTES, 'UTF-8') ?>/"><?= htmlspecialchars($categoryName, ENT_QUOTES, 'UTF-8') ?></a></li>
                <?php endif; ?>
                <li class="breadcrumb-item active"><?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?></li>
            </ol>
        </nav>
    </div>
</div>

<!-- Person hero -->
<section class="booking-person-hero">
    <div class="container">
        <div class="row align-items-center">
            <div class="col-auto">
                <?php if (!empty($personPhoto)): ?>
                    <img src="<?= htmlspecialchars($personPath . $personPhoto, ENT_QUOTES, 'UTF-8') ?>"
                         class="person-photo" alt="<?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?>">
                <?php else: ?>
                    <div class="person-photo-placeholder"><i class="bi bi-person"></i></div>
                <?php endif; ?>
            </div>
            <div class="col">
                <h1 class="mb-2"><?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($personNameEng): ?>
                    <p class="text-muted mb-1"><?= htmlspecialchars($personNameEng, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php if (!empty($bookingEntries)): ?>
                <div class="mb-2">
                    <?php foreach ($bookingEntries as $be): ?>
                        <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($be['category_name'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($personEpigraph): ?>
                    <p class="text-muted fst-italic"><?= htmlspecialchars($personEpigraph, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php if ($isDeceased): ?>
                <div class="alert alert-secondary mt-2 mb-0 py-2 d-inline-block">
                    <i class="bi bi-info-circle me-1"></i>К сожалению, этот человек ушёл из жизни. Приглашение невозможно.
                </div>
                <?php else: ?>
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <?php if ($age): ?>
                        <span class="text-muted"><i class="bi bi-calendar3 me-1"></i><?= $age ?> лет</span>
                    <?php endif; ?>
                    <?php if ($priceFrom): ?>
                        <div class="price-display">
                            от <?= number_format((int)$priceFrom, 0, '', ' ') ?> &#8381;
                            <?php if ($priceTo): ?>
                                <small>до <?= number_format((int)$priceTo, 0, '', ' ') ?> &#8381;</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="mt-3 d-flex gap-2">
                    <?php if (!$isDeceased): ?>
                    <a href="#booking-form" class="btn btn-brand">
                        <i class="bi bi-envelope me-1"></i>Пригласить
                    </a>
                    <?php endif; ?>
                    <?php if ($personPath): ?>
                    <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-secondary" target="_blank" rel="noopener">
                        <i class="bi bi-person-lines-fill me-1"></i>Профиль на peoples.ru
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Description -->
<?php if ($bookingDesc): ?>
<section class="py-4">
    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <h2 class="h4 mb-3">О выступлении</h2>
                <div class="mb-4"><?= nl2br(htmlspecialchars($bookingDesc, ENT_QUOTES, 'UTF-8')) ?></div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php if (!$isDeceased): ?>
<!-- Booking form -->
<section class="py-5 bg-light" id="booking-form">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <h2 class="text-center mb-4">Позвать <?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="booking-form">
                    <form class="booking-request-form">
                        <input type="hidden" name="person_id" value="<?= $personId ?>">
                        <?php if ($bookingPersonId): ?>
                        <input type="hidden" name="booking_person_id" value="<?= $bookingPersonId ?>">
                        <?php endif; ?>

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
                                <label class="form-label">Компания</label>
                                <input type="text" name="client_company" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Тип мероприятия</label>
                                <select name="event_type" class="form-select">
                                    <option value="">— Выберите —</option>
                                    <option value="corporate">Корпоратив</option>
                                    <option value="wedding">Свадьба</option>
                                    <option value="birthday">День рождения</option>
                                    <option value="concert">Концерт</option>
                                    <option value="private">Частная вечеринка</option>
                                    <option value="city_event">Городское мероприятие</option>
                                    <option value="charity">Благотворительное</option>
                                    <option value="opening">Открытие / презентация</option>
                                    <option value="other">Другое</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Дата мероприятия</label>
                                <input type="date" name="event_date" class="form-control">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Город</label>
                                <input type="text" name="event_city" class="form-control">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Площадка</label>
                                <input type="text" name="event_venue" class="form-control" placeholder="Ресторан, отель, зал...">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Кол-во гостей</label>
                                <input type="number" name="guest_count" class="form-control" min="1">
                            </div>
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Бюджет от (&#8381;)</label>
                                <input type="number" name="budget_from" class="form-control" min="0">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Сообщение</label>
                            <textarea name="message" class="form-control" rows="3" placeholder="Дополнительные пожелания..."></textarea>
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
<?php endif; ?>

<!-- Similar persons -->
<?php if (!empty($similar)): ?>
<section class="py-5">
    <div class="container">
        <h2 class="h4 mb-4">Похожие знаменитости</h2>
        <div class="row g-4">
            <?php foreach ($similar as $s): ?>
            <div class="col-6 col-md-3">
                <div class="booking-card">
                    <?php if (!empty($s['NamePhoto'])): ?>
                        <img src="<?= htmlspecialchars(($s['AllUrlInSity'] ?? '') . $s['NamePhoto'], ENT_QUOTES, 'UTF-8') ?>"
                             class="card-img-top" alt="<?= htmlspecialchars($s['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?php else: ?>
                        <div class="card-img-placeholder"><i class="bi bi-person"></i></div>
                    <?php endif; ?>
                    <div class="card-body">
                        <div class="person-name"><?= htmlspecialchars($s['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="person-desc"><?= htmlspecialchars($s['famous_for'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($s['price_from']): ?>
                        <div class="price-badge">от <?= number_format((int)$s['price_from'], 0, '', ' ') ?> &#8381;</div>
                        <?php endif; ?>
                    </div>
                    <div class="card-footer d-flex gap-2">
                        <?php if (!empty($s['AllUrlInSity'])): ?>
                        <a href="<?= htmlspecialchars($s['AllUrlInSity'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm btn-outline-secondary flex-fill" title="Профиль на peoples.ru">
                            <i class="bi bi-person-lines-fill me-1"></i>Профиль
                        </a>
                        <?php endif; ?>
                        <a href="/booking/person/<?= (int)$s['person_id'] ?>/" class="btn btn-sm btn-brand flex-fill">Пригласить</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-auto">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
