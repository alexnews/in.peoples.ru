<?php
/**
 * Profile page — requires auth.
 *
 * View/edit user profile (display_name, email, bio) and change password.
 * Submission statistics displayed.
 * POST: update profile via updateProfile() / changePassword()
 */

declare(strict_types=1);

$pageTitle = 'Профиль';
require_once __DIR__ . '/includes/header.php';

$db = getDb();
$userId = (int) $currentUser['id'];

$successMsg = '';
$errors = [];
$passwordErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ошибка безопасности. Попробуйте ещё раз.';
    } else {
        $action = $_POST['action'] ?? 'profile';

        if ($action === 'profile') {
            // Update profile fields
            $data = [];
            $displayName = trim($_POST['display_name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $bio = $_POST['bio'] ?? null;

            if ($displayName !== '') {
                $data['display_name'] = $displayName;
            }
            if ($email !== '') {
                $data['email'] = $email;
            }
            if ($bio !== null) {
                $data['bio'] = $bio === '' ? null : $bio;
            }

            if (!empty($data)) {
                try {
                    $updatedUser = updateProfile($userId, $data);
                    // Refresh currentUser
                    $currentUser = $updatedUser;
                    $successMsg = 'Профиль обновлён';
                } catch (InvalidArgumentException $e) {
                    $decoded = json_decode($e->getMessage(), true);
                    if (is_array($decoded)) {
                        $errors = $decoded;
                    } else {
                        $errors['general'] = $e->getMessage();
                    }
                } catch (RuntimeException $e) {
                    $decoded = json_decode($e->getMessage(), true);
                    if (is_array($decoded)) {
                        $errors = $decoded;
                    } else {
                        $errors['general'] = $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'password') {
            // Change password
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';

            if ($newPassword !== $confirmPassword) {
                $passwordErrors['confirm_password'] = 'Пароли не совпадают';
            } else {
                try {
                    changePassword($userId, $currentPassword, $newPassword);
                    $successMsg = 'Пароль изменён';
                } catch (InvalidArgumentException $e) {
                    $msg = $e->getMessage();
                    if (str_contains($msg, 'Current password is incorrect')) {
                        $passwordErrors['current_password'] = 'Неверный текущий пароль';
                    } elseif (str_contains($msg, 'at least 8')) {
                        $passwordErrors['new_password'] = 'Пароль должен быть не менее 8 символов';
                    } elseif (str_contains($msg, 'letter')) {
                        $passwordErrors['new_password'] = 'Пароль должен содержать хотя бы одну букву';
                    } elseif (str_contains($msg, 'digit')) {
                        $passwordErrors['new_password'] = 'Пароль должен содержать хотя бы одну цифру';
                    } else {
                        $passwordErrors['general'] = $msg;
                    }
                }
            }
        }
    }
}

// Fetch submission stats
$stmt = $db->prepare(
    'SELECT status, COUNT(*) AS cnt
     FROM user_submissions
     WHERE user_id = :uid
     GROUP BY status'
);
$stmt->execute([':uid' => $userId]);
$rows = $stmt->fetchAll();

$stats = [
    'total'              => 0,
    'draft'              => 0,
    'pending'            => 0,
    'approved'           => 0,
    'rejected'           => 0,
    'revision_requested' => 0,
];
foreach ($rows as $row) {
    $stats[$row['status']] = (int) $row['cnt'];
    $stats['total'] += (int) $row['cnt'];
}

// Translate errors
function trProfileError(string $msg): string
{
    $map = [
        'Display name must be at least 2 characters long' => 'Отображаемое имя должно быть не менее 2 символов',
        'Display name must not exceed 100 characters'     => 'Отображаемое имя не должно превышать 100 символов',
        'Invalid email address'                           => 'Некорректный email',
        'This email is already registered by another user' => 'Этот email уже используется другим пользователем',
        'Bio must not exceed 5000 characters'             => 'Биография не должна превышать 5000 символов',
    ];
    return $map[$msg] ?? $msg;
}
?>

<h4 class="mb-4">
    <i class="bi bi-person-circle me-2"></i>Профиль
</h4>

<?php if ($successMsg): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-1"></i>
    <?= htmlspecialchars($successMsg, ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!empty($errors['general'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-1"></i>
    <?= htmlspecialchars(trProfileError($errors['general']), ENT_QUOTES, 'UTF-8') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <!-- Profile form -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Личная информация</h6>
            </div>
            <div class="card-body">
                <form method="POST" action="/user/profile.php" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="profile">

                    <div class="mb-3">
                        <label class="form-label text-muted small">Имя пользователя</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($currentUser['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" disabled readonly>
                        <div class="form-text">Имя пользователя нельзя изменить</div>
                    </div>

                    <div class="mb-3">
                        <label for="display_name" class="form-label">Отображаемое имя</label>
                        <input type="text" class="form-control <?= isset($errors['display_name']) ? 'is-invalid' : '' ?>"
                               id="display_name" name="display_name"
                               value="<?= htmlspecialchars($currentUser['display_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               required>
                        <?php if (isset($errors['display_name'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars(trProfileError($errors['display_name']), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                               id="email" name="email"
                               value="<?= htmlspecialchars($currentUser['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                               required>
                        <?php if (isset($errors['email'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars(trProfileError($errors['email']), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="bio" class="form-label">О себе</label>
                        <textarea class="form-control <?= isset($errors['bio']) ? 'is-invalid' : '' ?>"
                                  id="bio" name="bio" rows="4"
                                  placeholder="Расскажите немного о себе..."><?= htmlspecialchars($currentUser['bio'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                        <?php if (isset($errors['bio'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars(trProfileError($errors['bio']), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Сохранить
                    </button>
                </form>
            </div>
        </div>

        <!-- Password change -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Изменить пароль</h6>
            </div>
            <div class="card-body">
                <?php if (!empty($passwordErrors['general'])): ?>
                <div class="alert alert-danger py-2">
                    <?= htmlspecialchars($passwordErrors['general'], ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="/user/profile.php" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="password">

                    <div class="mb-3">
                        <label for="current_password" class="form-label">Текущий пароль</label>
                        <input type="password" class="form-control <?= isset($passwordErrors['current_password']) ? 'is-invalid' : '' ?>"
                               id="current_password" name="current_password" required>
                        <?php if (isset($passwordErrors['current_password'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($passwordErrors['current_password'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label for="new_password" class="form-label">Новый пароль</label>
                        <input type="password" class="form-control <?= isset($passwordErrors['new_password']) ? 'is-invalid' : '' ?>"
                               id="new_password" name="new_password" required>
                        <?php if (isset($passwordErrors['new_password'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($passwordErrors['new_password'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <div class="form-text">Минимум 8 символов, одна буква и одна цифра</div>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Подтвердите новый пароль</label>
                        <input type="password" class="form-control <?= isset($passwordErrors['confirm_password']) ? 'is-invalid' : '' ?>"
                               id="confirm_password" name="confirm_password" required>
                        <?php if (isset($passwordErrors['confirm_password'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($passwordErrors['confirm_password'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-outline-primary">
                        <i class="bi bi-key me-1"></i>Изменить пароль
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <!-- Stats sidebar -->
        <div class="card mb-3">
            <div class="card-header">
                <h6 class="mb-0">Статистика</h6>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-8 text-muted">Всего материалов</dt>
                    <dd class="col-4 text-end fw-bold"><?= $stats['total'] ?></dd>

                    <dt class="col-8 text-muted">Черновики</dt>
                    <dd class="col-4 text-end"><?= $stats['draft'] ?></dd>

                    <dt class="col-8 text-muted">На проверке</dt>
                    <dd class="col-4 text-end text-warning"><?= $stats['pending'] ?></dd>

                    <dt class="col-8 text-muted">Опубликовано</dt>
                    <dd class="col-4 text-end text-success"><?= $stats['approved'] ?></dd>

                    <dt class="col-8 text-muted">Отклонено</dt>
                    <dd class="col-4 text-end text-danger"><?= $stats['rejected'] ?></dd>

                    <dt class="col-8 text-muted">На доработку</dt>
                    <dd class="col-4 text-end" style="color:#ffc107"><?= $stats['revision_requested'] ?></dd>
                </dl>
                <hr>
                <dl class="row mb-0">
                    <dt class="col-8 text-muted">Репутация</dt>
                    <dd class="col-4 text-end fw-bold" style="color:var(--primary)">
                        <i class="bi bi-star-fill text-warning" style="font-size:.7rem"></i>
                        <?= (int) ($currentUser['reputation'] ?? 0) ?>
                    </dd>
                </dl>
            </div>
        </div>

        <!-- Account info -->
        <div class="card">
            <div class="card-body">
                <h6 class="card-title mb-3">Аккаунт</h6>
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Роль</dt>
                    <dd class="col-7">
                        <?php
                        $roleLabels = [
                            'user'      => 'Пользователь',
                            'moderator' => 'Модератор',
                            'admin'     => 'Администратор',
                        ];
                        echo $roleLabels[$currentUser['role']] ?? $currentUser['role'];
                        ?>
                    </dd>

                    <dt class="col-5 text-muted">Регистрация</dt>
                    <dd class="col-7">
                        <?= $currentUser['created_at'] ? date('d.m.Y', strtotime($currentUser['created_at'])) : '—' ?>
                    </dd>

                    <dt class="col-5 text-muted">Последний вход</dt>
                    <dd class="col-7">
                        <?= $currentUser['last_login'] ? date('d.m.Y H:i', strtotime($currentUser['last_login'])) : '—' ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
