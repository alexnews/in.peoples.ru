<?php

declare(strict_types=1);

/**
 * Info Change Requests Management — /moderate/info-changes.php
 *
 * Admin: review info change requests from public form,
 * change status, add notes.
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
        SUM(status = 'new') AS new_count,
        SUM(status = 'in_progress') AS in_progress_count,
        SUM(status = 'completed') AS completed_count,
        SUM(status = 'rejected') AS rejected_count,
        SUM(status = 'spam') AS spam_count
    FROM user_info_change_requests"
);
$stats = $statsStmt->fetch();

// --- Filters ---
$statusFilter = $_GET['status'] ?? '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

$validStatuses = ['new', 'in_progress', 'completed', 'rejected', 'spam'];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'icr.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Count ---
$countStmt = $db->prepare("SELECT COUNT(*) FROM user_info_change_requests icr {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// --- Fetch requests ---
$sql = "SELECT icr.*,
               p.FullNameRus AS person_name, p.NamePhoto AS person_photo, p.AllUrlInSity AS person_url,
               u.display_name AS reviewer_name
        FROM user_info_change_requests icr
        LEFT JOIN persons p ON p.Persons_id = icr.person_id
        LEFT JOIN users u ON u.id = icr.reviewed_by
        {$whereClause}
        ORDER BY icr.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = fromDbRows($stmt->fetchAll());

$statusLabels = [
    'new'         => ['Новая', 'bg-danger'],
    'in_progress' => ['В работе', 'bg-info'],
    'completed'   => ['Выполнена', 'bg-success'],
    'rejected'    => ['Отклонена', 'bg-secondary'],
    'spam'        => ['Спам', 'bg-dark'],
];

$roleLabels = [
    'self'     => 'Сам артист',
    'manager'  => 'Менеджер',
    'relative' => 'Родственник',
    'fan'      => 'Поклонник',
    'other'    => 'Другое',
];

$changeFieldLabels = [
    'biography' => 'Биография',
    'photo'     => 'Фото',
    'dates'     => 'Даты жизни',
    'contacts'  => 'Контакты',
    'other'     => 'Другое',
];

$pageTitle = 'Изменение информации';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Запросы на изменение информации</h4>
</div>

<!-- Stats cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-danger"><?= (int)$stats['new_count'] ?></div>
                <div class="small text-muted">Новых</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-info"><?= (int)$stats['in_progress_count'] ?></div>
                <div class="small text-muted">В работе</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-success"><?= (int)$stats['completed_count'] ?></div>
                <div class="small text-muted">Выполнено</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-secondary"><?= (int)$stats['rejected_count'] ?></div>
                <div class="small text-muted">Отклонено</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-dark"><?= (int)$stats['spam_count'] ?></div>
                <div class="small text-muted">Спам</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
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
                <a href="/moderate/info-changes.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Requests -->
<?php if (empty($requests)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Запросов не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($requests as $req): ?>
    <?php
        $st = $req['status'] ?? 'new';
        $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
        $hasPersonLink = !empty($req['person_id']);
        $personDisplay = '';
        if ($hasPersonLink && !empty($req['person_name'])) {
            $personDisplay = $req['person_name'];
        } elseif (!empty($req['person_name_manual'])) {
            $personDisplay = $req['person_name_manual'];
        }
        $role = $req['requester_role'] ?? 'other';
        $roleLabel = $roleLabels[$role] ?? 'Другое';

        // Parse change_fields
        $fields = [];
        if (!empty($req['change_fields'])) {
            foreach (explode(',', $req['change_fields']) as $f) {
                $f = trim($f);
                if (isset($changeFieldLabels[$f])) {
                    $fields[] = $changeFieldLabels[$f];
                } elseif ($f !== '') {
                    $fields[] = $f;
                }
            }
        }
    ?>
    <div class="card card-mod mb-3" id="icr-<?= (int)$req['id'] ?>">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
            <div>
                <strong>#<?= (int)$req['id'] ?></strong>
                <?php if ($personDisplay !== ''): ?>
                    <span class="fw-medium ms-2">
                        <?php if ($hasPersonLink && !empty($req['person_url'])): ?>
                            <a href="https://www.peoples.ru/<?= htmlspecialchars($req['person_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="text-decoration-none">
                                <?= htmlspecialchars($personDisplay, ENT_QUOTES, 'UTF-8') ?>
                                <i class="bi bi-box-arrow-up-right ms-1 small"></i>
                            </a>
                        <?php else: ?>
                            <?= htmlspecialchars($personDisplay, ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!$hasPersonLink): ?>
                                <span class="badge bg-light text-muted ms-1">вручную</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
                <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge <?= $stInfo[1] ?>" id="icr-status-badge-<?= (int)$req['id'] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span>
                <small class="text-muted"><?= htmlspecialchars($req['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0 small">
                        <tr><td class="text-muted" style="width:120px">Имя</td><td class="fw-medium"><?= htmlspecialchars($req['requester_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <tr><td class="text-muted">Телефон</td><td class="fw-medium"><?= htmlspecialchars($req['requester_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php if (!empty($req['requester_email'])): ?>
                        <tr><td class="text-muted">Email</td><td><?= htmlspecialchars($req['requester_email'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0 small">
                        <?php if (!empty($req['evidence_url'])): ?>
                        <tr><td class="text-muted" style="width:120px">Источник</td><td><a href="<?= htmlspecialchars($req['evidence_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(mb_strimwidth($req['evidence_url'], 0, 50, '...', 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></a></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['reviewer_name'])): ?>
                        <tr><td class="text-muted">Проверил</td><td><?= htmlspecialchars($req['reviewer_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <tr><td class="text-muted">IP</td><td><?= htmlspecialchars($req['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                    </table>
                </div>
            </div>

            <?php if (!empty($fields)): ?>
            <div class="mt-2">
                <?php foreach ($fields as $fl): ?>
                    <span class="badge bg-light text-dark me-1"><?= htmlspecialchars($fl, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($req['description'])): ?>
            <div class="mt-2 p-2 bg-light rounded small">
                <i class="bi bi-chat-left-text me-1 text-muted"></i>
                <?= nl2br(htmlspecialchars($req['description'], ENT_QUOTES, 'UTF-8')) ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($req['admin_note'])): ?>
            <div class="alert alert-info py-2 px-3 mt-2 mb-0 small">
                <i class="bi bi-sticky me-1"></i>
                <strong>Заметка:</strong> <?= nl2br(htmlspecialchars($req['admin_note'], ENT_QUOTES, 'UTF-8')) ?>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="mt-3 d-flex gap-2 align-items-center flex-wrap">
                <select class="form-select form-select-sm icr-status-select" style="width:auto;" data-id="<?= (int)$req['id'] ?>">
                    <?php foreach ($statusLabels as $sk => $sv): ?>
                    <option value="<?= $sk ?>" <?= $st === $sk ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sv[0], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control form-control-sm" style="max-width:250px;"
                       id="icr-note-<?= (int)$req['id'] ?>" placeholder="Заметка">
                <button type="button" class="btn btn-sm btn-primary icr-save-btn" data-id="<?= (int)$req['id'] ?>">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

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

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

    var statusLabels = {
        'new': ['Новая', 'bg-danger'],
        'in_progress': ['В работе', 'bg-info'],
        'completed': ['Выполнена', 'bg-success'],
        'rejected': ['Отклонена', 'bg-secondary'],
        'spam': ['Спам', 'bg-dark']
    };

    // --- Save status + note ---
    document.querySelectorAll('.icr-save-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.dataset.id);
            var card = document.getElementById('icr-' + id);
            var statusSelect = card.querySelector('.icr-status-select');
            var noteInput = document.getElementById('icr-note-' + id);

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch('/api/v1/info-change/admin.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'update_status',
                    id: id,
                    status: statusSelect.value,
                    note: noteInput.value,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var badge = document.getElementById('icr-status-badge-' + id);
                    var info = statusLabels[data.data.new_status] || [data.data.new_status, 'bg-secondary'];
                    badge.className = 'badge ' + info[1];
                    badge.textContent = info[0];
                    noteInput.value = '';
                } else {
                    alert(data.error?.message || 'Ошибка');
                }
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
            })
            .catch(function() {
                alert('Ошибка сети');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
