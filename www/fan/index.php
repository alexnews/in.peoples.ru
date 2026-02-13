<?php

declare(strict_types=1);

/**
 * Fan Club Index — /fan/
 *
 * Lists persons with fan clubs, sorted by number of confirmed fans.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

// Pagination
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 24;
$offset  = ($page - 1) * $perPage;

// Total fan clubs (persons with at least 1 member)
$countStmt = $db->query(
    'SELECT COUNT(DISTINCT person_id) FROM user_fan_club_members'
);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// Persons with fan clubs, ordered by confirmed fan count
$sql = "SELECT p.Persons_id, p.FullNameRus, p.FullNameEngl, p.NamePhoto,
               p.AllUrlInSity, p.famous_for, p.path, p.DateOut,
               COUNT(CASE WHEN fcm.status = 'confirmed' THEN 1 END) AS fan_count,
               COUNT(*) AS total_members
        FROM user_fan_club_members fcm
        INNER JOIN persons p ON p.Persons_id = fcm.person_id
        GROUP BY p.Persons_id
        ORDER BY fan_count DESC, total_members DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clubs = fromDbRows($stmt->fetchAll());

// Global stats
$statsStmt = $db->query(
    "SELECT COUNT(DISTINCT person_id) AS club_count,
            SUM(status = 'confirmed') AS total_fans
     FROM user_fan_club_members"
);
$stats = $statsStmt->fetch();

$pageTitle = 'Фан-клубы знаменитостей';
$pageDesc = 'Фан-клубы знаменитостей на peoples.ru. Вступите в фан-клуб любимой знаменитости — получайте новости и будьте частью сообщества.';
$canonicalUrl = 'https://in.peoples.ru/fan/';

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
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">

    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "CollectionPage",
        "name": <?= json_encode($pageTitle, JSON_UNESCAPED_UNICODE) ?>,
        "description": <?= json_encode($pageDesc, JSON_UNESCAPED_UNICODE) ?>,
        "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_UNICODE) ?>
    }
    </script>
</head>
<body>

<!-- Hero -->
<section class="fan-hero">
    <div class="container text-center">
        <h1 class="mb-3"><i class="bi bi-heart me-2"></i>Фан-клубы знаменитостей</h1>
        <p class="lead opacity-75 mb-0">Вступите в фан-клуб любимой знаменитости на peoples.ru</p>
        <?php if ((int)($stats['total_fans'] ?? 0) > 0): ?>
        <div class="d-flex justify-content-center gap-4 mt-3">
            <span class="fan-count-badge"><i class="bi bi-people me-1"></i><?= (int)$stats['club_count'] ?> фан-клубов</span>
            <span class="fan-count-badge"><i class="bi bi-heart-fill me-1"></i><?= (int)$stats['total_fans'] ?> участников</span>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- Breadcrumb -->
<div class="bg-light py-2">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="https://www.peoples.ru">peoples.ru</a></li>
                <li class="breadcrumb-item active">Фан-клубы</li>
            </ol>
        </nav>
    </div>
</div>

<!-- Fan clubs grid -->
<section class="py-5">
    <div class="container">
        <?php if (empty($clubs)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-heart" style="font-size: 4rem;"></i>
                <p class="mt-3 fs-5">Фан-клубы пока не созданы.</p>
                <p>Найдите любимую знаменитость на <a href="https://www.peoples.ru">peoples.ru</a> и создайте первый фан-клуб!</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($clubs as $club):
                    $clubSlug = $club['path'] ?? '';
                    $clubUrl = $clubSlug !== '' ? '/fan/' . $clubSlug . '/' : '/fan/' . (int)$club['Persons_id'] . '/';
                    $photo = $club['NamePhoto'] ?? '';
                    $profileUrl = $club['AllUrlInSity'] ?? '';
                    $name = $club['FullNameRus'] ?? '';
                    $nameEng = $club['FullNameEngl'] ?? '';
                    $isDeceased = !empty($club['DateOut']);
                    $fanCount = (int)($club['fan_count'] ?? 0);
                ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <a href="<?= htmlspecialchars($clubUrl, ENT_QUOTES, 'UTF-8') ?>" class="card card-hover text-decoration-none h-100">
                        <div class="text-center pt-3">
                            <?php if ($photo && $profileUrl): ?>
                                <img src="<?= htmlspecialchars($profileUrl . $photo, ENT_QUOTES, 'UTF-8') ?>"
                                     alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                     style="width: 120px; height: 150px; object-fit: cover; border-radius: 8px;">
                            <?php else: ?>
                                <div style="width: 120px; height: 150px; border-radius: 8px; background: #e9ecef; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-person" style="font-size: 3rem; color: #adb5bd;"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body text-center">
                            <h6 class="card-title mb-1"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></h6>
                            <?php if ($nameEng): ?>
                                <small class="text-muted d-block mb-2"><?= htmlspecialchars($nameEng, ENT_QUOTES, 'UTF-8') ?></small>
                            <?php endif; ?>
                            <span class="badge bg-danger"><i class="bi bi-heart-fill me-1"></i><?= $fanCount ?></span>
                            <?php if ($isDeceased): ?>
                                <span class="badge bg-secondary ms-1">in memoriam</span>
                            <?php endif; ?>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page - 1 ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?page=<?= $page + 1 ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Footer -->
<footer class="bg-dark text-light py-4 mt-auto">
    <div class="container text-center">
        <p class="mb-1"><a href="https://www.peoples.ru" class="text-light text-decoration-none">peoples.ru</a> — Энциклопедия знаменитостей</p>
        <p class="mb-1"><a href="/booking/" class="text-light text-decoration-none opacity-75">Приглашения</a></p>
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
