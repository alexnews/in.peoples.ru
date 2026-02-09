<?php
/**
 * Suggest a new person — requires auth.
 *
 * Simple form that creates a special submission (section_id=2, biography)
 * with person details for an admin to review and create in the persons table.
 */

declare(strict_types=1);

$pageTitle = 'Предложить персону';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = (int) $currentUser['id'];

$success = false;
$errors = [];
$values = [
    'name_rus' => '',
    'name_eng' => '',
    'birth_date' => '',
    'description' => '',
    'source_url' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ошибка безопасности. Попробуйте ещё раз.';
    } else {
        $values['name_rus'] = trim($_POST['name_rus'] ?? '');
        $values['name_eng'] = trim($_POST['name_eng'] ?? '');
        $values['birth_date'] = trim($_POST['birth_date'] ?? '');
        $values['description'] = trim($_POST['description'] ?? '');
        $values['source_url'] = trim($_POST['source_url'] ?? '');

        if (mb_strlen($values['name_rus'], 'UTF-8') < 2) {
            $errors['name_rus'] = 'Укажите имя на русском';
        }
        if (mb_strlen($values['description'], 'UTF-8') < 10) {
            $errors['description'] = 'Опишите, кто этот человек (минимум 10 символов)';
        }

        if (empty($errors)) {
            // Create a submission with the person suggestion
            $title = 'Новая персона: ' . $values['name_rus'];
            $content = '<p><strong>Имя (рус.):</strong> ' . htmlspecialchars($values['name_rus'], ENT_QUOTES, 'UTF-8') . '</p>';
            if ($values['name_eng']) {
                $content .= '<p><strong>Имя (англ.):</strong> ' . htmlspecialchars($values['name_eng'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            if ($values['birth_date']) {
                $content .= '<p><strong>Дата рождения:</strong> ' . htmlspecialchars($values['birth_date'], ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $content .= '<p><strong>Описание:</strong> ' . htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') . '</p>';

            $stmt = $db->prepare(
                'INSERT INTO user_submissions (user_id, section_id, title, content, epigraph, source_url, status, created_at, updated_at)
                 VALUES (:uid, 2, :title, :content, :epigraph, :source, \'pending\', NOW(), NOW())'
            );
            $stmt->execute([
                ':uid' => $userId,
                ':title' => toDb($title),
                ':content' => toDb($content),
                ':epigraph' => toDb($values['description']),
                ':source' => toDb($values['source_url']),
            ]);

            $success = true;
        }
    }
}
?>

<h4 class="mb-4">
    <i class="bi bi-person-plus me-2"></i>Предложить новую персону
</h4>

<div class="row">
    <div class="col-lg-8">
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle me-1"></i>
            Ваше предложение отправлено на рассмотрение модератору. Спасибо!
        </div>
        <a href="/user/" class="btn btn-outline-secondary">На главную</a>
        <a href="/user/suggest-person.php" class="btn btn-outline-primary ms-2">Предложить ещё</a>
        <?php else: ?>

        <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger py-2">
            <?= htmlspecialchars($errors['general'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <p class="text-muted mb-4">Если вы не нашли нужного человека в базе, предложите его добавить. Модератор рассмотрит заявку и создаст страницу персоны.</p>

        <form method="POST" action="/user/suggest-person.php" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="name_rus" class="form-label">Полное имя на русском <span class="text-danger">*</span></label>
                <input type="text" class="form-control <?= isset($errors['name_rus']) ? 'is-invalid' : '' ?>"
                       id="name_rus" name="name_rus"
                       value="<?= htmlspecialchars($values['name_rus'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Иван Петров" required>
                <?php if (isset($errors['name_rus'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['name_rus'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="name_eng" class="form-label">Полное имя на английском</label>
                <input type="text" class="form-control" id="name_eng" name="name_eng"
                       value="<?= htmlspecialchars($values['name_eng'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Ivan Petrov">
            </div>

            <div class="mb-3">
                <label for="birth_date" class="form-label">Дата рождения</label>
                <input type="date" class="form-control" id="birth_date" name="birth_date"
                       value="<?= htmlspecialchars($values['birth_date'], ENT_QUOTES, 'UTF-8') ?>">
            </div>

            <div class="mb-3">
                <label for="description" class="form-label">Кто этот человек <span class="text-danger">*</span></label>
                <textarea class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                          id="description" name="description" rows="4"
                          placeholder="Кратко опишите, чем известен этот человек..."><?= htmlspecialchars($values['description'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php if (isset($errors['description'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['description'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="source_url" class="form-label">Ссылка на источник (Википедия и т.п.)</label>
                <input type="url" class="form-control" id="source_url" name="source_url"
                       value="<?= htmlspecialchars($values['source_url'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="https://ru.wikipedia.org/wiki/...">
            </div>

            <button type="submit" class="btn btn-primary">Отправить на рассмотрение</button>
            <a href="/user/" class="btn btn-outline-secondary ms-2">Отмена</a>
        </form>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-info-circle me-1"></i>Подсказки</h6>
                <ul class="small text-muted mb-0" style="padding-left:1.1rem">
                    <li>Убедитесь, что персоны нет в базе (попробуйте разные варианты написания)</li>
                    <li>Укажите полное имя и фамилию</li>
                    <li>Напишите, чем известен этот человек</li>
                    <li>По возможности укажите ссылку на Википедию</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
