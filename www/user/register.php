<?php
/**
 * Registration page — no auth required.
 *
 * POST: register new user, start session, redirect to /user/
 * GET:  display registration form
 */

declare(strict_types=1);

$noAuth = true;
$pageTitle = 'Регистрация';

require_once __DIR__ . '/includes/header.php';

// If already logged in, redirect to dashboard
if ($currentUser !== null) {
    header('Location: /user/');
    exit;
}

$errors = [];
$values = [
    'username'     => '',
    'email'        => '',
    'display_name' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ошибка безопасности. Попробуйте ещё раз.';
    } else {
        // Honeypot check — if filled, it's a bot
        if (!empty($_POST['website'])) {
            $errors['general'] = 'Ошибка отправки формы.';
        }

        // Bot token check
        $botToken = $_POST['bot_token'] ?? '';
        if (empty($botToken) || !preg_match('/^ok_\d{10,13}$/', $botToken)) {
            $errors['bot_check'] = 'Подтвердите, что вы не робот';
        } else {
            $tokenTime = (int) substr($botToken, 3);
            $now = (int) (microtime(true) * 1000);
            // Token must be between 1 second and 10 minutes old
            if (($now - $tokenTime) < 1000 || ($now - $tokenTime) > 600000) {
                $errors['bot_check'] = 'Подтвердите, что вы не робот';
            }
        }

        $username    = trim($_POST['username'] ?? '');
        $email       = trim($_POST['email'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $password    = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $values['username']     = $username;
        $values['email']        = $email;
        $values['display_name'] = $displayName;

        // Client-side-style checks done server-side
        if ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Пароли не совпадают';
        }

        if (empty($errors)) {
            try {
                $user = registerUser($username, $email, $password, $displayName);

                // Start session for the newly registered user
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                startSession((int) $user['id'], $ip, $ua);

                header('Location: /user/');
                exit;
            } catch (InvalidArgumentException $e) {
                $decoded = json_decode($e->getMessage(), true);
                if (is_array($decoded)) {
                    $errors = array_merge($errors, $decoded);
                } else {
                    $errors['general'] = $e->getMessage();
                }
            } catch (RuntimeException $e) {
                $decoded = json_decode($e->getMessage(), true);
                if (is_array($decoded)) {
                    $errors = array_merge($errors, $decoded);
                } else {
                    $errors['general'] = $e->getMessage();
                }
            }
        }
    }
}

// Translate common English error messages to Russian
function translateError(string $msg): string
{
    $map = [
        'This username is already taken'       => 'Это имя пользователя уже занято',
        'This email is already registered'     => 'Этот email уже зарегистрирован',
        'Invalid email address'                => 'Некорректный email',
        'Username must be at least 3 characters long'  => 'Имя пользователя должно быть не менее 3 символов',
        'Username must not exceed 50 characters'       => 'Имя пользователя не должно превышать 50 символов',
        'Username may contain only letters, digits, underscores, and hyphens, and must start with a letter or digit'
            => 'Имя может содержать только буквы, цифры, _ и -, и должно начинаться с буквы или цифры',
        'Password must be at least 8 characters long'  => 'Пароль должен быть не менее 8 символов',
        'Password must not exceed 128 characters'      => 'Пароль не должен превышать 128 символов',
        'Password must contain at least one letter'    => 'Пароль должен содержать хотя бы одну букву',
        'Password must contain at least one digit'     => 'Пароль должен содержать хотя бы одну цифру',
        'Display name must be at least 2 characters long' => 'Отображаемое имя должно быть не менее 2 символов',
        'Display name must not exceed 100 characters'     => 'Отображаемое имя не должно превышать 100 символов',
    ];
    return $map[$msg] ?? $msg;
}
?>

