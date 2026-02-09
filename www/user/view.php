<?php
/**
 * View single submission — requires auth.
 *
 * Displays the full content of a submission with status, person info,
 * moderator notes, and action buttons.
 */

declare(strict_types=1);

$pageTitle = 'Просмотр материала';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = (int) $currentUser['id'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /user/submissions.php');
    exit;
}

// Fetch submission with person and section info
$stmt = $db->prepare(
    'SELECT s.*,
            p.FullNameRus AS person_name, p.FullNameEngl AS person_name_eng,
            p.NamePhoto AS person_photo, p.AllUrlInSity AS person_path,
            p.DateIn AS person_date_birth, p.DateOut AS person_date_death,
            p.famous_for AS person_famous_for,
            ps.nameRus AS section_name, ps.nameEng AS section_name_eng, ps.path AS section_path
     FROM user_submissions s
     LEFT JOIN persons p ON p.Persons_id = s.KodPersons
     LEFT JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.id = :id AND s.user_id = :uid'
);
$stmt->execute([':id' => $id, ':uid' => $userId]);
$submission = $stmt->fetch();

if (!$submission) {
    header('Location: /user/submissions.php');
    exit;
}

$submission = fromDbArray($submission);
$pageTitle = $submission['title'] ?: 'Просмотр материала';

// Status display
$statusLabels = [
    'draft'              => 'Черновик',
    'pending'            => 'На проверке',
    'approved'           => 'Опубликовано',
    'rejected'           => 'Отклонено',
    'revision_requested' => 'На доработку',
];
$statusBadge = [
    'draft'              => 'badge-draft',
    'pending'            => 'badge-pending',
    'approved'           => 'badge-approved',
    'rejected'           => 'badge-rejected',
    'revision_requested' => 'badge-revision',
];

$canEdit = in_array($submission['status'], ['draft', 'revision_requested'], true);

// Person photo URL
$personPhotoUrl = '';
if (!empty($submission['person_photo']) && !empty($submission['person_path'])) {
    $personPhotoUrl = 'https://peoples.ru/photo/' . $submission['person_path'] . '/' . $submission['person_photo'];
}
$personDates = $submission['person_date_birth'] ?? '';
if (!empty($submission['person_date_death'])) {
    $personDates .= ' — ' . $submission['person_date_death'];
}
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/user/">Главная</a></li>
        <li class="breadcrumb-item"><a href="/user/submissions.php">Мои материалы</a></li>
        <li class="breadcrumb-item active"><?= htmlspecialchars($submission['title'] ?: 'Без названия', ENT_QUOTES, 'UTF-8') ?></li>
    </ol>
