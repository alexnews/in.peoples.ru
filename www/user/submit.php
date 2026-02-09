<?php
/**
 * Universal submission form — requires auth.
 *
 * - Create new submission or edit existing (draft/revision_requested)
 * - Section selector, person autocomplete, TinyMCE editor, photo upload
 * - Pre-select section from ?section= URL param
 * - Load existing submission from ?edit=ID
 */

declare(strict_types=1);

$pageTitle = 'Добавить материал';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = (int) $currentUser['id'];

// Supported sections
$supportedSections = [2, 3, 4, 5, 7, 8, 19];
$placeholders = implode(',', $supportedSections);

$stmt = $db->prepare(
    "SELECT id, nameRus, nameEng, path
     FROM peoples_section
     WHERE working = 'Y' AND id IN ({$placeholders})
     ORDER BY nameRus"
);
$stmt->execute();
$sections = fromDbRows($stmt->fetchAll());

// Pre-select section from URL
$preselectedSection = (int) ($_GET['section'] ?? 0);

// Editing mode
$editId = (int) ($_GET['edit'] ?? 0);
$submission = null;

if ($editId > 0) {
    $stmt = $db->prepare(
        'SELECT s.*, p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
                p.NamePhoto AS person_photo, p.AllUrlInSity AS person_path,
                p.DateIn AS person_date_birth, p.DateOut AS person_date_death
         FROM user_submissions s
         LEFT JOIN persons p ON p.Persons_id = s.KodPersons
         WHERE s.id = :id AND s.user_id = :uid'
    );
    $stmt->execute([':id' => $editId, ':uid' => $userId]);
    $submission = $stmt->fetch();

    if ($submission) {
        $submission = fromDbArray($submission);
        // Only draft or revision_requested can be edited
        if (!in_array($submission['status'], ['draft', 'revision_requested'], true)) {
            $submission = null;
            $editId = 0;
        } else {
            $pageTitle = 'Редактировать материал';
            $preselectedSection = (int) $submission['section_id'];
        }
    } else {
        $editId = 0;
    }
}

