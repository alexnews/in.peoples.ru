<?php

declare(strict_types=1);

/**
 * Moderation Queue — /moderate/queue.php
 *
 * Lists pending submissions with filtering, sorting, pagination, and bulk actions.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$db = getDb();

// ── Filters ────────────────────────────────────────────────────────────────

$sectionId = isset($_GET['section_id']) && $_GET['section_id'] !== '' ? (int) $_GET['section_id'] : null;
$sortOrder  = ($_GET['sort'] ?? 'oldest') === 'newest' ? 'DESC' : 'ASC';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

// Build WHERE
$where  = ["s.status = 'pending'"];
$params = [];

if ($sectionId !== null) {
    $where[] = 's.section_id = :section_id';
    $params[':section_id'] = $sectionId;
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// ── Count ──────────────────────────────────────────────────────────────────

$countSql = "SELECT COUNT(*) FROM user_submissions s {$whereClause}";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// ── Fetch submissions ──────────────────────────────────────────────────────

$sql = "SELECT s.id, s.user_id, s.section_id, s.KodPersons, s.title,
               s.status, s.created_at,
               u.username AS submitter_username, u.display_name AS submitter_display_name,
               u.reputation AS submitter_reputation,
               p.FullNameRus AS person_name,
               ps.nameRus AS section_name
        FROM user_submissions s
        INNER JOIN users u ON u.id = s.user_id
        LEFT JOIN persons p ON p.Persons_id = s.KodPersons
        LEFT JOIN peoples_section ps ON ps.id = s.section_id
        {$whereClause}
        ORDER BY s.created_at {$sortOrder}
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$submissions = fromDbRows($stmt->fetchAll());

// ── Available sections for filter dropdown ─────────────────────────────────

$sectStmt = $db->query(
    "SELECT DISTINCT ps.id, ps.nameRus
     FROM peoples_section ps
     INNER JOIN user_submissions s ON s.section_id = ps.id
     WHERE s.status = 'pending'
     ORDER BY ps.nameRus"
);
$availableSections = fromDbRows($sectStmt->fetchAll());

// ── Section icons map ──────────────────────────────────────────────────────

$sectionIcons = [
    2  => 'bi-file-text',
    3  => 'bi-image',
    4  => 'bi-newspaper',
    5  => 'bi-chat-left-text',
    7  => 'bi-music-note-beamed',
    8  => 'bi-lightbulb',
    19 => 'bi-pen',
];

$pageTitle = 'Очередь модерации';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Очередь модерации <span class="text-muted fs-6">(<?= $total ?>)</span></h4>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" action="/moderate/queue.php" class="row g-2 align-items-end">
            <div class="col-auto">
                <label for="filterSection" class="form-label small text-muted mb-0">Раздел</label>
                <select name="section_id" id="filterSection" class="form-select form-select-sm">
                    <option value="">Все разделы</option>
                    <?php foreach ($availableSections as $sec): ?>
                    <option value="<?= (int) $sec['id'] ?>" <?= $sectionId === (int) $sec['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sec['nameRus'] ?? 'ID: ' . $sec['id'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label for="filterSort" class="form-label small text-muted mb-0">Сортировка</label>
                <select name="sort" id="filterSort" class="form-select form-select-sm">
                    <option value="oldest" <?= $sortOrder === 'ASC' ? 'selected' : '' ?>>Сначала старые</option>
                    <option value="newest" <?= $sortOrder === 'DESC' ? 'selected' : '' ?>>Сначала новые</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-funnel me-1"></i>Применить
                </button>
            </div>
            <?php if ($sectionId !== null): ?>
            <div class="col-auto">
                <a href="/moderate/queue.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Queue Table -->
<?php if (empty($submissions)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0 text-muted">Очередь пуста. Все материалы проверены.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table queue-table mb-0">
            <thead>
                <tr>
                    <th style="width: 32px;">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAll">
                        </div>
                    </th>
                    <th style="width: 40px;">Тип</th>
                    <th>Заголовок</th>
                    <th>Автор</th>
                    <th>Персона</th>
                    <th>Дата</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $sub): ?>
                <?php
                    $sid = (int) ($sub['section_id'] ?? 0);
                    $icon = $sectionIcons[$sid] ?? 'bi-folder';
                    $iconClass = isset($sectionIcons[$sid]) ? 'section-icon-' . $sid : 'section-icon-default';
                    $displayTitle = $sub['title'] ?? $sub['section_name'] ?? 'Без заголовка';
                    $submitterName = $sub['submitter_display_name'] ?? $sub['submitter_username'] ?? 'Неизвестный';
                    $rep = (int) ($sub['submitter_reputation'] ?? 0);
                ?>
                <tr data-id="<?= (int) $sub['id'] ?>" class="clickable-row">
                    <td onclick="event.stopPropagation();">
                        <div class="form-check">
                            <input class="form-check-input row-checkbox" type="checkbox" value="<?= (int) $sub['id'] ?>">
                        </div>
                    </td>
                    <td>
                        <span class="section-icon <?= $iconClass ?>" title="<?= htmlspecialchars($sub['section_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <i class="bi <?= $icon ?>"></i>
                        </span>
                    </td>
                    <td>
                        <span class="queue-title" title="<?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($displayTitle, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <span><?= htmlspecialchars($submitterName, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="reputation ms-1 <?= $rep > 0 ? 'reputation-positive' : 'reputation-neutral' ?>"
                              title="Репутация">(<?= $rep ?>)</span>
                    </td>
                    <td>
                        <?php if ($sub['person_name']): ?>
                            <?= htmlspecialchars($sub['person_name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php else: ?>
                            <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-nowrap text-muted small">
                        <?= htmlspecialchars($sub['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Bulk Actions Bar -->
    <div class="bulk-actions-bar align-items-center gap-3 mt-3">
        <span class="text-muted">Выбрано: <strong id="selectedCount">0</strong></span>
        <button type="button" id="bulkApprove" class="btn btn-sm btn-approve">
            <i class="bi bi-check-lg me-1"></i>Одобрить выбранные
        </button>
        <button type="button" id="bulkReject" class="btn btn-sm btn-reject">
            <i class="bi bi-x-lg me-1"></i>Отклонить выбранные
        </button>
        <div id="bulkProgress" class="flex-grow-1" style="display: none;">
            <div class="progress" style="height: 20px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%;">0%</div>
            </div>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['section_id' => $sectionId, 'sort' => $_GET['sort'] ?? 'oldest', 'page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php
            $startPage = max(1, $page - 3);
            $endPage = min($totalPages, $page + 3);
            for ($i = $startPage; $i <= $endPage; $i++):
            ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['section_id' => $sectionId, 'sort' => $_GET['sort'] ?? 'oldest', 'page' => $i])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['section_id' => $sectionId, 'sort' => $_GET['sort'] ?? 'oldest', 'page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Keyboard Shortcuts -->
<div class="shortcuts-panel mt-4">
    <h6 class="mb-2"><i class="bi bi-keyboard me-1"></i>Горячие клавиши</h6>
    <dl class="row mb-0 small">
        <dt class="col-sm-2"><span class="kbd-hint">J</span> / <span class="kbd-hint">K</span></dt>
        <dd class="col-sm-4">Навигация по строкам</dd>
        <dt class="col-sm-2"><span class="kbd-hint">Enter</span></dt>
        <dd class="col-sm-4">Открыть выделенную запись</dd>
        <dt class="col-sm-2"><span class="kbd-hint">Esc</span></dt>
        <dd class="col-sm-4">Вернуться в очередь</dd>
    </dl>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
