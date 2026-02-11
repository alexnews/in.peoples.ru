<?php

declare(strict_types=1);

/**
 * Booking Requests Management — /moderate/booking.php
 *
 * Admin: view requests, change status, assign, add notes.
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
        SUM(status = 'contacted') AS contacted_count,
        SUM(status = 'completed') AS completed_count,
        SUM(status = 'cancelled') AS cancelled_count,
        SUM(status = 'spam') AS spam_count
    FROM booking_requests"
);
$stats = $statsStmt->fetch();

// --- Filters ---
$statusFilter = $_GET['status'] ?? '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

$validStatuses = ['new', 'in_progress', 'contacted', 'completed', 'cancelled', 'spam'];
if (in_array($statusFilter, $validStatuses, true)) {
    $where[] = 'br.status = :status';
    $params[':status'] = $statusFilter;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// --- Count ---
$countStmt = $db->prepare("SELECT COUNT(*) FROM booking_requests br {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// --- Fetch requests ---
$sql = "SELECT br.*,
               p.FullNameRus AS person_name, p.NamePhoto AS person_photo,
               u.display_name AS assigned_name
        FROM booking_requests br
        LEFT JOIN persons p ON p.Persons_id = br.person_id
        LEFT JOIN users u ON u.id = br.assigned_to
        {$whereClause}
        ORDER BY br.created_at DESC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = fromDbRows($stmt->fetchAll());

// --- Admin users for assignment ---
$adminsStmt = $db->query("SELECT id, display_name, username FROM users WHERE role IN ('admin', 'moderator') ORDER BY display_name");
$admins = fromDbRows($adminsStmt->fetchAll());

$statusLabels = [
    'new'         => ['Новая', 'bg-danger'],
    'in_progress' => ['В работе', 'bg-warning text-dark'],
    'contacted'   => ['Связались', 'bg-info'],
    'completed'   => ['Завершена', 'bg-success'],
    'cancelled'   => ['Отменена', 'bg-secondary'],
    'spam'        => ['Спам', 'bg-dark'],
];

$eventTypeLabels = [
    'corporate'  => 'Корпоратив',
    'wedding'    => 'Свадьба',
    'birthday'   => 'День рождения',
    'concert'    => 'Концерт',
    'private'    => 'Частная вечеринка',
    'city_event' => 'Городское мероприятие',
    'charity'    => 'Благотворительность',
    'opening'    => 'Открытие / презентация',
    'other'      => 'Другое',
];

$pageTitle = 'Приглашения — Заявки';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Заявки на приглашение</h4>
    <div>
        <?php
        $appCountStmt = $db->query("SELECT COUNT(*) FROM booking_applications WHERE status = 'new'");
        $newAppCount = (int) $appCountStmt->fetchColumn();
        ?>
        <a href="/moderate/booking-applications.php" class="btn btn-sm btn-outline-warning me-1">
            <i class="bi bi-person-badge me-1"></i>Заявки артистов
            <?php if ($newAppCount > 0): ?>
                <span class="badge bg-danger"><?= $newAppCount ?></span>
            <?php endif; ?>
        </a>
        <a href="/moderate/booking-persons.php" class="btn btn-sm btn-outline-secondary me-1">
            <i class="bi bi-people me-1"></i>Персоны
        </a>
        <a href="/moderate/booking-categories.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-tags me-1"></i>Категории
        </a>
    </div>
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
                <div class="fs-3 fw-bold text-warning"><?= (int)$stats['in_progress_count'] ?></div>
                <div class="small text-muted">В работе</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-info"><?= (int)$stats['contacted_count'] ?></div>
                <div class="small text-muted">Связались</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-success"><?= (int)$stats['completed_count'] ?></div>
                <div class="small text-muted">Завершено</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card card-mod text-center py-2">
            <div class="card-body py-2">
                <div class="fs-3 fw-bold text-secondary"><?= (int)$stats['cancelled_count'] ?></div>
                <div class="small text-muted">Отменено</div>
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
                <a href="/moderate/booking.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Requests Table -->
<?php if (empty($requests)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-inbox" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Заявок не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($requests as $req): ?>
    <?php
        $st = $req['status'] ?? 'new';
        $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
    ?>
    <div class="card card-mod mb-3" id="request-<?= (int)$req['id'] ?>">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
            <div>
                <strong>#<?= (int)$req['id'] ?></strong>
                <span class="text-muted ms-2"><?= htmlspecialchars($req['client_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                <?php if (!empty($req['person_name'])): ?>
                    <i class="bi bi-arrow-right mx-1 text-muted"></i>
                    <span class="fw-medium"><?= htmlspecialchars($req['person_name'], ENT_QUOTES, 'UTF-8') ?></span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-2">
                <span class="badge <?= $stInfo[1] ?>" id="status-badge-<?= (int)$req['id'] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span>
                <small class="text-muted"><?= htmlspecialchars($req['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0 small">
                        <tr><td class="text-muted" style="width:120px">Телефон</td><td class="fw-medium"><?= htmlspecialchars($req['client_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php if (!empty($req['client_email'])): ?>
                        <tr><td class="text-muted">Email</td><td><?= htmlspecialchars($req['client_email'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['client_company'])): ?>
                        <tr><td class="text-muted">Компания</td><td><?= htmlspecialchars($req['client_company'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['event_type'])): ?>
                        <tr><td class="text-muted">Тип</td><td><?= htmlspecialchars($eventTypeLabels[$req['event_type']] ?? $req['event_type'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['event_date'])): ?>
                        <tr><td class="text-muted">Дата</td><td><?= htmlspecialchars($req['event_date'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm table-borderless mb-0 small">
                        <?php if (!empty($req['event_city'])): ?>
                        <tr><td class="text-muted" style="width:120px">Город</td><td><?= htmlspecialchars($req['event_city'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['event_venue'])): ?>
                        <tr><td class="text-muted">Площадка</td><td><?= htmlspecialchars($req['event_venue'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['guest_count'])): ?>
                        <tr><td class="text-muted">Гостей</td><td><?= number_format((int)$req['guest_count'], 0, '', ' ') ?></td></tr>
                        <?php endif; ?>
                        <?php if (!empty($req['budget_from']) || !empty($req['budget_to'])): ?>
                        <tr>
                            <td class="text-muted">Бюджет</td>
                            <td>
                                <?php if (!empty($req['budget_from'])): ?><?= number_format((int)$req['budget_from'], 0, '', ' ') ?><?php endif; ?>
                                <?php if (!empty($req['budget_from']) && !empty($req['budget_to'])): ?> — <?php endif; ?>
                                <?php if (!empty($req['budget_to'])): ?><?= number_format((int)$req['budget_to'], 0, '', ' ') ?><?php endif; ?>
                                &#8381;
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($req['assigned_name'])): ?>
                        <tr><td class="text-muted">Ответственный</td><td><?= htmlspecialchars($req['assigned_name'], ENT_QUOTES, 'UTF-8') ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>

            <?php if (!empty($req['message'])): ?>
            <div class="mt-2 p-2 bg-light rounded small">
                <i class="bi bi-chat-left-text me-1 text-muted"></i>
                <?= nl2br(htmlspecialchars($req['message'], ENT_QUOTES, 'UTF-8')) ?>
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
                <select class="form-select form-select-sm status-select" style="width:auto;" data-id="<?= (int)$req['id'] ?>">
                    <?php foreach ($statusLabels as $sk => $sv): ?>
                    <option value="<?= $sk ?>" <?= $st === $sk ? 'selected' : '' ?>>
                        <?= htmlspecialchars($sv[0], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="form-control form-control-sm note-input" style="max-width:300px;"
                       id="note-<?= (int)$req['id'] ?>" placeholder="Заметка (необязательно)">
                <button type="button" class="btn btn-sm btn-primary save-status-btn" data-id="<?= (int)$req['id'] ?>">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
                <select class="form-select form-select-sm assign-select" style="width:auto;" data-id="<?= (int)$req['id'] ?>">
                    <option value="">— Назначить —</option>
                    <?php foreach ($admins as $adm): ?>
                    <option value="<?= (int)$adm['id'] ?>" <?= (int)($req['assigned_to'] ?? 0) === (int)$adm['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($adm['display_name'] ?? $adm['username'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">IP: <?= htmlspecialchars($req['ip_address'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
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
        'in_progress': ['В работе', 'bg-warning text-dark'],
        'contacted': ['Связались', 'bg-info'],
        'completed': ['Завершена', 'bg-success'],
        'cancelled': ['Отменена', 'bg-secondary'],
        'spam': ['Спам', 'bg-dark']
    };

    // Save status
    document.querySelectorAll('.save-status-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.dataset.id);
            var card = document.getElementById('request-' + id);
            var statusSelect = card.querySelector('.status-select');
            var noteInput = document.getElementById('note-' + id);

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            fetch('/api/v1/booking/admin-requests.php', {
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
                    var badge = document.getElementById('status-badge-' + id);
                    var info = statusLabels[data.data.new_status] || [data.data.new_status, 'bg-secondary'];
                    badge.className = 'badge ' + info[1];
                    badge.textContent = info[0];
                    noteInput.value = '';
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
                } else {
                    alert(data.error?.message || 'Ошибка');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
                }
            })
            .catch(function() {
                alert('Ошибка сети');
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Сохранить';
            });
        });
    });

    // Assign
    document.querySelectorAll('.assign-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var id = parseInt(this.dataset.id);
            fetch('/api/v1/booking/admin-requests.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'assign',
                    id: id,
                    assigned_to: this.value || null,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) alert(data.error?.message || 'Ошибка');
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
