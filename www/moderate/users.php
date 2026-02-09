<?php

declare(strict_types=1);

/**
 * User Management — /moderate/users.php
 *
 * Searchable, filterable user list with management actions (ban, unban, promote, demote).
 * Role changes require admin role.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDb();

// ── Filters ────────────────────────────────────────────────────────────────

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$roleFilter = $_GET['role'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($search !== '') {
    $searchDb = '%' . toDb($search) . '%';
    $where[] = '(u.username LIKE :s1 OR u.display_name LIKE :s2 OR u.email LIKE :s3)';
    $params[':s1'] = $searchDb;
    $params[':s2'] = $searchDb;
    $params[':s3'] = $searchDb;
}

$validRoles = ['user', 'moderator', 'admin'];
if (in_array($roleFilter, $validRoles, true)) {
    $where[] = 'u.role = :role';
    $params[':role'] = $roleFilter;
}

$validStatuses = ['active', 'banned', 'suspended'];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'u.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Count ──────────────────────────────────────────────────────────────────

$countSql = "SELECT COUNT(*) FROM users u {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// ── Fetch users ────────────────────────────────────────────────────────────

$sql = "SELECT u.id, u.username, u.email, u.display_name, u.avatar_path,
               u.role, u.status, u.reputation, u.last_login, u.created_at,
               (SELECT COUNT(*) FROM user_submissions WHERE user_id = u.id) AS total_submissions,
               (SELECT COUNT(*) FROM user_submissions WHERE user_id = u.id AND status = 'approved') AS approved_submissions
        FROM users u
        {$whereClause}
        ORDER BY u.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$users = fromDbRows($stmt->fetchAll());

// ── Role/status labels ─────────────────────────────────────────────────────

$roleLabels = [
    'admin'     => 'Администратор',
    'moderator' => 'Модератор',
    'user'      => 'Пользователь',
];

$statusLabels = [
    'active'    => 'Активен',
    'banned'    => 'Забанен',
    'suspended' => 'Заблокирован',
];

$pageTitle = 'Пользователи';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Пользователи <span class="text-muted fs-6">(<?= $total ?>)</span></h4>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/moderate/users.php" class="row g-2 align-items-end">
            <div class="col-md-4 col-lg-3">
                <label for="searchInput" class="form-label small text-muted mb-0">Поиск</label>
                <input type="text" name="search" id="searchInput" class="form-control form-control-sm"
                       placeholder="Имя, логин или email..."
                       value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-auto">
                <label for="roleSelect" class="form-label small text-muted mb-0">Роль</label>
                <select name="role" id="roleSelect" class="form-select form-select-sm">
                    <option value="">Все роли</option>
                    <?php foreach ($roleLabels as $rk => $rv): ?>
                    <option value="<?= $rk ?>" <?= $roleFilter === $rk ? 'selected' : '' ?>>
                        <?= htmlspecialchars($rv, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
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
            <?php if ($search !== '' || $roleFilter !== '' || $statusFilter !== ''): ?>
            <div class="col-auto">
                <a href="/moderate/users.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Users Table -->
<?php if (empty($users)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-person-x" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Пользователи не найдены.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table queue-table mb-0">
            <thead>
                <tr>
                    <th style="width: 40px;"></th>
                    <th>Логин</th>
                    <th>Имя</th>
                    <th>Email</th>
                    <th>Роль</th>
                    <th>Репутация</th>
                    <th>Материалы</th>
                    <th>Статус</th>
                    <th>Регистрация</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <?php
                    $uid = (int) $u['id'];
                    $role = $u['role'] ?? 'user';
                    $status = $u['status'] ?? 'active';
                    $displayName = $u['display_name'] ?? $u['username'];
                    $rep = (int) ($u['reputation'] ?? 0);
                    $totalSub = (int) ($u['total_submissions'] ?? 0);
                    $approvedSub = (int) ($u['approved_submissions'] ?? 0);
                    $isSelf = ($uid === (int) $currentUser['id']);
                ?>
                <tr>
                    <td>
                        <?php if (!empty($u['avatar_path'])): ?>
                            <img src="<?= htmlspecialchars($u['avatar_path'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;"
                                 onerror="this.outerHTML='<i class=\'bi bi-person-circle fs-5 text-muted\'></i>';">
                        <?php else: ?>
                            <i class="bi bi-person-circle fs-5 text-muted"></i>
                        <?php endif; ?>
                    </td>
                    <td class="fw-medium"><?= htmlspecialchars($u['username'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="small"><?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge badge-role-<?= htmlspecialchars($role, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($roleLabels[$role] ?? $role, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <span class="reputation <?= $rep > 0 ? 'reputation-positive' : 'reputation-neutral' ?>"><?= $rep ?></span>
                    </td>
                    <td>
                        <span title="Одобрено / Всего"><?= $approvedSub ?> / <?= $totalSub ?></span>
                    </td>
                    <td>
                        <span class="badge badge-status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($statusLabels[$status] ?? $status, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="text-nowrap text-muted small">
                        <?= htmlspecialchars($u['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?php if (!$isSelf): ?>
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($status === 'active'): ?>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#"
                                           data-user-action="ban"
                                           data-user-id="<?= $uid ?>"
                                           data-user-name="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-slash-circle me-1"></i>Забанить
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($status === 'banned'): ?>
                                    <li>
                                        <a class="dropdown-item text-success" href="#"
                                           data-user-action="unban"
                                           data-user-id="<?= $uid ?>"
                                           data-user-name="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-check-circle me-1"></i>Разбанить
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($isAdmin && $role === 'user'): ?>
                                    <li>
                                        <a class="dropdown-item text-primary" href="#"
                                           data-user-action="promote_moderator"
                                           data-user-id="<?= $uid ?>"
                                           data-user-name="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-arrow-up-circle me-1"></i>Назначить модератором
                                        </a>
                                    </li>
                                <?php endif; ?>
                                <?php if ($isAdmin && $role === 'moderator'): ?>
                                    <li>
                                        <a class="dropdown-item text-warning" href="#"
                                           data-user-action="demote_to_user"
                                           data-user-id="<?= $uid ?>"
                                           data-user-name="<?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-arrow-down-circle me-1"></i>Разжаловать
                                        </a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <?php else: ?>
                            <span class="text-muted small">Вы</span>
                        <?php endif; ?>
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
                    'search' => $search, 'role' => $roleFilter, 'status' => $statusFilter, 'page' => $page - 1
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
                    'search' => $search, 'role' => $roleFilter, 'status' => $statusFilter, 'page' => $i
                ])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter([
                    'search' => $search, 'role' => $roleFilter, 'status' => $statusFilter, 'page' => $page + 1
                ])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
