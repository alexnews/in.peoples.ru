<?php

declare(strict_types=1);

/**
 * Audit Log — /moderate/log.php
 *
 * Shows moderation action log with filters for moderator, action type, and date range.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$db = getDb();

// ── Filters ────────────────────────────────────────────────────────────────

$moderatorFilter = isset($_GET['moderator_id']) && $_GET['moderator_id'] !== '' ? (int) $_GET['moderator_id'] : null;
$actionFilter    = $_GET['action'] ?? '';
$dateFrom        = $_GET['date_from'] ?? '';
$dateTo          = $_GET['date_to'] ?? '';
$page            = max(1, (int) ($_GET['page'] ?? 1));
$perPage         = 30;
$offset          = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($moderatorFilter !== null) {
    $where[] = 'ml.moderator_id = :mod_id';
    $params[':mod_id'] = $moderatorFilter;
}

$validActions = ['approve', 'reject', 'request_revision', 'ban', 'unban', 'promote_moderator', 'demote_to_user'];
if (in_array($actionFilter, $validActions, true)) {
    $where[] = 'ml.action = :action';
    $params[':action'] = $actionFilter;
}

if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'DATE(ml.created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'DATE(ml.created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Count ──────────────────────────────────────────────────────────────────

$countSql = "SELECT COUNT(*) FROM users_moderation_log ml {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// ── Fetch log entries ──────────────────────────────────────────────────────

$sql = "SELECT ml.id, ml.moderator_id, ml.action, ml.target_type, ml.target_id,
               ml.note, ml.created_at,
               u_mod.username AS moderator_username, u_mod.display_name AS moderator_name,
               CASE
                   WHEN ml.target_type = 'submission' THEN
                       (SELECT COALESCE(s.title, CONCAT('Материал #', s.id))
                        FROM user_submissions s WHERE s.id = ml.target_id LIMIT 1)
                   WHEN ml.target_type = 'user' THEN
                       (SELECT COALESCE(u2.display_name, u2.username)
                        FROM users u2 WHERE u2.id = ml.target_id LIMIT 1)
                   ELSE CONCAT(ml.target_type, ' #', ml.target_id)
               END AS target_description
        FROM users_moderation_log ml
        INNER JOIN users u_mod ON u_mod.id = ml.moderator_id
        {$whereClause}
        ORDER BY ml.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$logEntries = fromDbRows($stmt->fetchAll());

// ── Available moderators for filter ────────────────────────────────────────

$modStmt = $db->query(
    "SELECT DISTINCT u.id, u.username, u.display_name
     FROM users u
     INNER JOIN users_moderation_log ml ON ml.moderator_id = u.id
     ORDER BY u.display_name, u.username"
);
$moderators = fromDbRows($modStmt->fetchAll());

// ── Action labels ──────────────────────────────────────────────────────────

$actionLabels = [
    'approve'           => 'Одобрено',
    'reject'            => 'Отклонено',
    'request_revision'  => 'На доработку',
    'ban'               => 'Забанен',
    'unban'             => 'Разбанен',
    'promote_moderator' => 'Повышен',
    'demote_to_user'    => 'Понижен',
];

$pageTitle = 'Журнал модерации';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Журнал модерации <span class="text-muted fs-6">(<?= $total ?>)</span></h4>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/moderate/log.php" class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="modFilter" class="form-label small text-muted mb-0">Модератор</label>
                <select name="moderator_id" id="modFilter" class="form-select form-select-sm">
                    <option value="">Все модераторы</option>
                    <?php foreach ($moderators as $mod): ?>
                    <option value="<?= (int) $mod['id'] ?>" <?= $moderatorFilter === (int) $mod['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($mod['display_name'] ?? $mod['username'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="actionFilter" class="form-label small text-muted mb-0">Действие</label>
                <select name="action" id="actionFilter" class="form-select form-select-sm">
                    <option value="">Все действия</option>
                    <?php foreach ($actionLabels as $ak => $av): ?>
                    <option value="<?= $ak ?>" <?= $actionFilter === $ak ? 'selected' : '' ?>>
                        <?= htmlspecialchars($av, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="dateFrom" class="form-label small text-muted mb-0">С</label>
                <input type="date" name="date_from" id="dateFrom" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-auto">
                <label for="dateTo" class="form-label small text-muted mb-0">По</label>
                <input type="date" name="date_to" id="dateTo" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-funnel me-1"></i>Применить
                </button>
            </div>
            <?php if ($moderatorFilter !== null || $actionFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
            <div class="col-auto">
                <a href="/moderate/log.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Log Table -->
<?php if (empty($logEntries)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-journal" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Записей не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table queue-table log-table mb-0">
            <thead>
                <tr>
                    <th>Дата/время</th>
                    <th>Модератор</th>
                    <th>Действие</th>
                    <th>Объект</th>
                    <th>Комментарий</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logEntries as $entry): ?>
                <?php
                    $act = $entry['action'] ?? '';
                    $label = $actionLabels[$act] ?? $act;
                    $badgeClass = 'badge-action-' . $act;
                ?>
                <tr>
                    <td class="text-nowrap text-muted small">
                        <?= htmlspecialchars($entry['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?= htmlspecialchars($entry['moderator_name'] ?? $entry['moderator_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <span class="badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($entry['target_type'] === 'submission'): ?>
                            <a href="/moderate/review.php?id=<?= (int) $entry['target_id'] ?>">
                                <?= htmlspecialchars($entry['target_description'] ?? 'ID: ' . $entry['target_id'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($entry['target_description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($entry['note'])): ?>
                        <span class="log-note" title="<?= htmlspecialchars($entry['note'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($entry['note'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php else: ?>
                            <span class="text-muted">-</span>
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
                    'moderator_id' => $moderatorFilter, 'action' => $actionFilter,
                    'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $page - 1
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
                    'moderator_id' => $moderatorFilter, 'action' => $actionFilter,
                    'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $i
                ])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter([
                    'moderator_id' => $moderatorFilter, 'action' => $actionFilter,
                    'date_from' => $dateFrom, 'date_to' => $dateTo, 'page' => $page + 1
                ])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
