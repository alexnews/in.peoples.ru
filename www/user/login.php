<?php
/**
 * Login page — no auth required.
 *
 * POST: authenticate user and redirect to /user/
 * GET:  display login form
 */

declare(strict_types=1);

$noAuth = true;
$pageTitle = 'Вход';

require_once __DIR__ . '/includes/header.php';

// If already logged in, redirect to dashboard
if ($currentUser !== null) {
    header('Location: /user/');
    exit;
}

$error = '';
$loginValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Попробуйте ещё раз.';
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);
        $loginValue = $login;

        try {
            $user = loginUser($login, $password);
            // loginUser() already creates the session via startSession()
            header('Location: /user/');
            exit;
        } catch (InvalidArgumentException $e) {
            $error = 'Неверный логин или пароль';
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'banned')) {
                $error = 'Ваш аккаунт заблокирован';
            } elseif (str_contains($msg, 'suspended')) {
                $error = 'Ваш аккаунт временно приостановлен';
            } else {
                $error = 'Ошибка входа. Попробуйте позже.';
            }
        }
    }
}
?>

<div class="auth-page">
    <div class="auth-card">
        <div class="brand"><span>in.</span>peoples.ru</div>
        <p class="text-center text-muted small mb-2">Платформа для авторов проекта <a href="https://www.peoples.ru" target="_blank">peoples.ru</a> &mdash; энциклопедии известных людей. Добавляйте биографии, новости, фотографии и другие материалы о знаменитостях.</p>
        <h5 class="text-center mb-3">Вход в личный кабинет</h5>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="/user/login.php" novalidate>
            <?= csrfField() ?>

            <div class="mb-3">
                <label for="login" class="form-label">Email или имя пользователя</label>
                <input type="text" class="form-control" id="login" name="login"
                       value="<?= htmlspecialchars($loginValue, ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="user@example.com" required autofocus>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="Ваш пароль" required>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember" value="1">
                <label class="form-check-label" for="remember">Запомнить меня</label>
            </div>

            <button type="submit" class="btn btn-primary w-100 mb-3">Войти</button>

            <div class="d-flex justify-content-between">
                <a href="#" class="text-muted small">Забыли пароль?</a>
                <a href="/user/register.php" class="small">Регистрация</a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
