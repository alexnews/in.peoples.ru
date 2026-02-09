<?php

declare(strict_types=1);

/**
 * Person Suggestions — /moderate/persons.php
 *
 * Moderators: review pending suggestions (approve/reject content quality).
 * Admins: push approved suggestions to the real `persons` table.
 *
 * Flow: User → Moderator (content quality) → Admin (push to persons table)
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$isAdmin = ($currentUser['role'] === 'admin');
$db = getDb();

// ── Load structure categories for admin push ─────────────────────────────────

$structureOptions = [];
if ($isAdmin) {
    $structStmt = $db->query(
        "SELECT Structure_id, NameURL, URL, title
         FROM structure
         WHERE Structure_id > 0
         ORDER BY NameURL"
    );
    $structureOptions = fromDbRows($structStmt->fetchAll());
}

// ── Filters ─────────────────────────────────────────────────────────────────

$statusFilter = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected', 'published', 'all'];
if (!in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = 'pending';
}

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// Build WHERE
$where  = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'ps.status = :status';
    $params[':status'] = $statusFilter;
}
$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Count ───────────────────────────────────────────────────────────────────

$countStmt = $db->prepare("SELECT COUNT(*) FROM user_person_suggestions ps {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// ── Fetch suggestions ───────────────────────────────────────────────────────

$sql = "SELECT ps.*,
               u.username AS submitter_username, u.display_name AS submitter_display_name,
               u.reputation AS submitter_reputation
        FROM user_person_suggestions ps
        INNER JOIN users u ON u.id = ps.user_id
        {$whereClause}
        ORDER BY ps.created_at ASC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$suggestions = fromDbRows($stmt->fetchAll());

// ── Status counts for tabs ──────────────────────────────────────────────────

$countsStmt = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM user_person_suggestions GROUP BY status"
);
$statusCounts = [];
foreach ($countsStmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}
$totalAll = array_sum($statusCounts);

$statusLabels = [
    'pending'            => ['Ожидает', 'bg-warning text-dark'],
    'approved'           => ['Одобрено', 'bg-success'],
    'rejected'           => ['Отклонено', 'bg-danger'],
    'revision_requested' => ['На доработке', 'bg-info'],
    'published'          => ['Создано', 'bg-primary'],
];

$ruMonths = ['', 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

function formatDate(string $dateStr, array $months): string {
    if (empty($dateStr)) return '';
    $parts = explode('-', substr($dateStr, 0, 10));
    if (count($parts) !== 3) return $dateStr;
    return (int)$parts[2] . ' ' . ($months[(int)$parts[1]] ?? '') . ' ' . $parts[0];
}

$pageTitle = 'Предложения персон';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-person-plus me-2"></i>Предложения персон</h4>
</div>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'pending' ? 'active' : '' ?>" href="?status=pending">
            Ожидают <span class="badge bg-warning text-dark ms-1"><?= $statusCounts['pending'] ?? 0 ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'approved' ? 'active' : '' ?>" href="?status=approved">
            Одобрены <span class="badge bg-success ms-1"><?= $statusCounts['approved'] ?? 0 ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'rejected' ? 'active' : '' ?>" href="?status=rejected">
            Отклонены <span class="badge bg-danger ms-1"><?= $statusCounts['rejected'] ?? 0 ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'published' ? 'active' : '' ?>" href="?status=published">
            Созданы <span class="badge bg-primary ms-1"><?= $statusCounts['published'] ?? 0 ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $statusFilter === 'all' ? 'active' : '' ?>" href="?status=all">
            Все <span class="badge bg-secondary ms-1"><?= $totalAll ?></span>
        </a>
    </li>
</ul>

<?php if (empty($suggestions)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5">
            <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0 text-muted">Нет предложений с данным статусом.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($suggestions as $sug): ?>
    <?php
        $st = $sug['status'] ?? 'pending';
        $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
        $fullName = trim(($sug['NameRus'] ?? '') . ' ' . ($sug['SurNameRus'] ?? ''));
        $fullNameEng = trim(($sug['NameEngl'] ?? '') . ' ' . ($sug['SurNameEngl'] ?? ''));
        $submitterName = $sug['submitter_display_name'] ?? $sug['submitter_username'] ?? '';
    ?>
    <div class="card card-mod mb-3" id="suggestion-<?= (int)$sug['id'] ?>">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
            <div>
                <strong><?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?></strong>
                <?php if ($fullNameEng): ?>
                    <small class="text-muted ms-2"><?= htmlspecialchars($fullNameEng, ENT_QUOTES, 'UTF-8') ?></small>
                <?php endif; ?>
            </div>
            <span class="badge <?= $stInfo[1] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <table class="table table-sm table-borderless mb-0 small">
                        <?php if (!empty($sug['DateIn'])): ?>
                        <tr><td class="text-muted">Рождение</td><td><?= htmlspecialchars(formatDate($sug['DateIn'], $ruMonths), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($sug['DateOut'])): ?>
                        <tr><td class="text-muted">Смерть</td><td><?= htmlspecialchars(formatDate($sug['DateOut'], $ruMonths), ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($sug['gender'])): ?>
                        <tr><td class="text-muted">Пол</td><td><?= $sug['gender'] === 'm' ? 'Мужской' : 'Женский' ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($sug['TownIn'])): ?>
                        <tr><td class="text-muted">Город</td><td><?= htmlspecialchars($sug['TownIn'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($sug['cc2born']) || !empty($sug['cc2dead']) || !empty($sug['cc2'])): ?>
                        <tr>
                            <td class="text-muted">Страны</td>
                            <td>
                                <?php if (!empty($sug['cc2born'])): ?><span class="badge bg-light text-dark">рожд: <?= htmlspecialchars($sug['cc2born'], ENT_QUOTES, 'UTF-8') ?></span> <?php endif; ?>
                                <?php if (!empty($sug['cc2dead'])): ?><span class="badge bg-light text-dark">смерти: <?= htmlspecialchars($sug['cc2dead'], ENT_QUOTES, 'UTF-8') ?></span> <?php endif; ?>
                                <?php if (!empty($sug['cc2'])): ?><span class="badge bg-light text-dark">осн: <?= htmlspecialchars($sug['cc2'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr><td class="text-muted">Автор</td><td><?= htmlspecialchars($submitterName, ENT_QUOTES, 'UTF-8') ?> <span class="reputation">(<?= (int)($sug['submitter_reputation'] ?? 0) ?>)</span></td></tr>
                        <tr><td class="text-muted">Дата</td><td><?= htmlspecialchars($sug['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php if (!empty($sug['source_url'])): ?>
                        <tr><td class="text-muted">Источник</td><td><a href="<?= htmlspecialchars($sug['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="small"><?= htmlspecialchars(mb_substr($sug['source_url'], 0, 50, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>...</a></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-8">
                    <?php if (!empty($sug['person_photo_path'])): ?>
                    <div class="float-end ms-3 mb-2">
                        <img src="<?= htmlspecialchars($sug['person_photo_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Фото" style="max-width:120px;max-height:150px;border-radius:4px;object-fit:cover;">
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($sug['title'])): ?>
                    <div class="mb-1"><span class="badge bg-secondary">Звание:</span> <?= htmlspecialchars($sug['title'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($sug['epigraph'])): ?>
                    <div class="text-muted fst-italic small mb-2"><?= htmlspecialchars($sug['epigraph'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <div class="review-content small" style="max-height:200px;overflow-y:auto;">
                        <?= nl2br(htmlspecialchars($sug['biography'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                    </div>
                    <?php if (!empty($sug['photo_path'])): ?>
                    <div class="mt-2">
                        <span class="small text-muted">Фото к статье:</span>
                        <img src="<?= htmlspecialchars($sug['photo_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Фото к статье" style="max-width:200px;max-height:150px;border-radius:4px;object-fit:cover;display:block;margin-top:4px;">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($sug['moderator_note'])): ?>
            <div class="alert alert-warning py-2 px-3 mt-2 mb-0 small">
                <i class="bi bi-chat-left-text me-1"></i>
                <strong>Модератор:</strong> <?= htmlspecialchars($sug['moderator_note'], ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <?php if ($st === 'pending'): ?>
            <div class="mt-3 d-flex gap-2 align-items-center">
                <input type="text" class="form-control form-control-sm" style="max-width:400px;"
                       id="note-<?= (int)$sug['id'] ?>" placeholder="Комментарий (необязательно)">
                <button type="button" class="btn btn-sm btn-approve person-action-btn"
                        data-id="<?= (int)$sug['id'] ?>" data-action="approve">
                    <i class="bi bi-check-lg me-1"></i>Одобрить
                </button>
                <button type="button" class="btn btn-sm btn-revision person-action-btn"
                        data-id="<?= (int)$sug['id'] ?>" data-action="request_revision">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>На доработку
                </button>
                <button type="button" class="btn btn-sm btn-reject person-action-btn"
                        data-id="<?= (int)$sug['id'] ?>" data-action="reject">
                    <i class="bi bi-x-lg me-1"></i>Отклонить
                </button>
            </div>
            <?php endif; ?>

            <?php if ($st === 'approved' && $isAdmin && empty($sug['published_person_id'])): ?>
            <div class="mt-3">
                <div class="alert alert-info py-2 px-3 mb-2 small">
                    <i class="bi bi-info-circle me-1"></i>
                    Модератор одобрил содержание. Выберите раздел, проверьте дубликаты и нажмите «Создать персону».
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-bold mb-1">Раздел на сайте <span class="text-danger">*</span></label>
                    <select class="form-select form-select-sm structure-select" id="structure-<?= (int)$sug['id'] ?>">
                        <option value="">— Выберите раздел —</option>
                        <?php foreach ($structureOptions as $opt): ?>
                        <option value="<?= (int)$opt['Structure_id'] ?>">
                            <?= htmlspecialchars(trim($opt['NameURL'], ' >'), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <button type="button" class="btn btn-sm btn-primary person-push-btn"
                            data-id="<?= (int)$sug['id'] ?>">
                        <i class="bi bi-plus-circle me-1"></i>Создать персону в базе
                    </button>
                    <a href="/api/v1/persons/search.php?q=<?= urlencode($sug['SurNameRus'] ?? '') ?>"
                       target="_blank" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-search me-1"></i>Поиск дубликатов
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($st === 'published' && !empty($sug['published_person_id'])): ?>
            <div class="mt-2">
                <span class="badge bg-primary">Persons_id: <?= (int)$sug['published_person_id'] ?></span>
                <small class="text-muted ms-2">Создано <?= htmlspecialchars($sug['published_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination justify-content-center mb-0">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(['status' => $statusFilter, 'page' => $page - 1]) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(['status' => $statusFilter, 'page' => $i]) ?>"><?= $i ?></a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(['status' => $statusFilter, 'page' => $page + 1]) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Moderator actions (approve/reject/revision)
    document.querySelectorAll('.person-action-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var action = this.dataset.action;
            var note = document.getElementById('note-' + id)?.value || '';
            var card = document.getElementById('suggestion-' + id);

            if (action === 'request_revision' && !note.trim()) {
                alert('Укажите комментарий при возврате на доработку.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch('/api/v1/moderate/person-review.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    suggestion_id: parseInt(id),
                    action: action,
                    note: note,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    card.style.opacity = '0.5';
                    card.querySelector('.card-header .badge').className = 'badge bg-success';
                    card.querySelector('.card-header .badge').textContent =
                        action === 'approve' ? 'Одобрено' : action === 'reject' ? 'Отклонено' : 'На доработке';
                    var actions = card.querySelector('.mt-3.d-flex');
                    if (actions) actions.remove();
                } else {
                    alert(data.error?.message || data.error || 'Ошибка');
                    btn.disabled = false;
                    btn.innerHTML = action === 'approve' ? '<i class="bi bi-check-lg me-1"></i>Одобрить' : 'Повторить';
                }
            })
            .catch(function() {
                alert('Ошибка сети');
                btn.disabled = false;
            });
        });
    });

    // Admin push to persons table
    document.querySelectorAll('.person-push-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.id;
            var structSelect = document.getElementById('structure-' + id);
            var kodStructure = structSelect ? structSelect.value : '';

            if (!kodStructure) {
                alert('Выберите раздел на сайте перед созданием персоны.');
                if (structSelect) structSelect.focus();
                return;
            }

            if (!confirm('Создать персону в рабочей таблице persons? Убедитесь, что дубликата нет.')) return;

            var card = document.getElementById('suggestion-' + id);
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Создаётся...';

            fetch('/api/v1/moderate/person-push.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    suggestion_id: parseInt(id),
                    kod_structure: parseInt(kodStructure),
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    card.querySelector('.card-header .badge').className = 'badge bg-primary';
                    card.querySelector('.card-header .badge').textContent = 'Создано';
                    btn.parentElement.innerHTML =
                        '<span class="badge bg-primary">Persons_id: ' + data.data.person_id + '</span>' +
                        '<span class="text-muted ms-2 small">Создано только что</span>';
                } else {
                    alert(data.error?.message || data.error || 'Ошибка');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i>Создать персону в базе';
                }
            })
            .catch(function() {
                alert('Ошибка сети');
                btn.disabled = false;
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
