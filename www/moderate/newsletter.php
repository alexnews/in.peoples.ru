<?php

declare(strict_types=1);

/**
 * Newsletter Management — /moderate/newsletter.php
 *
 * Admin view of newsletter subscribers: list, stats, filtering.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('admin');
$db = getDb();

// --- Allowed sections map ---
$sectionNames = [
    4  => 'Новости',
    2  => 'Истории',
    8  => 'Мир фактов',
    7  => 'Песни',
    19 => 'Стихи',
    29 => 'Цитаты',
    31 => 'Анекдоты',
    13 => 'Интересное',
];

// --- Stats ---
$statsStmt = $db->query("
    SELECT
        COUNT(*) AS total,
        SUM(status = 'confirmed') AS confirmed,
        SUM(status = 'pending') AS pending,
        SUM(status = 'unsubscribed') AS unsubscribed
    FROM user_newsletter_subscribers
");
$stats = $statsStmt->fetch();

// --- Filters ---
$statusFilter = $_GET['status'] ?? '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

$validStatuses = ['pending', 'confirmed', 'unsubscribed'];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'ns.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Count ---
$countStmt = $db->prepare("SELECT COUNT(*) FROM user_newsletter_subscribers ns {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// --- Fetch subscribers ---
$sql = "SELECT ns.id, ns.email, ns.frequency, ns.status, ns.confirmed_at, ns.last_sent_at, ns.created_at
        FROM user_newsletter_subscribers ns
        {$whereClause}
        ORDER BY ns.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$subscribers = $stmt->fetchAll();

// --- Fetch sections for each subscriber ---
$subscriberIds = array_column($subscribers, 'id');
$sectionMap = [];
if (!empty($subscriberIds)) {
    $placeholders = implode(',', array_fill(0, count($subscriberIds), '?'));
    $secStmt = $db->prepare("SELECT subscriber_id, section_id FROM user_newsletter_sections WHERE subscriber_id IN ({$placeholders})");
    $secStmt->execute($subscriberIds);
    foreach ($secStmt->fetchAll() as $row) {
        $sectionMap[(int) $row['subscriber_id']][] = (int) $row['section_id'];
    }
}

$statusLabels = [
    'pending'      => 'Ожидает',
    'confirmed'    => 'Подтверждён',
    'unsubscribed' => 'Отписан',
];

$statusBadgeClass = [
    'pending'      => 'bg-warning text-dark',
    'confirmed'    => 'bg-success',
    'unsubscribed' => 'bg-secondary',
];

$freqLabels = [
    'daily'  => 'Ежедневно',
    'weekly' => 'Еженедельно',
];

$pageTitle = 'Рассылка';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Рассылка <span class="text-muted fs-6">(<?= $total ?>)</span></h4>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold brand-color"><?= (int) $stats['confirmed'] ?></div>
                <div class="small text-muted">Подтверждённых</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-warning"><?= (int) $stats['pending'] ?></div>
                <div class="small text-muted">Ожидают</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-secondary"><?= (int) $stats['unsubscribed'] ?></div>
                <div class="small text-muted">Отписались</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold"><?= (int) $stats['total'] ?></div>
                <div class="small text-muted">Всего</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/moderate/newsletter.php" class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="statusSelect" class="form-label small text-muted mb-0">Статус</label>
                <select name="status" id="statusSelect" class="form-select form-select-sm">
                    <option value="">Все статусы</option>
                    <?php foreach ($statusLabels as $sk => $sv): ?>
                    <option value="<?= $sk ?>" <?= $statusFilter === $sk ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sv, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-search me-1"></i>Найти
                </button>
            </div>
            <?php if ($statusFilter !== ''): ?>
            <div class="col-auto">
                <a href="/moderate/newsletter.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Subscribers Table -->
<?php if (empty($subscribers)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-envelope-x" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Подписчиков не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table queue-table mb-0">
            <thead>
                <tr>
                    <th>Email</th>
                    <th>Статус</th>
                    <th>Частота</th>
                    <th>Разделы</th>
                    <th>Подтверждён</th>
                    <th>Последняя отправка</th>
                    <th>Подписка</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subscribers as $sub): ?>
                <?php
                    $sid = (int) $sub['id'];
                    $email = fromDb($sub['email']);
                    $status = $sub['status'] ?? 'pending';
                    $freq = $sub['frequency'] ?? 'weekly';
                    $sections = $sectionMap[$sid] ?? [];
                ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($email ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge <?= $statusBadgeClass[$status] ?? 'bg-secondary' ?>">
                            <?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="small"><?= htmlspecialchars($freqLabels[$freq] ?? $freq, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="small">
                        <?php if (empty($sections)): ?>
                            <span class="text-muted">—</span>
                        <?php else: ?>
                            <?php foreach ($sections as $secId): ?>
                                <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($sectionNames[$secId] ?? "#{$secId}", ENT_QUOTES, 'UTF-8') ?></span>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap text-muted small">
                        <?= $sub['confirmed_at'] ? htmlspecialchars($sub['confirmed_at'], ENT_QUOTES, 'UTF-8') : '—' ?>
                    </td>
                    <td class="text-nowrap text-muted small">
                        <?= $sub['last_sent_at'] ? htmlspecialchars($sub['last_sent_at'], ENT_QUOTES, 'UTF-8') : '—' ?>
                    </td>
                    <td class="text-nowrap text-muted small">
                        <?= htmlspecialchars($sub['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter([
                    'status' => $statusFilter, 'page' => $page - 1
                ])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php
            $startPage = max(1, $page - 3);
            $endPage = min($totalPages, $page + 3);
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter([
                    'status' => $statusFilter, 'page' => $i
                ])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter([
                    'status' => $statusFilter, 'page' => $page + 1
                ])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
