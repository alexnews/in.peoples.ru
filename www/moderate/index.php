<?php

declare(strict_types=1);

/**
 * Moderation Dashboard — /moderate/
 *
 * Shows stats overview, pending by type, recent moderation log, and top contributors.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$db = getDb();

// ── Stats ──────────────────────────────────────────────────────────────────

// Queue size (pending)
$stmt = $db->query("SELECT COUNT(*) FROM user_submissions WHERE status = 'pending'");
$queueSize = (int) $stmt->fetchColumn();

// Approved today
$stmt = $db->query(
    "SELECT COUNT(*) FROM user_submissions
     WHERE status = 'approved' AND DATE(reviewed_at) = CURDATE()"
);
$approvedToday = (int) $stmt->fetchColumn();

// Rejected today
$stmt = $db->query(
    "SELECT COUNT(*) FROM user_submissions
     WHERE status = 'rejected' AND DATE(reviewed_at) = CURDATE()"
);
$rejectedToday = (int) $stmt->fetchColumn();

// Active users (logged in within last 30 days)
$stmt = $db->query(
    "SELECT COUNT(*) FROM users
     WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$activeUsers = (int) $stmt->fetchColumn();

// ── Pending by type ────────────────────────────────────────────────────────

$stmt = $db->query(
    "SELECT ps.id AS section_id, ps.nameRus AS section_name,
            COUNT(s.id) AS pending_count
     FROM user_submissions s
     INNER JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.status = 'pending'
     GROUP BY ps.id
     ORDER BY pending_count DESC"
);
$pendingByType = fromDbRows($stmt->fetchAll());

// ── Recent moderation actions (last 10) ────────────────────────────────────

$stmt = $db->query(
    "SELECT ml.id, ml.action, ml.target_type, ml.target_id, ml.note, ml.created_at,
            u_mod.username AS moderator_username, u_mod.display_name AS moderator_name,
            CASE
                WHEN ml.target_type = 'submission' THEN
                    (SELECT COALESCE(s.title, CONCAT('ID: ', s.id))
                     FROM user_submissions s WHERE s.id = ml.target_id LIMIT 1)
                WHEN ml.target_type = 'user' THEN
                    (SELECT COALESCE(u2.display_name, u2.username)
                     FROM users u2 WHERE u2.id = ml.target_id LIMIT 1)
                ELSE CONCAT(ml.target_type, ' #', ml.target_id)
            END AS target_description
     FROM users_moderation_log ml
     INNER JOIN users u_mod ON u_mod.id = ml.moderator_id
     ORDER BY ml.created_at DESC
     LIMIT 10"
);
$recentActions = fromDbRows($stmt->fetchAll());

// ── Top contributors this month (top 5) ────────────────────────────────────

$stmt = $db->query(
    "SELECT u.id, u.username, u.display_name, u.reputation, u.avatar_path,
            COUNT(s.id) AS approved_count
     FROM users u
     INNER JOIN user_submissions s ON s.user_id = u.id
     WHERE s.status = 'approved'
       AND s.reviewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY u.id
     ORDER BY approved_count DESC
     LIMIT 5"
);
$topContributors = fromDbRows($stmt->fetchAll());

// ── Section icon map ───────────────────────────────────────────────────────

$sectionIcons = [
    2  => 'bi-file-text',
    3  => 'bi-image',
    4  => 'bi-newspaper',
    5  => 'bi-chat-left-text',
    7  => 'bi-music-note-beamed',
    8  => 'bi-lightbulb',
    19 => 'bi-pen',
];

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

$pageTitle = 'Дашборд';
require_once __DIR__ . '/includes/header.php';
?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card position-relative overflow-hidden">
            <div class="card-body">
                <i class="bi bi-inbox stat-icon text-warning"></i>
                <div class="stat-value text-warning"><?= $queueSize ?></div>
                <div class="stat-label">Ожидают проверки</div>
                <?php if ($queueSize > 0): ?>
                    <a href="/moderate/queue.php" class="stretched-link"></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card position-relative overflow-hidden">
            <div class="card-body">
                <i class="bi bi-check-circle stat-icon text-success"></i>
                <div class="stat-value text-success"><?= $approvedToday ?></div>
                <div class="stat-label">Одобрено сегодня</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card position-relative overflow-hidden">
            <div class="card-body">
                <i class="bi bi-x-circle stat-icon text-danger"></i>
                <div class="stat-value text-danger"><?= $rejectedToday ?></div>
                <div class="stat-label">Отклонено сегодня</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card stat-card position-relative overflow-hidden">
            <div class="card-body">
                <i class="bi bi-people stat-icon text-primary"></i>
                <div class="stat-value text-primary"><?= $activeUsers ?></div>
                <div class="stat-label">Активных пользователей</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left Column -->
    <div class="col-lg-8">
        <!-- Pending by Type -->
        <?php if (!empty($pendingByType)): ?>
        <div class="card card-mod mb-4">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0"><i class="bi bi-bar-chart me-2"></i>Ожидают проверки по разделам</h5>
            </div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php foreach ($pendingByType as $item): ?>
                    <?php
                        $sid = (int) $item['section_id'];
                        $icon = $sectionIcons[$sid] ?? 'bi-folder';
                        $iconClass = 'section-icon-' . $sid;
                        if (!isset($sectionIcons[$sid])) $iconClass = 'section-icon-default';
                    ?>
                    <a href="/moderate/queue.php?section_id=<?= $sid ?>"
                       class="list-group-item list-group-item-action d-flex align-items-center">
                        <span class="section-icon <?= $iconClass ?> me-3">
                            <i class="bi <?= $icon ?>"></i>
                        </span>
                        <span class="flex-grow-1"><?= htmlspecialchars($item['section_name'] ?? 'Раздел #' . $sid, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="badge bg-warning text-dark rounded-pill"><?= (int) $item['pending_count'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Recent Actions -->
        <div class="card card-mod">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0"><i class="bi bi-clock-history me-2"></i>Последние действия модерации</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentActions)): ?>
                    <div class="p-4 text-center text-muted">Пока нет действий</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Модератор</th>
                                <th>Действие</th>
                                <th>Объект</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActions as $action): ?>
                            <tr>
                                <td class="text-nowrap text-muted small">
                                    <?= htmlspecialchars($action['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($action['moderator_name'] ?? $action['moderator_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <?php
                                        $act = $action['action'] ?? '';
                                        $label = $actionLabels[$act] ?? $act;
                                        $badgeClass = 'badge-action-' . $act;
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
                                </td>
                                <td class="text-truncate-custom" title="<?= htmlspecialchars($action['target_description'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($action['target_type'] === 'submission'): ?>
                                        <a href="/moderate/review.php?id=<?= (int) $action['target_id'] ?>">
                                            <?= htmlspecialchars($action['target_description'] ?? 'ID: ' . $action['target_id'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <?= htmlspecialchars($action['target_description'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent text-end">
                <a href="/moderate/log.php" class="btn btn-sm btn-outline-secondary">
                    Все записи <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4">
        <!-- Top Contributors -->
        <div class="card card-mod">
            <div class="card-header bg-transparent">
                <h5 class="card-title mb-0"><i class="bi bi-trophy me-2"></i>Лучшие авторы месяца</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topContributors)): ?>
                    <div class="p-4 text-center text-muted">Нет данных за текущий месяц</div>
                <?php else: ?>
                <ol class="list-group list-group-flush list-group-numbered">
                    <?php foreach ($topContributors as $i => $contributor): ?>
                    <li class="list-group-item d-flex align-items-center">
                        <div class="ms-2 me-auto">
                            <div class="fw-bold">
                                <?= htmlspecialchars($contributor['display_name'] ?? $contributor['username'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <small class="text-muted">
                                Репутация:
                                <span class="reputation <?= (int) $contributor['reputation'] > 0 ? 'reputation-positive' : 'reputation-neutral' ?>">
                                    <?= (int) $contributor['reputation'] ?>
                                </span>
                            </small>
                        </div>
                        <span class="badge bg-success rounded-pill" title="Одобрено материалов">
                            <?= (int) $contributor['approved_count'] ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
