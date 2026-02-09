<?php

declare(strict_types=1);

/**
 * Single Submission Review — /moderate/review.php?id=ID
 *
 * Shows full submission content, person info, metadata, and action buttons.
 * For biographies (section_id=2), shows side-by-side diff with existing content.
 * For photos (section_id=3), shows uploaded photos large.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/encoding.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/csrf.php';

$currentUser = requireRole('moderator');
$db = getDb();

// ── Load submission ────────────────────────────────────────────────────────

$submissionId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($submissionId <= 0) {
    http_response_code(400);
    echo 'Не указан ID материала.';
    exit;
}

$stmt = $db->prepare(
    "SELECT s.*,
            u.username AS submitter_username, u.display_name AS submitter_display_name,
            u.reputation AS submitter_reputation, u.avatar_path AS submitter_avatar,
            p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
            p.NamePhoto AS person_photo, p.Persons_id AS person_id,
            p.DataBirth AS person_birth, p.DataDeath AS person_death,
            ps.nameRus AS section_name, ps.nameEng AS section_name_eng
     FROM user_submissions s
     INNER JOIN users u ON u.id = s.user_id
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.id = :id"
);
$stmt->execute([':id' => $submissionId]);
$submission = $stmt->fetch();

if (!$submission) {
    http_response_code(404);
    echo 'Материал не найден.';
    exit;
}

$submission = fromDbArray($submission);
$sectionId = (int) ($submission['section_id'] ?? 0);
$isPending = $submission['status'] === 'pending';

// ── For biography: load existing content ───────────────────────────────────

$existingBio = null;
if ($sectionId === 2 && !empty($submission['KodPersons'])) {
    $bioStmt = $db->prepare(
        "SELECT Content, Epigraph, date_pub
         FROM histories
         WHERE KodPersons = :kod
         ORDER BY date_pub DESC
         LIMIT 1"
    );
    $bioStmt->execute([':kod' => (int) $submission['KodPersons']]);
    $existingBio = $bioStmt->fetch();
    if ($existingBio) {
        $existingBio = fromDbArray($existingBio);
    }
}

// ── Find next pending submission ───────────────────────────────────────────

$nextStmt = $db->prepare(
    "SELECT id FROM user_submissions
     WHERE status = 'pending' AND id != :current_id
     ORDER BY created_at ASC
     LIMIT 1"
);
$nextStmt->execute([':current_id' => $submissionId]);
$nextItem = $nextStmt->fetch();
$nextId = $nextItem ? (int) $nextItem['id'] : null;

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

$statusLabels = [
    'pending'            => ['Ожидает проверки', 'bg-warning text-dark'],
    'approved'           => ['Одобрено', 'bg-success'],
    'rejected'           => ['Отклонено', 'bg-danger'],
    'revision_requested' => ['На доработке', 'bg-info'],
    'draft'              => ['Черновик', 'bg-secondary'],
];

$pageTitle = 'Проверка: ' . ($submission['title'] ?? 'ID ' . $submissionId);
require_once __DIR__ . '/includes/header.php';
?>

<div data-submission-id="<?= $submissionId ?>">
<?php if ($nextId): ?>
    <span data-next-id="<?= $nextId ?>" style="display:none;"></span>
<?php endif; ?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="/moderate/">Дашборд</a></li>
        <li class="breadcrumb-item"><a href="/moderate/queue.php">Очередь</a></li>
        <li class="breadcrumb-item active">#<?= $submissionId ?></li>
    </ol>
</nav>

<div class="row g-4">
    <!-- Main Content Column -->
    <div class="col-lg-8">
        <!-- Submission Header -->
        <div class="card card-mod mb-3">
            <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
                <div>
                    <span class="section-icon <?= isset($sectionIcons[$sectionId]) ? 'section-icon-' . $sectionId : 'section-icon-default' ?> me-2">
                        <i class="bi <?= $sectionIcons[$sectionId] ?? 'bi-folder' ?>"></i>
                    </span>
                    <strong><?= htmlspecialchars($submission['section_name'] ?? 'Раздел', ENT_QUOTES, 'UTF-8') ?></strong>
                </div>
                <?php
                    $st = $submission['status'] ?? 'pending';
                    $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
                ?>
                <span class="badge <?= $stInfo[1] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="card-body">
                <h5 class="mb-3"><?= htmlspecialchars($submission['title'] ?? 'Без заголовка', ENT_QUOTES, 'UTF-8') ?></h5>

                <?php if (!empty($submission['epigraph'])): ?>
                <blockquote class="blockquote border-start border-3 ps-3 mb-3">
                    <p class="mb-0 fst-italic text-muted">
                        <?= htmlspecialchars($submission['epigraph'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                </blockquote>
                <?php endif; ?>

                <?php if ($sectionId === 3 && !empty($submission['photo_path'])): ?>
                    <!-- Photo Preview -->
                    <div class="text-center mb-3">
                        <img src="<?= htmlspecialchars($submission['photo_path'], ENT_QUOTES, 'UTF-8') ?>"
                             alt="Загруженное фото"
                             class="review-photo-preview">
                    </div>
                    <?php if (!empty($submission['title'])): ?>
                    <p class="text-center text-muted"><em><?= htmlspecialchars($submission['title'], ENT_QUOTES, 'UTF-8') ?></em></p>
                    <?php endif; ?>

                <?php elseif ($sectionId === 2 && $existingBio): ?>
                    <!-- Side-by-side biography diff -->
                    <div class="diff-container">
                        <div class="diff-panel diff-current">
                            <div class="diff-panel-header">Текущая биография</div>
                            <div class="review-content">
                                <?= $existingBio['Content'] ?? '<em class="text-muted">Пусто</em>' ?>
                            </div>
                        </div>
                        <div class="diff-panel diff-proposed">
                            <div class="diff-panel-header">Предложенная версия</div>
                            <div class="review-content">
                                <?= $submission['content'] ?? '<em class="text-muted">Пусто</em>' ?>
                            </div>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Regular content display -->
                    <div class="review-content">
                        <?= $submission['content'] ?? '<em class="text-muted">Содержимое отсутствует</em>' ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($submission['source_url'])): ?>
                <div class="mt-3">
                    <small class="text-muted">
                        <i class="bi bi-link-45deg me-1"></i>Источник:
                        <a href="<?= htmlspecialchars($submission['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($submission['source_url'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions -->
        <?php if ($isPending): ?>
        <div class="card card-mod mb-3">
            <div class="card-body">
                <div class="mb-3">
                    <label for="moderatorNote" class="form-label">Комментарий модератора</label>
                    <textarea class="form-control" id="moderatorNote" rows="3"
                              placeholder="Необязательно для одобрения/отклонения. Обязательно при возврате на доработку."></textarea>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" id="btnApprove" class="btn btn-approve">
                        <i class="bi bi-check-lg me-1"></i>Одобрить
                        <span class="kbd-hint">A</span>
                    </button>
                    <button type="button" id="btnRevision" class="btn btn-revision">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>На доработку
                        <span class="kbd-hint">E</span>
                    </button>
                    <button type="button" id="btnReject" class="btn btn-reject">
                        <i class="bi bi-x-lg me-1"></i>Отклонить
                        <span class="kbd-hint">R</span>
                    </button>
                </div>
            </div>
        </div>
        <?php else: ?>
            <?php if (!empty($submission['moderator_note'])): ?>
            <div class="card card-mod mb-3">
                <div class="card-body">
                    <h6><i class="bi bi-chat-left-text me-1"></i>Комментарий модератора</h6>
                    <p class="mb-0"><?= htmlspecialchars($submission['moderator_note'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Navigation -->
        <div class="d-flex justify-content-between align-items-center">
            <a href="/moderate/queue.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>К очереди
                <span class="kbd-hint">Esc</span>
            </a>
            <?php if ($nextId): ?>
            <a href="/moderate/review.php?id=<?= $nextId ?>" class="btn btn-outline-primary">
                Следующий <i class="bi bi-arrow-right ms-1"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sidebar -->
    <div class="col-lg-4 review-sidebar">
        <!-- Person Info -->
        <?php if (!empty($submission['person_name'])): ?>
        <div class="card card-mod mb-3">
            <div class="card-header bg-transparent">
                <h6 class="mb-0"><i class="bi bi-person me-1"></i>Персона</h6>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <?php if (!empty($submission['person_photo'])): ?>
                    <img src="https://www.peoples.ru/photo/<?= htmlspecialchars($submission['person_photo'], ENT_QUOTES, 'UTF-8') ?>"
                         alt="<?= htmlspecialchars($submission['person_name'], ENT_QUOTES, 'UTF-8') ?>"
                         class="person-thumbnail"
                         onerror="this.style.display='none';">
                    <?php endif; ?>
                    <div>
                        <h6 class="mb-1"><?= htmlspecialchars($submission['person_name'], ENT_QUOTES, 'UTF-8') ?></h6>
                        <?php if (!empty($submission['person_name_eng'])): ?>
                        <small class="text-muted d-block"><?= htmlspecialchars($submission['person_name_eng'], ENT_QUOTES, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if (!empty($submission['person_birth']) || !empty($submission['person_death'])): ?>
                        <small class="text-muted d-block mt-1">
                            <?= htmlspecialchars($submission['person_birth'] ?? '?', ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($submission['person_death'])): ?>
                                &mdash; <?= htmlspecialchars($submission['person_death'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </small>
                        <?php endif; ?>
                        <?php if (!empty($submission['person_id'])): ?>
                        <a href="https://www.peoples.ru/art/cinema/actor/<?= (int) $submission['person_id'] ?>/"
                           target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-2">
                            <i class="bi bi-box-arrow-up-right me-1"></i>Профиль на peoples.ru
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Submission Metadata -->
        <div class="card card-mod mb-3">
            <div class="card-header bg-transparent">
                <h6 class="mb-0"><i class="bi bi-info-circle me-1"></i>Информация</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted">ID</td>
                        <td><strong>#<?= $submissionId ?></strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Автор</td>
                        <td>
                            <?= htmlspecialchars($submission['submitter_display_name'] ?? $submission['submitter_username'] ?? 'Неизвестный', ENT_QUOTES, 'UTF-8') ?>
                            <span class="reputation ms-1 <?= (int) ($submission['submitter_reputation'] ?? 0) > 0 ? 'reputation-positive' : 'reputation-neutral' ?>">
                                (<?= (int) ($submission['submitter_reputation'] ?? 0) ?>)
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Раздел</td>
                        <td><?= htmlspecialchars($submission['section_name'] ?? 'ID: ' . $sectionId, ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Дата подачи</td>
                        <td><?= htmlspecialchars($submission['created_at'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php if (!empty($submission['updated_at']) && $submission['updated_at'] !== $submission['created_at']): ?>
                    <tr>
                        <td class="text-muted">Обновлено</td>
                        <td><?= htmlspecialchars($submission['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endif; ?>
                    <?php if (!empty($submission['reviewed_at'])): ?>
                    <tr>
                        <td class="text-muted">Проверено</td>
                        <td><?= htmlspecialchars($submission['reviewed_at'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="text-muted">Статус</td>
                        <td>
                            <?php
                                $st = $submission['status'] ?? 'pending';
                                $stInfo = $statusLabels[$st] ?? [$st, 'bg-secondary'];
                            ?>
                            <span class="badge <?= $stInfo[1] ?>"><?= htmlspecialchars($stInfo[0], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Keyboard Shortcuts (review page) -->
        <?php if ($isPending): ?>
        <div class="shortcuts-panel">
            <h6 class="mb-2"><i class="bi bi-keyboard me-1"></i>Горячие клавиши</h6>
            <dl class="row mb-0 small">
                <dt class="col-4"><span class="kbd-hint">A</span></dt>
                <dd class="col-8">Одобрить</dd>
                <dt class="col-4"><span class="kbd-hint">E</span></dt>
                <dd class="col-8">На доработку</dd>
                <dt class="col-4"><span class="kbd-hint">R</span></dt>
                <dd class="col-8">Отклонить</dd>
                <dt class="col-4"><span class="kbd-hint">Esc</span></dt>
                <dd class="col-8">К очереди</dd>
            </dl>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
