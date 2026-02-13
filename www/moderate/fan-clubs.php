<?php

declare(strict_types=1);

/**
 * Fan Club Members Management — /moderate/fan-clubs.php
 *
 * Admin: view fan club signups, filter by status, see stats.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('admin');
$db = getDb();

// --- Stats ---
$statsStmt = $db->query(
    "SELECT
        COUNT(*) AS total,
        SUM(status = 'pending') AS pending_count,
        SUM(status = 'confirmed') AS confirmed_count,
        SUM(status = 'unsubscribed') AS unsubscribed_count
    FROM user_fan_club_members"
);
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
    $where[] = 'fcm.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Count ---
$countStmt = $db->prepare("SELECT COUNT(*) FROM user_fan_club_members fcm {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// --- Fetch members ---
$sql = "SELECT fcm.*,
               p.FullNameRus AS person_name, p.path AS person_path
        FROM user_fan_club_members fcm
        LEFT JOIN persons p ON p.Persons_id = fcm.person_id
        {$whereClause}
        ORDER BY fcm.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$members = fromDbRows($stmt->fetchAll());

$statusLabels = [
    'pending'      => ['Ожидает', 'bg-warning text-dark'],
    'confirmed'    => ['Подтверждён', 'bg-success'],
    'unsubscribed' => ['Отписался', 'bg-secondary'],
];

$pageTitle = 'Фан-клубы';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-heart me-2"></i>Участники фан-клубов</h4>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-warning"><?= (int)$stats['pending_count'] ?></div>
                <div class="small text-muted">Ожидают</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-success"><?= (int)$stats['confirmed_count'] ?></div>
                <div class="small text-muted">Подтверждено</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-secondary"><?= (int)$stats['unsubscribed_count'] ?></div>
                <div class="small text-muted">Отписалось</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold"><?= (int)$stats['total'] ?></div>
                <div class="small text-muted">Всего</div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small text-muted mb-0">Статус</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Все статусы</option>
                    <?php foreach ($statusLabels as $sk => $sv): ?>
                    <option value="<?= $sk ?>" <?= $statusFilter === $sk ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sv[0], ENT_QUOTES, 'UTF-8') ?>
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
                <a href="/moderate/fan-clubs.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Members Table -->
<?php if (empty($members)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-heart" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Участников не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card card-mod">
        <div class="table-responsive">
            <table class="table table-hover mb-0 small">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Персона</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Статус</th>
                        <th>Дата</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m):
                        $st = $m['status'] ?? 'pending';
                        $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
                        $personPath = $m['person_path'] ?? '';
                    ?>
                    <tr>
                        <td><?= (int)$m['id'] ?></td>
                        <td>
                            <?php if ($personPath): ?>
                                <a href="/fan/<?= htmlspecialchars($personPath, ENT_QUOTES, 'UTF-8') ?>/" target="_blank">
                                    <?= htmlspecialchars($m['person_name'] ?? 'ID:' . $m['person_id'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php else: ?>
                                <?= htmlspecialchars($m['person_name'] ?? 'ID:' . $m['person_id'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($m['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($m['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><span class="badge <?= $stInfo[1] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td><?= htmlspecialchars($m['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="text-muted"><?= htmlspecialchars($m['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php if (!empty($m['message'])): ?>
                    <tr>
                        <td></td>
                        <td colspan="6" class="pt-0 pb-2">
                            <small class="text-muted"><i class="bi bi-chat-left-text me-1"></i><?= nl2br(htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8')) ?></small>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['status' => $statusFilter, 'page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['status' => $statusFilter, 'page' => $i])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['status' => $statusFilter, 'page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
