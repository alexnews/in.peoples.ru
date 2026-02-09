<?php

declare(strict_types=1);

/**
 * POST /api/v1/auth/logout
 *
 * Log out the current user by destroying the active session.
 *
 * Requires authentication.
 */

require_once __DIR__ . '/../config.php';

requireMethod('POST');

requireAuth();

logoutUser();

jsonSuccess(['message' => 'Logged out successfully']);