</nav>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Header -->
        <div class="d-flex align-items-center gap-3 mb-3">
            <span class="badge <?= $statusBadge[$submission['status']] ?? 'badge-draft' ?> fs-6">
                <?= $statusLabels[$submission['status']] ?? $submission['status'] ?>
            </span>
            <?php if ($submission['section_name']): ?>
            <span class="text-muted small">
                <?= htmlspecialchars($submission['section_name'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <?php endif; ?>
        </div>

        <h3 class="mb-3"><?= htmlspecialchars($submission['title'] ?: 'Без названия', ENT_QUOTES, 'UTF-8') ?></h3>

        <?php if (!empty($submission['epigraph'])): ?>
        <blockquote class="blockquote" style="border-left:3px solid var(--primary);padding-left:1rem;font-style:italic;color:#6c757d;font-size:.95rem;">
            <?= htmlspecialchars($submission['epigraph'], ENT_QUOTES, 'UTF-8') ?>
        </blockquote>
        <?php endif; ?>

        <!-- Moderator note -->
        <?php if (in_array($submission['status'], ['rejected', 'revision_requested'], true) && !empty($submission['moderator_note'])): ?>
        <div class="moderator-note">
            <div class="note-label">
                <i class="bi bi-chat-left-text me-1"></i>
                <?= $submission['status'] === 'rejected' ? 'Причина отклонения' : 'Комментарий модератора' ?>
            </div>
            <?= htmlspecialchars($submission['moderator_note'], ENT_QUOTES, 'UTF-8') ?>
            <?php if (!empty($submission['reviewed_at'])): ?>
            <div class="text-muted small mt-1">
                <?= date('d.m.Y H:i', strtotime($submission['reviewed_at'])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Photo -->
        <?php if (!empty($submission['photo_path'])): ?>
        <div class="mb-4">
            <img src="<?= htmlspecialchars($submission['photo_path'], ENT_QUOTES, 'UTF-8') ?>"
                 alt="" class="img-fluid rounded" style="max-height:500px">
        </div>
        <?php endif; ?>

        <!-- Content -->
        <div class="submission-content mb-4">
            <?= $submission['content'] ?? '' ?>
        </div>

        <?php if (!empty($submission['source_url'])): ?>
        <div class="text-muted small mb-3">
            <i class="bi bi-link-45deg me-1"></i>Источник:
            <a href="<?= htmlspecialchars($submission['source_url'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
                <?= htmlspecialchars($submission['source_url'], ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="d-flex gap-2 mt-4">
            <?php if ($canEdit): ?>
            <a href="/user/submit.php?edit=<?= (int)$submission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Редактировать
            </a>
            <?php endif; ?>

            <?php if ($submission['status'] === 'approved' && !empty($submission['section_path'])): ?>
            <?php
                $publishedUrl = 'https://peoples.ru/' . $submission['section_path'];
                if (!empty($submission['person_path'])) {
                    $publishedUrl .= '/' . $submission['person_path'];
                }
            ?>
            <a href="<?= htmlspecialchars($publishedUrl, ENT_QUOTES, 'UTF-8') ?>"
               class="btn btn-outline-success" target="_blank" rel="noopener">
                <i class="bi bi-box-arrow-up-right me-1"></i>Смотреть на сайте
            </a>
            <?php endif; ?>

            <a href="/user/submissions.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>К списку
            </a>
        </div>

        <div class="text-muted small mt-3">
            Создано: <?= $submission['created_at'] ? date('d.m.Y H:i', strtotime($submission['created_at'])) : '—' ?>
            <?php if ($submission['updated_at'] && $submission['updated_at'] !== $submission['created_at']): ?>
            &middot; Обновлено: <?= date('d.m.Y H:i', strtotime($submission['updated_at'])) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Person info card -->
        <?php if ($submission['KodPersons']): ?>
        <div class="card mb-3">
            <div class="card-body text-center">
                <?php if ($personPhotoUrl): ?>
                <img src="<?= htmlspecialchars($personPhotoUrl, ENT_QUOTES, 'UTF-8') ?>"
                     alt="<?= htmlspecialchars($submission['person_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     class="rounded-circle mb-3" style="width:100px;height:100px;object-fit:cover;background:#eee">
                <?php else: ?>
                <div class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3"
                     style="width:100px;height:100px">
                    <i class="bi bi-person fs-1 text-muted"></i>
                </div>
                <?php endif; ?>

                <h6 class="mb-1"><?= htmlspecialchars($submission['person_name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h6>
                <?php if (!empty($submission['person_name_eng'])): ?>
                <div class="text-muted small mb-1"><?= htmlspecialchars($submission['person_name_eng'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($personDates): ?>
                <div class="text-muted small"><?= htmlspecialchars($personDates, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if (!empty($submission['person_famous_for'])): ?>
                <div class="small mt-2"><?= htmlspecialchars($submission['person_famous_for'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if (!empty($submission['person_path'])): ?>
                <a href="https://peoples.ru/<?= htmlspecialchars($submission['person_path'], ENT_QUOTES, 'UTF-8') ?>"
                   class="btn btn-sm btn-outline-primary mt-2" target="_blank" rel="noopener">
                    Профиль на peoples.ru
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Submission meta -->
        <div class="card">
            <div class="card-body">
                <h6 class="card-title mb-3">Информация</h6>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">ID</dt>
                    <dd class="col-7">#<?= (int)$submission['id'] ?></dd>

                    <dt class="col-5 text-muted">Раздел</dt>
                    <dd class="col-7"><?= htmlspecialchars($submission['section_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></dd>

                    <dt class="col-5 text-muted">Статус</dt>
                    <dd class="col-7">
                        <span class="badge <?= $statusBadge[$submission['status']] ?? 'badge-draft' ?>">
                            <?= $statusLabels[$submission['status']] ?? $submission['status'] ?>
                        </span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
