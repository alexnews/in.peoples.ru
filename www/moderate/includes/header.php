<?php

declare(strict_types=1);

ob_start();

/**
 * Shared header for moderation panel pages.
 *
 * Expects $pageTitle to be set before including this file.
 * Requires the user to be authenticated (getCurrentUser() must return non-null).
 */

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/encoding.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/permissions.php';
require_once __DIR__ . '/../../includes/csrf.php';

header('Content-Type: text/html; charset=UTF-8');

$currentUser = getCurrentUser();
$csrfToken = generateCsrfToken();

// Pending queue count for nav badge
$db = getDb();
$badgeStmt = $db->query("SELECT COUNT(*) FROM user_submissions WHERE status = 'pending'");
$pendingCount = (int) $badgeStmt->fetchColumn();

// Pending person suggestions count
$personBadgeStmt = $db->query("SELECT COUNT(*) FROM user_person_suggestions WHERE status = 'pending'");
$pendingPersonCount = (int) $personBadgeStmt->fetchColumn();

// New booking requests count
$bookingBadgeStmt = $db->query("SELECT COUNT(*) FROM booking_requests WHERE status = 'new'");
$newBookingCount = (int) $bookingBadgeStmt->fetchColumn();

// New booking applications (celebrity self-registration) count
$appBadgeStmt = $db->query("SELECT COUNT(*) FROM booking_applications WHERE status = 'new'");
$newApplicationCount = (int) $appBadgeStmt->fetchColumn();

// New info change requests count
$infoChangeBadgeStmt = $db->query("SELECT COUNT(*) FROM user_info_change_requests WHERE status = 'new'");
$newInfoChangeCount = (int) $infoChangeBadgeStmt->fetchColumn();

// New ad requests count
$adBadgeStmt = $db->query("SELECT COUNT(*) FROM user_ad_requests WHERE status = 'new'");
$newAdCount = (int) $adBadgeStmt->fetchColumn();

$pageTitle = $pageTitle ?? 'Модерация';
$currentPage = basename($_SERVER['SCRIPT_NAME']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> &mdash; Модерация peoples.ru</title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="/assets/css/moderate.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark mod-navbar">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center" href="/moderate/">
            <i class="bi bi-shield-check me-2"></i>
            <span>Модерация</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#modNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="modNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" href="/moderate/">
                        <i class="bi bi-speedometer2 me-1"></i>Дашборд
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'queue.php' ? 'active' : '' ?>" href="/moderate/queue.php">
                        <i class="bi bi-inbox me-1"></i>Очередь
                        <?php if ($pendingCount > 0): ?>
                            <span class="badge bg-warning text-dark ms-1 pending-badge"><?= $pendingCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'persons.php' ? 'active' : '' ?>" href="/moderate/persons.php">
                        <i class="bi bi-person-plus me-1"></i>Персоны
                        <?php if ($pendingPersonCount > 0): ?>
                            <span class="badge bg-warning text-dark ms-1 pending-badge"><?= $pendingPersonCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>" href="/moderate/users.php">
                        <i class="bi bi-people me-1"></i>Пользователи
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'newsletter.php' ? 'active' : '' ?>" href="/moderate/newsletter.php">
                        <i class="bi bi-envelope me-1"></i>Рассылка
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= in_array($currentPage, ['booking.php', 'booking-persons.php', 'booking-categories.php', 'booking-applications.php']) ? 'active' : '' ?>" href="/moderate/booking.php">
                        <i class="bi bi-calendar-event me-1"></i>Букинг
                        <?php $totalBookingBadge = $newBookingCount + $newApplicationCount; ?>
                        <?php if ($totalBookingBadge > 0): ?>
                            <span class="badge bg-danger ms-1 pending-badge"><?= $totalBookingBadge ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'info-changes.php' ? 'active' : '' ?>" href="/moderate/info-changes.php">
                        <i class="bi bi-pencil-square me-1"></i>Инфо
                        <?php if ($newInfoChangeCount > 0): ?>
                            <span class="badge bg-danger ms-1 pending-badge"><?= $newInfoChangeCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'ad-requests.php' ? 'active' : '' ?>" href="/moderate/ad-requests.php">
                        <i class="bi bi-megaphone me-1"></i>Реклама
                        <?php if ($newAdCount > 0): ?>
                            <span class="badge bg-danger ms-1 pending-badge"><?= $newAdCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentPage === 'log.php' ? 'active' : '' ?>" href="/moderate/log.php">
                        <i class="bi bi-journal-text me-1"></i>Журнал
                    </a>
                </li>
            </ul>
            <ul class="navbar-nav">
                <li class="nav-item">
                    <a class="nav-link text-light opacity-75" href="/user/" title="Вернуться на сайт">
                        <i class="bi bi-arrow-left me-1"></i>Вернуться на сайт
                    </a>
                </li>
                <?php if ($currentUser): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($currentUser['display_name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><span class="dropdown-item-text text-muted small"><?= htmlspecialchars(ucfirst($currentUser['role']), ENT_QUOTES, 'UTF-8') ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="/user/">Личный кабинет</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid py-4">