<div class="auth-page">
    <div class="auth-card" style="max-width:480px">
        <div class="brand"><span>in.</span>peoples.ru</div>
        <h5 class="text-center mb-3">Создать аккаунт</h5>

        <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-danger py-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars(translateError($errors['general']), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/user/register.php" novalidate id="register-form">
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="username" class="form-label">Имя пользователя <span class="text-danger">*</span></label>
                <input type="text" class="form-control <?= isset($errors['username']) ? 'is-invalid' : '' ?>"
                       id="username" name="username"
                       value="<?= htmlspecialchars($values['username'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="ivan_petrov" required>
                <?php if (isset($errors['username'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars(translateError($errors['username']), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="form-text">От 3 до 50 символов: буквы, цифры, _ и -</div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                       id="email" name="email"
                       value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="user@example.com" required>
                <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars(translateError($errors['email']), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="display_name" class="form-label">Отображаемое имя <span class="text-danger">*</span></label>
                <input type="text" class="form-control <?= isset($errors['display_name']) ? 'is-invalid' : '' ?>"
                       id="display_name" name="display_name"
                       value="<?= htmlspecialchars($values['display_name'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="Иван Петров" required>
                <?php if (isset($errors['display_name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars(translateError($errors['display_name']), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Пароль <span class="text-danger">*</span></label>
                <input type="password" class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>"
                       id="password" name="password"
                       placeholder="Минимум 8 символов" required>
                <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars(translateError($errors['password']), ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <div class="form-text">Минимум 8 символов, одна буква и одна цифра</div>
            </div>

            <div class="mb-3">
                <label for="password_confirm" class="form-label">Подтвердите пароль <span class="text-danger">*</span></label>
                <input type="password" class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>"
                       id="password_confirm" name="password_confirm"
                       placeholder="Повторите пароль" required>
                <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <!-- Honeypot — hidden from humans, bots fill it -->
            <div style="position:absolute;left:-9999px" aria-hidden="true">
                <input type="text" name="website" tabindex="-1" autocomplete="off">
            </div>

            <!-- JS bot check -->
            <div class="mb-3">
                <div class="bot-check p-3 border rounded d-flex align-items-center gap-3 <?= isset($errors['bot_check']) ? 'border-danger' : '' ?>" id="bot-check-widget">
                    <div class="form-check mb-0">
                        <input type="checkbox" class="form-check-input" id="not-a-robot" style="width:1.4em;height:1.4em;cursor:pointer">
                        <label class="form-check-label ms-1" for="not-a-robot" style="cursor:pointer;user-select:none">Я не робот</label>
                    </div>
                    <div id="bot-check-status" class="ms-auto text-muted small"></div>
                </div>
                <input type="hidden" name="bot_token" id="bot-token" value="">
                <?php if (isset($errors['bot_check'])): ?>
                <div class="text-danger small mt-1"><?= htmlspecialchars($errors['bot_check'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Зарегистрироваться</button>

            <div class="text-center">
                <span class="text-muted">Уже есть аккаунт?</span>
                <a href="/user/login.php">Войти</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Bot check widget
    var checkbox = document.getElementById('not-a-robot');
    var tokenField = document.getElementById('bot-token');
    var status = document.getElementById('bot-check-status');
    var widget = document.getElementById('bot-check-widget');

    if (checkbox) {
        checkbox.addEventListener('change', function () {
            if (this.checked) {
                this.disabled = true;
                status.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                // Small delay to mimic verification
                setTimeout(function () {
                    tokenField.value = 'ok_' + Date.now();
                    status.innerHTML = '<i class="bi bi-check-circle-fill text-success" style="font-size:1.4em"></i>';
                    widget.classList.remove('border-danger');
                    widget.classList.add('border-success');
                }, 600 + Math.random() * 400);
            }
        });
    }

    var form = document.getElementById('register-form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        // Check bot verification
        if (!tokenField.value) {
            e.preventDefault();
            widget.classList.add('border-danger');
            return false;
        }
        var password = document.getElementById('password').value;
        var confirm = document.getElementById('password_confirm').value;
        var confirmField = document.getElementById('password_confirm');

        // Reset
        confirmField.classList.remove('is-invalid');

        if (password !== confirm) {
            e.preventDefault();
            confirmField.classList.add('is-invalid');
            var fb = confirmField.nextElementSibling;
            if (!fb || !fb.classList.contains('invalid-feedback')) {
                fb = document.createElement('div');
                fb.className = 'invalid-feedback';
                confirmField.parentNode.appendChild(fb);
            }
            fb.textContent = 'Пароли не совпадают';
            return false;
        }
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