// Section names map
$sectionNames = [];
foreach ($sections as $s) {
    $sectionNames[(int)$s['id']] = $s['nameRus'];
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-pencil-square me-2"></i>
        <?= $editId ? 'Редактировать материал' : 'Добавить материал' ?>
    </h4>
    <span id="autosave-indicator" class="autosave-indicator"></span>
</div>

<?php if ($submission && $submission['status'] === 'revision_requested' && !empty($submission['moderator_note'])): ?>
<div class="moderator-note">
    <div class="note-label"><i class="bi bi-chat-left-text me-1"></i>Комментарий модератора</div>
    <?= htmlspecialchars($submission['moderator_note'], ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<form id="submit-form" novalidate>
    <input type="hidden" id="submission-id" value="<?= $editId ?: '' ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Section -->
            <div class="mb-3">
                <label for="section_id" class="form-label">Раздел <span class="text-danger">*</span></label>
                <select class="form-select" id="section_id" name="section_id" required>
                    <option value="">Выберите раздел...</option>
                    <?php foreach ($sections as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"
                        <?= $preselectedSection === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($s['nameRus'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="invalid-feedback">Выберите раздел</div>
            </div>

            <!-- Person autocomplete -->
            <div class="mb-3">
                <label for="person-search" class="form-label">Персона</label>
                <div class="person-autocomplete-wrapper">
                    <input type="text" class="form-control" id="person-search"
                           placeholder="Начните вводить имя..."
                           value="<?= $submission && $submission['person_name'] ? htmlspecialchars($submission['person_name'], ENT_QUOTES, 'UTF-8') : '' ?>"
                           <?= $submission && $submission['KodPersons'] ? 'disabled' : '' ?>
                           autocomplete="off">
                    <input type="hidden" id="KodPersons" name="KodPersons"
                           value="<?= $submission ? (int)($submission['KodPersons'] ?? 0) : '' ?>">
                    <div id="person-autocomplete-dropdown" class="person-autocomplete-dropdown"></div>
                </div>
                <div id="selected-person"
                     <?= (!$submission || !$submission['KodPersons']) ? 'style="display:none"' : '' ?>>
                    <?php if ($submission && $submission['KodPersons']): ?>
                    <?php
                        $personPhoto = !empty($submission['person_photo']) && !empty($submission['person_path'])
                            ? $submission['person_path'] . $submission['person_photo']
                            : '';
                        $personDates = $submission['person_date_birth'] ?? '';
                        if (!empty($submission['person_date_death'])) {
                            $personDates .= ' — ' . $submission['person_date_death'];
                        }
                    ?>
                    <div class="selected-person-card">
                        <?php if ($personPhoto): ?>
                        <img src="<?= htmlspecialchars($personPhoto, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php endif; ?>
                        <div class="info">
                            <div class="name"><?= htmlspecialchars($submission['person_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if ($personDates): ?>
                            <div class="dates"><?= htmlspecialchars($personDates, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary btn-remove" title="Убрать">&times;</button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Title -->
            <div class="mb-3">
                <label for="title" class="form-label">Заголовок <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="title" name="title"
                       value="<?= $submission ? htmlspecialchars($submission['title'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                       placeholder="Введите заголовок" required>
                <div class="invalid-feedback">Заполните заголовок</div>
            </div>

            <!-- Epigraph -->
            <div class="mb-3" id="epigraph-group">
                <label for="epigraph" class="form-label">Эпиграф / краткое описание</label>
                <textarea class="form-control" id="epigraph" name="epigraph" rows="2"
                          placeholder="Краткое описание или эпиграф (необязательно)"><?= $submission ? htmlspecialchars($submission['epigraph'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>
            </div>

            <!-- Content editor -->
            <div class="mb-3" id="content-group">
                <label for="content" class="form-label">Содержание <span class="text-danger">*</span></label>
                <div id="content-toolbar" class="btn-toolbar mb-1 gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="b" title="Bold"><b>B</b></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="i" title="Italic"><i>I</i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="h2" title="H2">H2</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="h3" title="H3">H3</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="p" title="Paragraph">P</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-tag="blockquote" title="Quote"><i class="bi bi-quote"></i></button>
                </div>
                <textarea class="form-control" id="content" name="content" rows="12"><?= $submission ? htmlspecialchars($submission['content'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?></textarea>
                <div class="invalid-feedback">Заполните содержание</div>
            </div>

            <!-- Photo Upload -->
            <div class="mb-3" id="photo-upload-group">
                <label class="form-label">Фото</label>
                <div class="photo-dropzone" id="photo-dropzone">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <p>Перетащите фото сюда или <span class="browse-link">выберите файл</span></p>
                    <p class="small text-muted">JPG, PNG, WebP. Максимум 10 МБ.</p>
                </div>
                <input type="file" id="photo-file-input" accept="image/jpeg,image/png,image/webp"
                       multiple style="display:none">

                <div id="photo-preview-grid" class="photo-preview-grid">
                    <?php if ($submission && !empty($submission['photo_path'])): ?>
                    <div class="photo-preview-item" data-file-path="<?= htmlspecialchars($submission['photo_path'], ENT_QUOTES, 'UTF-8') ?>">
                        <img src="<?= htmlspecialchars($submission['photo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <button type="button" class="remove-btn" title="Удалить">&times;</button>
                        <input type="text" class="caption-input" placeholder="Подпись к фото...">
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Sidebar -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="card-title mb-3">Публикация</h6>

                    <!-- Source URL -->
                    <div class="mb-3">
                        <label for="source_url" class="form-label small text-muted">Источник (URL)</label>
                        <input type="url" class="form-control form-control-sm" id="source_url" name="source_url"
                               value="<?= $submission ? htmlspecialchars($submission['source_url'] ?? '', ENT_QUOTES, 'UTF-8') : '' ?>"
                               placeholder="https://...">
                    </div>

                    <hr>

                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-secondary" id="btn-save-draft">
                            <i class="bi bi-save me-1"></i>Сохранить черновик
                        </button>
                        <button type="button" class="btn btn-primary" id="btn-submit-review">
                            <i class="bi bi-send me-1"></i>Отправить на проверку
                        </button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <h6 class="card-title mb-2"><i class="bi bi-info-circle me-1"></i>Подсказки</h6>
                    <ul class="small text-muted mb-0" style="padding-left:1.1rem">
                        <li>Выберите раздел и укажите персону</li>
                        <li>Заполните заголовок и содержание</li>
                        <li>Черновики сохраняются автоматически каждые 60 секунд</li>
                        <li>После отправки на проверку модератор рассмотрит ваш материал</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Simple formatting toolbar -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    var toolbar = document.getElementById('content-toolbar');
    var textarea = document.getElementById('content');
    if (!toolbar || !textarea) return;

    toolbar.querySelectorAll('[data-tag]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tag = this.getAttribute('data-tag');
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var selected = textarea.value.substring(start, end);
            var open = '<' + tag + '>';
            var close = '</' + tag + '>';
            textarea.value = textarea.value.substring(0, start) + open + selected + close + textarea.value.substring(end);
            textarea.focus();
            textarea.selectionStart = start + open.length;
            textarea.selectionEnd = start + open.length + selected.length;
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
