<?php

declare(strict_types=1);

/**
 * Booking Category Page — /booking/category/{slug}/
 *
 * Shows filtered person grid with pagination for a specific category.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';

$db = getDb();

$slug = trim($_GET['slug'] ?? '');
if ($slug === '' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(404);
    echo '404 — Категория не найдена';
    exit;
}

// Fetch category
$catStmt = $db->prepare(
    'SELECT id, name, slug, description, icon FROM booking_categories WHERE slug = :slug AND is_active = 1'
);
$catStmt->execute([':slug' => $slug]);
$category = $catStmt->fetch();

if (!$category) {
    http_response_code(404);
    echo '404 — Категория не найдена';
    exit;
}
$category = fromDbArray($category);

// Pagination
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

// Count
$countStmt = $db->prepare(
    'SELECT COUNT(*)
     FROM booking_persons bp
     INNER JOIN persons p ON p.Persons_id = bp.person_id
     WHERE bp.category_id = :cid AND bp.is_active = 1 AND p.DateOut IS NULL'
);
$countStmt->execute([':cid' => (int)$category['id']]);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// Fetch persons
$sql = 'SELECT bp.id AS booking_person_id, bp.person_id, bp.price_from, bp.price_to,
               bp.short_desc, bp.is_featured,
               p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
               p.famous_for
        FROM booking_persons bp
        INNER JOIN persons p ON p.Persons_id = bp.person_id
        WHERE bp.category_id = :cid AND bp.is_active = 1 AND p.DateOut IS NULL
        ORDER BY bp.is_featured DESC, bp.sort_order ASC, p.popularity DESC
        LIMIT :limit OFFSET :offset';

$stmt = $db->prepare($sql);
$stmt->bindValue(':cid', (int)$category['id'], PDO::PARAM_INT);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$persons = fromDbRows($stmt->fetchAll());

$catName = $category['name'];
$pageTitle = "Пригласить — {$catName}";
$pageDesc = $category['description'] ?? "Закажите выступление: {$catName} на мероприятии. peoples.ru";

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

<!-- Breadcrumb -->
<div class="bg-light py-2">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 small">
                <li class="breadcrumb-item"><a href="https://www.peoples.ru">peoples.ru</a></li>
                <li class="breadcrumb-item"><a href="/booking/">Букинг</a></li>
                <li class="breadcrumb-item active"><?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?></li>
            </ol>
        </nav>
    </div>
</div>

<!-- Category header -->
<section class="py-4">
    <div class="container">
        <div class="d-flex align-items-center gap-3 mb-3">
            <?php if (!empty($category['icon'])): ?>
                <i class="bi <?= htmlspecialchars($category['icon'], ENT_QUOTES, 'UTF-8') ?> fs-2 brand-red"></i>
            <?php endif; ?>
            <div>
                <h1 class="mb-0"><?= htmlspecialchars($catName, ENT_QUOTES, 'UTF-8') ?></h1>
                <?php if (!empty($category['description'])): ?>
                    <p class="text-muted mb-0"><?= htmlspecialchars($category['description'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            </div>
            <span class="badge bg-light text-dark fs-6 ms-auto"><?= $total ?> человек</span>
        </div>
    </div>
</section>

<!-- Person grid -->
<section class="pb-5">
    <div class="container">
        <?php if (empty($persons)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-people" style="font-size: 3rem;"></i>
                <p class="mt-3">В этой категории пока никого нет.</p>
                <a href="/booking/" class="btn btn-outline-secondary">Вернуться к каталогу</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach ($persons as $p): ?>
                <div class="col-6 col-md-4">
                    <div class="booking-card">
                        <?php if (!empty($p['NamePhoto'])): ?>
                            <img src="<?= htmlspecialchars(($p['AllUrlInSity'] ?? '') . $p['NamePhoto'], ENT_QUOTES, 'UTF-8') ?>"
                                 class="card-img-top" alt="<?= htmlspecialchars($p['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <?php else: ?>
                            <div class="card-img-placeholder"><i class="bi bi-person"></i></div>
                        <?php endif; ?>
                        <div class="card-body">
                            <div class="person-name"><?= htmlspecialchars($p['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="person-desc"><?= htmlspecialchars($p['short_desc'] ?? $p['famous_for'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($p['price_from']): ?>
                            <div class="price-badge">
                                от <?= number_format((int)$p['price_from'], 0, '', ' ') ?> &#8381;
                                <?php if ($p['price_to']): ?>
                                    до <?= number_format((int)$p['price_to'], 0, '', ' ') ?> &#8381;
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer d-flex gap-2">
                            <a href="/booking/person/<?= (int)$p['person_id'] ?>/" class="btn btn-sm btn-outline-secondary flex-fill">Подробнее</a>
                            <a href="/booking/person/<?= (int)$p['person_id'] ?>/#booking-form" class="btn btn-sm btn-brand flex-fill">Заказать</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(['slug' => $slug, 'page' => $page - 1]) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                    <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(['slug' => $slug, 'page' => $i]) ?>">
                            <?= $i ?>
                        </a>
                    </li>
                    <?php endfor; ?>
                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(['slug' => $slug, 'page' => $page + 1]) ?>">
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
        <small class="text-muted">&copy; <?= date('Y') ?> peoples.ru</small>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
