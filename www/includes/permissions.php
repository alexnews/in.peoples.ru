<?php

declare(strict_types=1);

/**
 * Permission and role-checking helpers.
 *
 * Role hierarchy: user < moderator < admin
 * Functions that require a certain role will send a JSON error and exit if
 * the current user does not meet the requirement.
 */

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/response.php';

/**
 * Role hierarchy levels. Higher number = more privileges.
 */
const ROLE_LEVELS = [
    'user'      => 1,
    'moderator' => 2,
    'admin'     => 3,
];

/**
 * Require authentication. Returns the current user or sends a 401 JSON error and exits.
 *
 * @return array User data row (UTF-8 encoded)
 */
function requireAuth(): array
{
    $user = getCurrentUser();

    if ($user === null) {
        jsonError('Authentication required', 'UNAUTHORIZED', 401);
    }

    return $user;
}

/**
 * Require a minimum role level. Returns the current user or sends a 403 JSON error and exits.
 *
 * Role hierarchy: user < moderator < admin.
 * Calling requireRole('moderator') allows moderators and admins.
 * Calling requireRole('admin') allows only admins.
 *
 * @param string $minRole Minimum required role ('user', 'moderator', 'admin')
 * @return array User data row (UTF-8 encoded)
 */
function requireRole(string $minRole): array
{
    $user = requireAuth();

    $userLevel = ROLE_LEVELS[$user['role']] ?? 0;
    $requiredLevel = ROLE_LEVELS[$minRole] ?? 0;

    if ($userLevel < $requiredLevel) {
        jsonError('Insufficient permissions', 'FORBIDDEN', 403);
    }

    return $user;
}

/**
 * Check whether any user is currently logged in.
 *
 * @return bool
 */
function isLoggedIn(): bool
{
    return getCurrentUser() !== null;
}

/**
 * Check whether the current user has moderator or admin role.
 *
 * @return bool
 */
function isModerator(): bool
{
    $user = getCurrentUser();
    if ($user === null) {
        return false;
    }

    $level = ROLE_LEVELS[$user['role']] ?? 0;
    return $level >= ROLE_LEVELS['moderator'];
}

/**
 * Check whether the current user has admin role.
 *
 * @return bool
 */
function isAdmin(): bool
{
    $user = getCurrentUser();
    if ($user === null) {
        return false;
    }

    return $user['role'] === 'admin';
}
