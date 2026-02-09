<?php
/**
 * User Dashboard — requires auth.
 *
 * Shows welcome message, stats cards, quick actions, and recent submissions.
 */

declare(strict_types=1);

$pageTitle = 'Личный кабинет';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = (int) $currentUser['id'];

// Fetch submission stats by status
$stmt = $db->prepare(
    'SELECT status, COUNT(*) AS cnt
     FROM user_submissions
     WHERE user_id = :uid
     GROUP BY status'
);
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll();

$stats = [
    'total'              => 0,
    'pending'            => 0,
    'approved'           => 0,
    'draft'              => 0,
    'rejected'           => 0,
    'revision_requested' => 0,
];
foreach ($rows as $row) {
    $stats[$row['status']] = (int) $row['cnt'];
    $stats['total'] += (int) $row['cnt'];
}

// Fetch recent 5 submissions
$stmt = $db->prepare(
    'SELECT s.id, s.section_id, s.title, s.status, s.created_at,
            p.FullNameRus AS person_name,
            ps.nameRus AS section_name
     FROM user_submissions s
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.user_id = :uid
     ORDER BY s.created_at DESC
     LIMIT 5'
);
$stmt->execute([':uid' => $userId]);
$recentSubmissions = fromDbRows($stmt->fetchAll());

// Section icons
$sectionIcons = [
    2 => 'bi-book',
    3 => 'bi-camera',
    4 => 'bi-newspaper',
    5 => 'bi-chat-quote',
    7 => 'bi-lightbulb',
    8 => 'bi-star',
    19 => 'bi-file-text',
];

// Status display
$statusLabels = [
    'draft'              => 'Черновик',
    'pending'            => 'На проверке',
    'approved'           => 'Опубликовано',
    'rejected'           => 'Отклонено',
    'revision_requested' => 'На доработку',
];
$statusBadge = [
    'draft'              => 'badge-draft',
    'pending'            => 'badge-pending',
    'approved'           => 'badge-approved',
    'rejected'           => 'badge-rejected',
    'revision_requested' => 'badge-revision',
];
?>

<h4 class="mb-4">
    <i class="bi bi-house-door me-2"></i>
    Добро пожаловать, <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>!
</h4>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card card-hover">
            <div class="stat-value"><?= $stats['total'] ?></div>
            <div class="stat-label">Всего материалов</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card card-hover">
            <div class="stat-value text-warning"><?= $stats['pending'] ?></div>
            <div class="stat-label">На проверке</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card card-hover">
            <div class="stat-value text-success"><?= $stats['approved'] ?></div>
            <div class="stat-label">Опубликовано</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card card-hover">
            <div class="stat-value" style="color:var(--primary)"><?= (int) ($currentUser['reputation'] ?? 0) ?></div>
            <div class="stat-label">Репутация</div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<h5 class="mb-3"><i class="bi bi-lightning me-1"></i>Быстрые действия</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=2" class="quick-action">
            <i class="bi bi-book"></i>
            <span>Написать биографию</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=4" class="quick-action">
            <i class="bi bi-newspaper"></i>
            <span>Добавить новость</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=3" class="quick-action">
            <i class="bi bi-camera"></i>
            <span>Загрузить фото</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=7" class="quick-action">
            <i class="bi bi-lightbulb"></i>
            <span>Добавить факт</span>
        </a>
    </div>
</div>

<!-- Recent Submissions -->
<h5 class="mb-3"><i class="bi bi-clock-history me-1"></i>Последние материалы</h5>
<?php if (empty($recentSubmissions)): ?>
<div class="card">
    <div class="card-body text-center text-muted py-4">
        <i class="bi bi-inbox fs-1 d-block mb-2"></i>
        У вас пока нет материалов.
        <a href="/user/submit.php" class="d-block mt-2">Добавить первый</a>
    </div>
</div>
<?php else: ?>
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px"></th>
                    <th>Название</th>
                    <th>Персона</th>
                    <th>Статус</th>
                    <th>Дата</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentSubmissions as $sub): ?>
                <tr>
                    <td>
                        <span class="section-icon">
                            <i class="bi <?= $sectionIcons[(int)$sub['section_id']] ?? 'bi-file-text' ?>"></i>
                        </span>
                    </td>
                    <td>
                        <a href="/user/view.php?id=<?= (int)$sub['id'] ?>">
                            <?= htmlspecialchars($sub['title'] ?: 'Без названия', ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </td>
                    <td class="text-muted">
                        <?= htmlspecialchars($sub['person_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <span class="badge <?= $statusBadge[$sub['status']] ?? 'badge-draft' ?>">
                            <?= $statusLabels[$sub['status']] ?? $sub['status'] ?>
                        </span>
                    </td>
                    <td class="text-muted small">
                        <?= $sub['created_at'] ? date('d.m.Y', strtotime($sub['created_at'])) : '' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($stats['total'] > 5): ?>
    <div class="card-footer text-center">
        <a href="/user/submissions.php" class="text-decoration-none">
            Все материалы <i class="bi bi-arrow-right"></i>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
