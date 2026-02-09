<?php
/**
 * Shared header for all user-facing pages.
 *
 * Expected variables:
 *   $pageTitle  — string, page title (shown in <title>)
 *   $currentUser — array|null, current user data (if page requires auth, this is set before include)
 *   $noAuth     — bool, if true skip auth redirect (for login/register pages)
 */

declare(strict_types=1);

ob_start();

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/encoding.php';
require_once dirname(__DIR__, 2) . '/includes/session.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/validation.php';
require_once dirname(__DIR__, 2) . '/includes/permissions.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';
require_once dirname(__DIR__, 2) . '/includes/response.php';

header('Content-Type: text/html; charset=UTF-8');

if (!isset($noAuth) || $noAuth !== true) {
    $currentUser = getCurrentUser();
    if ($currentUser === null) {
        header('Location: /user/login.php');
        exit;
    }
} else {
    $currentUser = $currentUser ?? getCurrentUser();
}

$pageTitle = ($pageTitle ?? 'Личный кабинет') . ' — in.peoples.ru';
$csrfToken = generateCsrfToken();
$isMod = $currentUser ? isModerator() : false;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="https://www.peoples.ru/favicon.ico" type="image/x-icon">

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- App CSS -->
    <link href="/assets/css/app.css" rel="stylesheet">
</head>
<body>

<?php if ($currentUser): ?>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom shadow-sm">
    <div class="container">
        <a class="navbar-brand" href="/"><span>in.</span>peoples.ru</a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Меню">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item">
                    <a class="nav-link" href="/user/">
                        <i class="bi bi-house-door me-1"></i>Главная
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/user/submissions.php">
                        <i class="bi bi-folder me-1"></i>Мои материалы
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/user/submit.php">
                        <i class="bi bi-plus-circle me-1"></i>Добавить
                    </a>
                </li>
                <?php if ($isMod): ?>
                <li class="nav-item">
                    <a class="nav-link" href="/moderate/">
                        <i class="bi bi-shield-check me-1"></i>Модерация
                    </a>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto mb-2 mb-lg-0 align-items-lg-center">
                <li class="nav-item me-2">
                    <span class="user-info d-none d-lg-inline">
                        <?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>
                        <span class="reputation ms-1" title="Репутация">
                            <i class="bi bi-star-fill text-warning" style="font-size:.7rem"></i>
                            <?= (int) ($currentUser['reputation'] ?? 0) ?>
                        </span>
                    </span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/user/profile.php">
                        <i class="bi bi-person-circle me-1"></i>Профиль
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" id="btn-logout">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<?php endif; ?>

<div id="flash-messages" class="position-fixed top-0 end-0 p-3" style="z-index:1080"></div>

<main class="container py-4">
