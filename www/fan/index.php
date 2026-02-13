<?php

declare(strict_types=1);

/**
 * Fan Club Landing Page — /fan/{slug}/
 *
 * Rich person page with content links, fan signup form, SEO tags.
 * Works for both living and deceased persons.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

// Accept slug (path-based or numeric ID)
$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    http_response_code(404);
    echo '404 — Не найдено';
    exit;
}

// Resolve person ID: numeric → lookup by Persons_id, string → lookup by persons.path
if (ctype_digit($slug)) {
    $personId = (int) $slug;
} else {
    $pathStmt = $db->prepare('SELECT Persons_id FROM persons WHERE path = :path LIMIT 1');
    $pathStmt->execute([':path' => toDb($slug)]);
    $personId = (int) $pathStmt->fetchColumn();
    if ($personId <= 0) {
        http_response_code(404);
        echo '404 — Не найдено';
        exit;
    }
}

// Fetch person
$pStmt = $db->prepare(
    'SELECT Persons_id, FullNameRus, FullNameEngl, NamePhoto, AllUrlInSity,
            DateIn, DateOut, Epigraph, famous_for, gender, path
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

// 301 redirect from numeric ID to path-based URL
$personSlug = $person['path'] ?? '';
if ($personSlug !== '' && ctype_digit($slug)) {
    header('Location: /fan/' . $personSlug . '/', true, 301);
    exit;
}

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

// Fan count
$fanStmt = $db->prepare(
    "SELECT COUNT(*) FROM user_fan_club_members WHERE person_id = :pid AND status = 'confirmed'"
);
$fanStmt->execute([':pid' => $personId]);
$fanCount = (int) $fanStmt->fetchColumn();

// Check if person is in booking system
$bookingStmt = $db->prepare(
    'SELECT 1 FROM booking_persons WHERE person_id = :pid AND is_active = 1 LIMIT 1'
);
$bookingStmt->execute([':pid' => $personId]);
$isInBooking = (bool) $bookingStmt->fetch();

$fanClubTitle = $isDeceased ? "Фан-клуб памяти: {$personName}" : "Фан-клуб: {$personName}";
$pageTitle = $fanClubTitle;
$pageDesc = $famousFor
    ? "{$fanClubTitle}. {$famousFor}. peoples.ru"
    : "{$fanClubTitle}. Вступите в фан-клуб на peoples.ru";
$canonicalSlug = $personSlug !== '' ? $personSlug : (string)$personId;
$canonicalUrl = 'https://in.peoples.ru/fan/' . $canonicalSlug . '/';
$ogImage = !empty($personPhoto) ? ($personPath . $personPhoto) : 'https://www.peoples.ru/img/og-default.jpg';

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
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/booking.css" rel="stylesheet">

    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>">
    <meta property="og:site_name" content="peoples.ru">
    <meta property="og:locale" content="ru_RU">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "Person",
        "name": <?= json_encode($personName, JSON_UNESCAPED_UNICODE) ?>,
        "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_UNICODE) ?>
        <?php if (!empty($personPhoto)): ?>
        ,"image": <?= json_encode($personPath . $personPhoto, JSON_UNESCAPED_UNICODE) ?>
        <?php endif; ?>
        <?php if ($famousFor): ?>
        ,"description": <?= json_encode($famousFor, JSON_UNESCAPED_UNICODE) ?>
        <?php endif; ?>
    }
    </script>
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "BreadcrumbList",
        "itemListElement": [
            {"@type": "ListItem", "position": 1, "name": "peoples.ru", "item": "https://www.peoples.ru"},
            {"@type": "ListItem", "position": 2, "name": "Фан-клуб", "item": "https://in.peoples.ru/fan/"},
            {"@type": "ListItem", "position": 3, "name": <?= json_encode($personName, JSON_UNESCAPED_UNICODE) ?>, "item": <?= json_encode($canonicalUrl, JSON_UNESCAPED_UNICODE) ?>}
        ]
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
                <li class="breadcrumb-item active"><?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?></li>
            </ol>
        </nav>
    </div>
</div>

<!-- Hero -->
<section class="fan-hero">
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
                <h1 class="mb-2"><?= htmlspecialchars($fanClubTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if ($personNameEng): ?>
                    <p class="text-light mb-1 opacity-75"><?= htmlspecialchars($personNameEng, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php if ($personEpigraph): ?>
                    <p class="fst-italic opacity-75"><?= htmlspecialchars($personEpigraph, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php if ($famousFor): ?>
                    <p class="mb-2"><?= htmlspecialchars($famousFor, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
                    <?php if ($age): ?>
                        <span><i class="bi bi-calendar3 me-1"></i><?= $age ?> лет</span>
                    <?php endif; ?>
                    <span class="fan-count-badge">
                        <i class="bi bi-people me-1"></i><?= $fanCount ?> <?= $fanCount === 1 ? 'участник' : ($fanCount >= 2 && $fanCount <= 4 ? 'участника' : 'участников') ?>
                    </span>
                </div>

                <div class="d-flex gap-2 flex-wrap">
                    <a href="#fan-form" class="btn btn-brand">
                        <i class="bi bi-heart me-1"></i>Вступить в фан-клуб
                    </a>
                    <?php if ($personPath): ?>
                    <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>"
                       class="btn btn-outline-light" target="_blank" rel="noopener">
                        <i class="bi bi-person-lines-fill me-1"></i>Профиль на peoples.ru
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Content links -->
<section class="py-5">
    <div class="container">
        <h2 class="text-center mb-4">Материалы о <?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?></h2>
        <div class="row g-3">
            <?php if ($personPath): ?>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-book"></i></div>
                    <div class="cat-name">Биография</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>photo/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-camera"></i></div>
                    <div class="cat-name">Фотографии</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>news/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-newspaper"></i></div>
                    <div class="cat-name">Новости</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>quotes/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-chat-quote"></i></div>
                    <div class="cat-name">Цитаты</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>poetry/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-feather"></i></div>
                    <div class="cat-name">Стихи</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>songs/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-music-note-beamed"></i></div>
                    <div class="cat-name">Песни</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>video/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-play-circle"></i></div>
                    <div class="cat-name">Видео</div>
                </a>
            </div>
            <div class="col-6 col-md-3">
                <a href="<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>facts/" class="category-card" target="_blank" rel="noopener">
                    <div class="cat-icon"><i class="bi bi-lightbulb"></i></div>
                    <div class="cat-name">Факты</div>
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Fan signup form -->
<section class="py-5 bg-light" id="fan-form">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-6">
                <h2 class="text-center mb-4">Вступить в фан-клуб</h2>
                <p class="text-center text-muted mb-4">Получайте новости о <?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?> и станьте частью сообщества поклонников.</p>
                <div class="booking-form">
                    <form class="fan-join-form">
                        <input type="hidden" name="person_id" value="<?= $personId ?>">
                        <div class="mb-3">
                            <label class="form-label">Ваше имя <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required>
                        </div>
                        <div class="mb-4">
                            <label class="form-label">Сообщение (необязательно)</label>
                            <textarea name="message" class="form-control" rows="3"
                                      placeholder="Почему вы являетесь поклонником..."></textarea>
                        </div>
                        <!-- Honeypot -->
                        <div class="booking-hp">
                            <input type="text" name="website" tabindex="-1" autocomplete="off">
                        </div>
                        <button type="submit" class="btn btn-brand btn-lg w-100">
                            <i class="bi bi-heart me-1"></i>Вступить
                        </button>
                        <div class="form-result mt-3" style="display:none;"></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<?php if (!$isDeceased && $isInBooking): ?>
<!-- Booking CTA -->
<section class="booking-register-cta">
    <div class="container text-center">
        <h2>Хотите пригласить <?= htmlspecialchars($personName, ENT_QUOTES, 'UTF-8') ?>?</h2>
        <p>Оставьте заявку на приглашение через наш сервис.</p>
        <a href="/booking/person/<?= htmlspecialchars($canonicalSlug, ENT_QUOTES, 'UTF-8') ?>/" class="btn btn-brand btn-lg">
            <i class="bi bi-envelope me-1"></i>Пригласить
        </a>
    </div>
</section>
<?php endif; ?>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-auto">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <p class="mb-1"><a href="/booking/" class="text-light text-decoration-none opacity-75">Приглашения</a></p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="/assets/js/booking.js"></script>
</body>
</html>
