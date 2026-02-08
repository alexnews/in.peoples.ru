<?php
/**
 * in.peoples.ru — Landing page
 * Redirects to login or dashboard based on auth state
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/session.php';

$user = getCurrentUser();

if ($user) {
    header('Location: /user/index.php');
} else {
    header('Location: /user/login.php');
}
exit;
