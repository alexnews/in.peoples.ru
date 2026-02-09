<?php

declare(strict_types=1);

/**
 * GET|POST /api/v1/moderate/users.php
 *
 * GET:  List users (filterable by search, role, status). Requires moderator.
 * POST: Manage users (ban, unban, promote, demote). Requires appropriate role.
 *
 * Requires moderator role minimum.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET', 'POST');

$moderator = requireRole('moderator');
$db = getDb();
$moderatorId = (int) $moderator['id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ------------------------------------------------------------------
    // GET: List users with filters
    // ------------------------------------------------------------------
    $search  = getInput('search');
    $role    = getInput('role');
    $status  = getInput('status');
    $page    = max(1, (int) getInput('page', 1));
    $perPage = min(100, max(1, (int) getInput('per_page', 20)));
    $offset  = ($page - 1) * $perPage;

    $where  = [];
    $params = [];

    if ($search !== null && trim((string) $search) !== '') {
        $searchDb = '%' . toDb(trim((string) $search)) . '%';
        $where[] = '(u.username LIKE :search1 OR u.display_name LIKE :search2 OR u.email LIKE :search3)';
        $params[':search1'] = $searchDb;
        $params[':search2'] = $searchDb;
        $params[':search3'] = $searchDb;
    }

    if ($role !== null && $role !== '') {
        $validRoles = ['user', 'moderator', 'admin'];
        if (in_array($role, $validRoles, true)) {
            $where[] = 'u.role = :role';
            $params[':role'] = $role;
        }
    }

    if ($status !== null && $status !== '') {
        $validStatuses = ['active', 'banned', 'suspended'];
        if (in_array($status, $validStatuses, true)) {
            $where[] = 'u.status = :status';
            $params[':status'] = $status;
        }
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Count total
    $countSql = "SELECT COUNT(*) FROM users u {$whereClause}";
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Fetch users
    $sql = "SELECT u.id, u.username, u.email, u.display_name, u.avatar_path,
                   u.role, u.status, u.reputation, u.bio,
                   u.last_login, u.login_ip, u.created_at, u.updated_at,
                   (SELECT COUNT(*) FROM user_submissions WHERE user_id = u.id) AS total_submissions,
                   (SELECT COUNT(*) FROM user_submissions WHERE user_id = u.id AND status = 'approved') AS approved_submissions
            FROM users u
            {$whereClause}
            ORDER BY u.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $items = fromDbRows($rows);

    jsonPaginated($items, $total, $page, $perPage);

} else {
    // ------------------------------------------------------------------
    // POST: Manage user (ban, unban, promote, demote)
    // ------------------------------------------------------------------
    requireCsrf();

    $targetUserId = getInput('user_id');
    $action       = getInput('action');
    $note         = trim((string) getInput('note', ''));

    if ($targetUserId === null || $targetUserId === '') {
        jsonError('User ID is required', 'VALIDATION_ERROR', 400);
    }
    $targetUserId = (int) $targetUserId;

    $validActions = ['ban', 'unban', 'promote_moderator', 'demote_to_user'];
    if (!in_array($action, $validActions, true)) {
        jsonError(
            'Action must be one of: ban, unban, promote_moderator, demote_to_user',
            'VALIDATION_ERROR',
            400
        );
    }

    // Fetch target user
    $stmt = $db->prepare('SELECT id, username, role, status FROM users WHERE id = :id');
    $stmt->execute([':id' => $targetUserId]);
    $targetUser = $stmt->fetch();

    if (!$targetUser) {
        jsonError('User not found', 'NOT_FOUND', 404);
    }

    // Cannot modify yourself
    if ($targetUserId === $moderatorId) {
        jsonError('You cannot modify your own account', 'FORBIDDEN', 403);
    }

    // Cannot modify admins (unless you are admin)
    if ($targetUser['role'] === 'admin' && $moderator['role'] !== 'admin') {
        jsonError('Cannot modify admin accounts', 'FORBIDDEN', 403);
    }

    // Role changes require admin
    if (in_array($action, ['promote_moderator', 'demote_to_user'], true)) {
        if ($moderator['role'] !== 'admin') {
            jsonError('Only admins can change user roles', 'FORBIDDEN', 403);
        }
    }

    // Execute the action
    switch ($action) {
        case 'ban':
            $db->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id')
               ->execute([':status' => 'banned', ':id' => $targetUserId]);

            // Destroy all sessions for the banned user
            $db->prepare('DELETE FROM user_sessions WHERE user_id = :uid')
               ->execute([':uid' => $targetUserId]);
            break;

        case 'unban':
            $db->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id')
               ->execute([':status' => 'active', ':id' => $targetUserId]);
            break;

        case 'promote_moderator':
            $db->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id')
               ->execute([':role' => 'moderator', ':id' => $targetUserId]);
            break;

        case 'demote_to_user':
            $db->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id')
               ->execute([':role' => 'user', ':id' => $targetUserId]);
            break;
    }

    // Log action
    $logStmt = $db->prepare(
        'INSERT INTO users_moderation_log (moderator_id, action, target_type, target_id, note, created_at)
         VALUES (:mod_id, :action, :target_type, :target_id, :note, NOW())'
    );
    $logStmt->execute([
        ':mod_id'      => $moderatorId,
        ':action'      => $action,
        ':target_type' => 'user',
        ':target_id'   => $targetUserId,
        ':note'        => $note !== '' ? toDb($note) : null,
    ]);

    // Fetch and return updated user
    $stmt = $db->prepare(
        'SELECT id, username, email, display_name, avatar_path, role, status,
                reputation, bio, last_login, created_at, updated_at
         FROM users WHERE id = :id'
    );
    $stmt->execute([':id' => $targetUserId]);
    $updatedUser = $stmt->fetch();

    jsonSuccess(fromDbArray($updatedUser));
}
