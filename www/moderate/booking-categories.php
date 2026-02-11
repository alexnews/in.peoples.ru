<?php

declare(strict_types=1);

/**
 * Booking Categories Management — /moderate/booking-categories.php
 *
 * Admin: CRUD categories, reorder, toggle active.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('admin');
$db = getDb();

// Fetch all categories
$stmt = $db->query(
    'SELECT c.*, COUNT(bp.id) AS person_count
     FROM booking_categories c
     LEFT JOIN booking_persons bp ON bp.category_id = c.id
     GROUP BY c.id
     ORDER BY c.sort_order ASC'
);
$categories = fromDbRows($stmt->fetchAll());

$pageTitle = 'Приглашения — Категории';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0"><i class="bi bi-tags me-2"></i>Категории приглашений</h4>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openCategoryModal()">
        <i class="bi bi-plus-lg me-1"></i>Добавить категорию
    </button>
</div>

<div class="card card-mod">
    <div class="table-responsive">
        <table class="table queue-table mb-0">
            <thead>
                <tr>
                    <th style="width:50px">#</th>
                    <th>Иконка</th>
                    <th>Название</th>
                    <th>Slug</th>
                    <th>Описание</th>
                    <th>Персон</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody id="categoriesTable">
                <?php foreach ($categories as $cat): ?>
                <tr id="cat-row-<?= (int)$cat['id'] ?>">
                    <td class="text-muted"><?= (int)$cat['sort_order'] ?></td>
                    <td>
                        <?php if (!empty($cat['icon'])): ?>
                            <i class="bi <?= htmlspecialchars($cat['icon'], ENT_QUOTES, 'UTF-8') ?> fs-5"></i>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="fw-medium"><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><code><?= htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') ?></code></td>
                    <td class="small text-muted"><?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="badge bg-light text-dark"><?= (int)$cat['person_count'] ?></span>
                    </td>
                    <td>
                        <?php if ((int)$cat['is_active']): ?>
                            <span class="badge bg-success">Активна</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Скрыта</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary edit-cat-btn"
                                    data-id="<?= (int)$cat['id'] ?>"
                                    data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-slug="<?= htmlspecialchars($cat['slug'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-description="<?= htmlspecialchars($cat['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-icon="<?= htmlspecialchars($cat['icon'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-sort="<?= (int)$cat['sort_order'] ?>"
                                    data-active="<?= (int)$cat['is_active'] ?>"
                                    title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary toggle-cat-btn"
                                    data-id="<?= (int)$cat['id'] ?>"
                                    data-active="<?= (int)$cat['is_active'] ?>"
                                    title="<?= (int)$cat['is_active'] ? 'Скрыть' : 'Активировать' ?>">
                                <i class="bi <?= (int)$cat['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                            </button>
                            <?php if ((int)$cat['person_count'] === 0): ?>
                            <button type="button" class="btn btn-outline-danger delete-cat-btn"
                                    data-id="<?= (int)$cat['id'] ?>"
                                    data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle">Добавить категорию</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="catEditId" value="">
                <div class="mb-3">
                    <label for="catName" class="form-label">Название <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="catName" placeholder="Ведущие">
                </div>
                <div class="mb-3">
                    <label for="catSlug" class="form-label">Slug <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="catSlug" placeholder="vedushchie">
                    <div class="form-text">Только латинские буквы, цифры и дефис</div>
                </div>
                <div class="mb-3">
                    <label for="catDescription" class="form-label">Описание</label>
                    <textarea class="form-control" id="catDescription" rows="2"></textarea>
                </div>
                <div class="row">
                    <div class="col-8 mb-3">
                        <label for="catIcon" class="form-label">Bootstrap Icon класс</label>
                        <input type="text" class="form-control" id="catIcon" placeholder="bi-mic">
                    </div>
                    <div class="col-4 mb-3">
                        <label for="catSort" class="form-label">Порядок</label>
                        <input type="number" class="form-control" id="catSort" value="0" min="0">
                    </div>
                </div>
                <div id="catEditActiveGroup" style="display:none;">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="catActive" checked>
                        <label class="form-check-label" for="catActive">Активна</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" id="catSaveBtn">
                    <i class="bi bi-check-lg me-1"></i>Сохранить
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    var modal = new bootstrap.Modal(document.getElementById('categoryModal'));

    window.openCategoryModal = function(data) {
        document.getElementById('catEditId').value = data ? data.id : '';
        document.getElementById('catName').value = data ? data.name : '';
        document.getElementById('catSlug').value = data ? data.slug : '';
        document.getElementById('catDescription').value = data ? data.description : '';
        document.getElementById('catIcon').value = data ? data.icon : '';
        document.getElementById('catSort').value = data ? data.sort : '0';
        document.getElementById('categoryModalTitle').textContent = data ? 'Редактировать категорию' : 'Добавить категорию';
        document.getElementById('catEditActiveGroup').style.display = data ? 'block' : 'none';
        if (data) {
            document.getElementById('catActive').checked = !!data.active;
        }
    };

    // Edit button
    document.querySelectorAll('.edit-cat-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            openCategoryModal({
                id: this.dataset.id,
                name: this.dataset.name,
                slug: this.dataset.slug,
                description: this.dataset.description,
                icon: this.dataset.icon,
                sort: this.dataset.sort,
                active: parseInt(this.dataset.active)
            });
            modal.show();
        });
    });

    // Save
    document.getElementById('catSaveBtn').addEventListener('click', function() {
        var btn = this;
        var editId = document.getElementById('catEditId').value;
        var body = {
            action: editId ? 'update' : 'create',
            name: document.getElementById('catName').value,
            slug: document.getElementById('catSlug').value,
            description: document.getElementById('catDescription').value,
            icon: document.getElementById('catIcon').value,
            sort_order: parseInt(document.getElementById('catSort').value) || 0,
            csrf_token: csrfToken
        };
        if (editId) {
            body.id = parseInt(editId);
            body.is_active = document.getElementById('catActive').checked ? 1 : 0;
        }

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('/api/v1/booking/admin-categories.php', {
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
    document.querySelectorAll('.toggle-cat-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.dataset.id);
            var isActive = parseInt(this.dataset.active);
            fetch('/api/v1/booking/admin-categories.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'update', id: id,
                    is_active: isActive ? 0 : 1,
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.error?.message || 'Ошибка');
            });
        });
    });

    // Delete
    document.querySelectorAll('.delete-cat-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = parseInt(this.dataset.id);
            var name = this.dataset.name;
            if (!confirm('Удалить категорию "' + name + '"?')) return;

            fetch('/api/v1/booking/admin-categories.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'delete', id: id, csrf_token: csrfToken })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) location.reload();
                else alert(data.error?.message || 'Ошибка');
            });
        });
    });

    // Auto-generate slug from name
    document.getElementById('catName').addEventListener('input', function() {
        if (document.getElementById('catEditId').value) return; // don't auto-slug on edit
        var slug = this.value.toLowerCase()
            .replace(/[а-яёА-ЯЁ]/g, function(c) {
                var map = {'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'yo','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'};
                return map[c.toLowerCase()] || c;
            })
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
        document.getElementById('catSlug').value = slug;
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
