<?php

declare(strict_types=1);

/**
 * Booking Persons Management — /moderate/booking-persons.php
 *
 * Admin: add/edit/remove bookable persons with person autocomplete.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('admin');
$db = getDb();

// Load categories for dropdown
$catStmt = $db->query('SELECT id, name, slug FROM booking_categories WHERE is_active = 1 ORDER BY sort_order ASC');
$categories = fromDbRows($catStmt->fetchAll());

// Filters
$categoryFilter = $_GET['category'] ?? '';
$activeFilter   = $_GET['active'] ?? '';
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

$where  = [];
$params = [];

if ($categoryFilter !== '') {
    $where[] = 'bp.category_id = :cid';
    $params[':cid'] = (int) $categoryFilter;
}

if ($activeFilter === '1') {
    $where[] = 'bp.is_active = 1';
} elseif ($activeFilter === '0') {
    $where[] = 'bp.is_active = 0';
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM booking_persons bp {$whereClause}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 0;

// Fetch persons
$sql = "SELECT bp.*,
               p.FullNameRus, p.FullNameEngl, p.NamePhoto, p.AllUrlInSity,
               p.DateIn, p.DateOut,
               c.name AS category_name, c.slug AS category_slug
        FROM booking_persons bp
        INNER JOIN persons p ON p.Persons_id = bp.person_id
        INNER JOIN booking_categories c ON c.id = bp.category_id
        {$whereClause}
        ORDER BY bp.sort_order ASC, p.FullNameRus ASC
        LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$persons = fromDbRows($stmt->fetchAll());

$pageTitle = 'Букинг — Персоны';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Персоны букинга <span class="text-muted fs-6">(<?= $total ?>)</span></h4>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#personModal" onclick="openPersonModal()">
        <i class="bi bi-plus-lg me-1"></i>Добавить персону
    </button>
</div>

<!-- Filters -->
<div class="card card-mod mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small text-muted mb-0">Категория</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">Все</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= $categoryFilter == $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small text-muted mb-0">Статус</label>
                <select name="active" class="form-select form-select-sm">
                    <option value="">Все</option>
                    <option value="1" <?= $activeFilter === '1' ? 'selected' : '' ?>>Активные</option>
                    <option value="0" <?= $activeFilter === '0' ? 'selected' : '' ?>>Скрытые</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-search me-1"></i>Найти
                </button>
            </div>
            <?php if ($categoryFilter !== '' || $activeFilter !== ''): ?>
            <div class="col-auto">
                <a href="/moderate/booking-persons.php" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-x me-1"></i>Сбросить
                </a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Persons Table -->
<?php if (empty($persons)): ?>
    <div class="card card-mod">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-people" style="font-size: 3rem;"></i>
            <p class="mt-3 mb-0">Персон для букинга не найдено.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table queue-table mb-0">
            <thead>
                <tr>
                    <th style="width:60px">Фото</th>
                    <th>Персона</th>
                    <th>Категория</th>
                    <th>Цена</th>
                    <th>Краткое описание</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($persons as $p): ?>
                <tr id="bp-row-<?= (int)$p['id'] ?>">
                    <td>
                        <?php if (!empty($p['NamePhoto'])): ?>
                            <img src="<?= htmlspecialchars(($p['AllUrlInSity'] ?? '') . $p['NamePhoto'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="" style="width:40px;height:50px;object-fit:cover;border-radius:4px;">
                        <?php else: ?>
                            <div style="width:40px;height:50px;background:#eee;border-radius:4px;" class="d-flex align-items-center justify-content-center">
                                <i class="bi bi-person text-muted"></i>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-medium"><?= htmlspecialchars($p['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <small class="text-muted"><?= htmlspecialchars($p['FullNameEngl'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </td>
                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($p['category_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                    <td class="text-nowrap">
                        <?php if ($p['price_from']): ?>
                            <strong><?= number_format((int)$p['price_from'], 0, '', ' ') ?></strong>
                            <?php if ($p['price_to']): ?>
                                — <?= number_format((int)$p['price_to'], 0, '', ' ') ?>
                            <?php endif; ?>
                            <small class="text-muted">&#8381;</small>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                        <?= htmlspecialchars($p['short_desc'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td>
                        <?php if ((int)$p['is_active']): ?>
                            <span class="badge bg-success">Активна</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Скрыта</span>
                        <?php endif; ?>
                        <?php if ((int)$p['is_featured']): ?>
                            <span class="badge bg-warning text-dark">Featured</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-bp-btn"
                                    data-id="<?= (int)$p['id'] ?>"
                                    data-person-id="<?= (int)$p['person_id'] ?>"
                                    data-person-name="<?= htmlspecialchars($p['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-category-id="<?= (int)$p['category_id'] ?>"
                                    data-price-from="<?= (int)($p['price_from'] ?? 0) ?>"
                                    data-price-to="<?= (int)($p['price_to'] ?? 0) ?>"
                                    data-description="<?= htmlspecialchars($p['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-short-desc="<?= htmlspecialchars($p['short_desc'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-featured="<?= (int)$p['is_featured'] ?>"
                                    data-sort="<?= (int)$p['sort_order'] ?>"
                                    title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary toggle-bp-btn"
                                    data-id="<?= (int)$p['id'] ?>"
                                    title="Вкл/Выкл">
                                <i class="bi <?= (int)$p['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                            </button>
                            <button type="button" class="btn btn-outline-warning toggle-feat-btn"
                                    data-id="<?= (int)$p['id'] ?>"
                                    title="Featured">
                                <i class="bi <?= (int)$p['is_featured'] ? 'bi-star-fill' : 'bi-star' ?>"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger remove-bp-btn"
                                    data-id="<?= (int)$p['id'] ?>"
                                    data-name="<?= htmlspecialchars($p['FullNameRus'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
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
                <a class="page-link" href="?<?= http_build_query(array_filter(['category' => $categoryFilter, 'active' => $activeFilter, 'page' => $page - 1])) ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
            </li>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['category' => $categoryFilter, 'active' => $activeFilter, 'page' => $i])) ?>">
                    <?= $i ?>
                </a>
            </li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_filter(['category' => $categoryFilter, 'active' => $activeFilter, 'page' => $page + 1])) ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
<?php endif; ?>

<!-- Add/Edit Person Modal -->
<div class="modal fade" id="personModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="personModalTitle">Добавить персону</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="bpEditId" value="">
                <input type="hidden" id="bpPersonId" value="">

                <!-- Person search (only for new) -->
                <div id="personSearchGroup" class="mb-3">
                    <label class="form-label">Персона <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="personSearch" placeholder="Начните вводить имя..." autocomplete="off">
                    <div id="personSearchResults" class="list-group position-absolute" style="z-index:1050;max-height:300px;overflow-y:auto;display:none;"></div>
                    <div id="selectedPerson" class="mt-2" style="display:none;">
                        <span class="badge bg-primary fs-6" id="selectedPersonName"></span>
                        <button type="button" class="btn btn-sm btn-link text-danger" id="clearPerson">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Категория <span class="text-danger">*</span></label>
                    <select class="form-select" id="bpCategory">
                        <option value="">— Выберите —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Цена от (&#8381;)</label>
                        <input type="number" class="form-control" id="bpPriceFrom" min="0">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Цена до (&#8381;)</label>
                        <input type="number" class="form-control" id="bpPriceTo" min="0">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Краткое описание (для карточки)</label>
                    <input type="text" class="form-control" id="bpShortDesc" maxlength="500">
                </div>

                <div class="mb-3">
                    <label class="form-label">Полное описание (для страницы)</label>
                    <textarea class="form-control" id="bpDescription" rows="4"></textarea>
                </div>

                <div class="row">
                    <div class="col-6 mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="bpFeatured">
                            <label class="form-check-label" for="bpFeatured">Featured (на главной)</label>
                        </div>
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Порядок сортировки</label>
                        <input type="number" class="form-control" id="bpSort" value="0" min="0">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="bpSaveBtn">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var modal = new bootstrap.Modal(document.getElementById('personModal'));
    var searchTimeout = null;

    window.openPersonModal = function(data) {
        document.getElementById('bpEditId').value = data ? data.id : '';
        document.getElementById('bpPersonId').value = data ? data.personId : '';
        document.getElementById('bpCategory').value = data ? data.categoryId : '';
        document.getElementById('bpPriceFrom').value = data && data.priceFrom ? data.priceFrom : '';
        document.getElementById('bpPriceTo').value = data && data.priceTo ? data.priceTo : '';
        document.getElementById('bpShortDesc').value = data ? data.shortDesc : '';
        document.getElementById('bpDescription').value = data ? data.description : '';
        document.getElementById('bpFeatured').checked = data ? !!data.featured : false;
        document.getElementById('bpSort').value = data ? data.sort : '0';
        document.getElementById('personModalTitle').textContent = data ? 'Редактировать' : 'Добавить персону';

        if (data) {
            document.getElementById('personSearchGroup').style.display = 'none';
        } else {
            document.getElementById('personSearchGroup').style.display = 'block';
            document.getElementById('personSearch').value = '';
            document.getElementById('selectedPerson').style.display = 'none';
            document.getElementById('personSearchResults').style.display = 'none';
        }
    };

    // Person autocomplete
    document.getElementById('personSearch').addEventListener('input', function() {
        var q = this.value.trim();
        clearTimeout(searchTimeout);
        if (q.length < 2) {
            document.getElementById('personSearchResults').style.display = 'none';
            return;
        }
        searchTimeout = setTimeout(function() {
            fetch('/api/v1/persons/search.php?q=' + encodeURIComponent(q) + '&limit=10')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success || !data.data.length) {
                    document.getElementById('personSearchResults').style.display = 'none';
                    return;
                }
                var html = '';
                data.data.forEach(function(p) {
                    var dateInfo = p.dates && p.dates.birth ? ' (' + p.dates.birth + ')' : '';
                    html += '<button type="button" class="list-group-item list-group-item-action person-result" ' +
                            'data-id="' + p.id + '" data-name="' + (p.name || '').replace(/"/g, '&quot;') + '">' +
                            '<strong>' + (p.name || '') + '</strong>' +
                            '<small class="text-muted ms-2">' + (p.name_eng || '') + dateInfo + '</small>' +
                            '</button>';
                });
                document.getElementById('personSearchResults').innerHTML = html;
                document.getElementById('personSearchResults').style.display = 'block';

                document.querySelectorAll('.person-result').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        document.getElementById('bpPersonId').value = this.dataset.id;
                        document.getElementById('selectedPersonName').textContent = this.dataset.name;
                        document.getElementById('selectedPerson').style.display = 'block';
                        document.getElementById('personSearch').value = '';
                        document.getElementById('personSearchResults').style.display = 'none';
                    });
                });
            });
        }, 300);
    });

    document.getElementById('clearPerson').addEventListener('click', function() {
        document.getElementById('bpPersonId').value = '';
        document.getElementById('selectedPerson').style.display = 'none';
    });

    // Edit buttons
    document.querySelectorAll('.edit-bp-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openPersonModal({
                id: this.dataset.id,
                personId: this.dataset.personId,
                personName: this.dataset.personName,
                categoryId: this.dataset.categoryId,
                priceFrom: parseInt(this.dataset.priceFrom) || '',
                priceTo: parseInt(this.dataset.priceTo) || '',
                description: this.dataset.description,
                shortDesc: this.dataset.shortDesc,
                featured: parseInt(this.dataset.featured),
                sort: this.dataset.sort
            });
            modal.show();
        });
    });

    // Save
    document.getElementById('bpSaveBtn').addEventListener('click', function() {
        var btn = this;
        var editId = document.getElementById('bpEditId').value;
        var personId = document.getElementById('bpPersonId').value;
        var categoryId = document.getElementById('bpCategory').value;

        if (!editId && !personId) {
            alert('Выберите персону.');
            return;
        }
        if (!categoryId) {
            alert('Выберите категорию.');
            return;
        }

        var body = {
            action: editId ? 'update' : 'add',
            category_id: parseInt(categoryId),
            price_from: document.getElementById('bpPriceFrom').value || null,
            price_to: document.getElementById('bpPriceTo').value || null,
            short_desc: document.getElementById('bpShortDesc').value,
            description: document.getElementById('bpDescription').value,
            is_featured: document.getElementById('bpFeatured').checked ? 1 : 0,
            sort_order: parseInt(document.getElementById('bpSort').value) || 0,
            csrf_token: csrfToken
        };

        if (editId) {
            body.id = parseInt(editId);
        } else {
            body.person_id = parseInt(personId);
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('/api/v1/booking/admin-persons.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                location.reload();
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

    // Toggle active
    document.querySelectorAll('.toggle-bp-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            fetch('/api/v1/booking/admin-persons.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'toggle_active', id: parseInt(this.dataset.id), csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.error?.message || 'Ошибка');
            });
        });
    });

    // Toggle featured
    document.querySelectorAll('.toggle-feat-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            fetch('/api/v1/booking/admin-persons.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'toggle_featured', id: parseInt(this.dataset.id), csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.error?.message || 'Ошибка');
            });
        });
    });

    // Remove
    document.querySelectorAll('.remove-bp-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Удалить "' + this.dataset.name + '" из букинга?')) return;
            fetch('/api/v1/booking/admin-persons.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'remove', id: parseInt(this.dataset.id), csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.error?.message || 'Ошибка');
            });
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
