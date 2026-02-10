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

// Fetch newsletter subscription by user email
$userEmail = toDb($currentUser['email'] ?? '');
$nlStmt = $db->prepare(
    'SELECT ns.id, ns.frequency, ns.status, ns.unsubscribe_token
     FROM user_newsletter_subscribers ns
     WHERE ns.email = :email
     LIMIT 1'
);
$nlStmt->execute([':email' => $userEmail]);
$newsletter = $nlStmt->fetch();

$nlSections = [];
if ($newsletter) {
    $nlSecStmt = $db->prepare(
        'SELECT sec.section_id FROM user_newsletter_sections sec WHERE sec.subscriber_id = :sid'
    );
    $nlSecStmt->execute([':sid' => $newsletter['id']]);
    $nlSections = $nlSecStmt->fetchAll(PDO::FETCH_COLUMN);
}

$nlSectionNames = [
    4  => 'Новости',
    2  => 'Истории',
    8  => 'Мир фактов',
    7  => 'Песни',
    19 => 'Стихи',
    29 => 'Цитаты',
    31 => 'Анекдоты',
    13 => 'Интересное',
];

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

<!-- Newsletter Subscription -->
<h5 class="mb-3"><i class="bi bi-envelope me-1"></i>Рассылка</h5>
<?php if ($newsletter && $newsletter['status'] === 'confirmed'): ?>
<div class="card mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="badge bg-success me-2">Подписка активна</span>
            <span class="text-muted small">
                <?= $newsletter['frequency'] === 'daily' ? 'Ежедневно' : 'Еженедельно' ?> &middot;
                <?php
                $names = [];
                foreach ($nlSections as $sid) {
                    if (isset($nlSectionNames[(int)$sid])) {
                        $names[] = $nlSectionNames[(int)$sid];
                    }
                }
                echo htmlspecialchars(implode(', ', $names), ENT_QUOTES, 'UTF-8');
                ?>
            </span>
        </div>
        <a href="/newsletter.php?token=<?= urlencode($newsletter['unsubscribe_token']) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-gear me-1"></i>Настроить
        </a>
    </div>
</div>
<?php elseif ($newsletter && $newsletter['status'] === 'pending'): ?>
<div class="card mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <span class="badge bg-warning text-dark me-2">Ожидает подтверждения</span>
            <span class="text-muted small">Проверьте почту для подтверждения подписки</span>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card mb-4">
    <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-muted">Вы не подписаны на рассылку peoples.ru</div>
        <a href="/newsletter.php" class="btn btn-sm btn-brand">
            <i class="bi bi-envelope-check me-1"></i>Подписаться
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Add Content — Section Cards -->
<h5 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Добавить материал</h5>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=2" class="quick-action">
            <i class="bi bi-file-text"></i>
            <span>Статьи</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=3" class="quick-action">
            <i class="bi bi-camera"></i>
            <span>Фотографии</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=4" class="quick-action">
            <i class="bi bi-newspaper"></i>
            <span>Новости</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=5" class="quick-action">
            <i class="bi bi-chat-dots"></i>
            <span>Форум</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=7" class="quick-action">
            <i class="bi bi-music-note-beamed"></i>
            <span>Песни</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=8" class="quick-action">
            <i class="bi bi-lightbulb"></i>
            <span>Факты</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/submit.php?section=19" class="quick-action">
            <i class="bi bi-pen"></i>
            <span>Стихи</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="/user/suggest-person.php" class="quick-action" style="border-style:dashed;">
            <i class="bi bi-person-plus"></i>
            <span>Предложить персону</span>
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
