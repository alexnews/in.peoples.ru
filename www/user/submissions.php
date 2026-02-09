<?php
/**
 * My submissions list — requires auth.
 *
 * Displays a filterable, paginated table of the user's submissions.
 * Data is loaded via AJAX from /api/v1/submissions/index.php
 */

declare(strict_types=1);

$pageTitle = 'Мои материалы';
require_once __DIR__ . '/includes/header.php';

$db = getDb();

// Load sections for filter dropdown
$supportedSections = [2, 3, 4, 5, 7, 8, 19];
$placeholders = implode(',', $supportedSections);
$stmt = $db->prepare(
    "SELECT id, nameRus
     FROM peoples_section
     WHERE working = 'Y' AND id IN ({$placeholders})
     ORDER BY nameRus"
);
$stmt->execute();
$sections = fromDbRows($stmt->fetchAll());
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-folder me-2"></i>Мои материалы
    </h4>
    <a href="/user/submit.php" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-circle me-1"></i>Добавить
    </a>
</div>

<!-- Filter row -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-center">
            <div class="col-md-3">
                <select class="form-select form-select-sm" id="filter-section">
                    <option value="">Все разделы</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>">
                        <?= htmlspecialchars($s['nameRus'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" id="filter-status">
                    <option value="">Все статусы</option>
                    <option value="draft">Черновик</option>
                    <option value="pending">На проверке</option>
                    <option value="approved">Опубликовано</option>
                    <option value="rejected">Отклонено</option>
                    <option value="revision_requested">На доработку</option>
                </select>
            </div>
            <div class="col-md-6">
                <input type="text" class="form-control form-control-sm" id="filter-search"
                       placeholder="Поиск по названию...">
            </div>
        </div>
    </div>
</div>

<!-- Submissions table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px"></th>
                    <th>Название</th>
                    <th>Персона</th>
                    <th>Статус</th>
                    <th>Дата</th>
                    <th style="width:100px">Действия</th>
                </tr>
            </thead>
            <tbody id="submissions-table-body">
                <tr>
                    <td colspan="6" class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-muted" role="status"></div>
                        <span class="text-muted ms-2">Загрузка...</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
<div id="submissions-pagination" class="mt-3"></div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Удалить материал?</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Вы уверены, что хотите удалить
                    &laquo;<span id="delete-modal-title"></span>&raquo;?
                </p>
                <p class="text-muted small mt-1 mb-0">Это действие нельзя отменить.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-sm btn-danger" id="btn-confirm-delete">Удалить</button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
