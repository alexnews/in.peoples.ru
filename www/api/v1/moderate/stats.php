<?php

declare(strict_types=1);

/**
 * GET /api/v1/moderate/stats.php
 *
 * Moderation dashboard statistics.
 *
 * Returns: queue_size, approved_today, rejected_today, total_users,
 * top_contributors (top 10 by approved count this month), by_type (pending per section).
 *
 * Requires moderator role.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');

requireRole('moderator');

$db = getDb();

// Queue size (pending submissions)
$stmt = $db->query("SELECT COUNT(*) FROM user_submissions WHERE status = 'pending'");
$queueSize = (int) $stmt->fetchColumn();

// Approved today
$stmt = $db->query(
    "SELECT COUNT(*) FROM user_submissions
     WHERE status = 'approved' AND DATE(reviewed_at) = CURDATE()"
);
$approvedToday = (int) $stmt->fetchColumn();

// Rejected today
$stmt = $db->query(
    "SELECT COUNT(*) FROM user_submissions
     WHERE status = 'rejected' AND DATE(reviewed_at) = CURDATE()"
);
$rejectedToday = (int) $stmt->fetchColumn();

// Total users
$stmt = $db->query('SELECT COUNT(*) FROM users');
$totalUsers = (int) $stmt->fetchColumn();

// Active users (logged in within last 30 days)
$stmt = $db->query(
    "SELECT COUNT(*) FROM users
     WHERE last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
);
$activeUsers = (int) $stmt->fetchColumn();

// Top contributors this month (top 10 by approved submissions)
$stmt = $db->query(
    "SELECT u.id, u.username, u.display_name, u.reputation, u.avatar_path,
            COUNT(s.id) AS approved_count
     FROM users u
     INNER JOIN user_submissions s ON s.user_id = u.id
     WHERE s.status = 'approved'
       AND s.reviewed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY u.id
     ORDER BY approved_count DESC
     LIMIT 10"
);
$topContributors = fromDbRows($stmt->fetchAll());

// Pending count by section type
$stmt = $db->query(
    "SELECT ps.id AS section_id, ps.nameRus AS section_name, ps.nameEng AS section_name_eng,
            COUNT(s.id) AS pending_count
     FROM user_submissions s
     INNER JOIN peoples_section ps ON ps.id = s.section_id
     WHERE s.status = 'pending'
     GROUP BY ps.id
     ORDER BY pending_count DESC"
);
$byType = fromDbRows($stmt->fetchAll());

// Submissions this week (for trend)
$stmt = $db->query(
    "SELECT DATE(created_at) AS date, COUNT(*) AS count
     FROM user_submissions
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY DATE(created_at)
     ORDER BY date ASC"
);
$weeklyTrend = $stmt->fetchAll();

// Moderation activity today (by moderator)
$stmt = $db->query(
    "SELECT u.id, u.username, u.display_name,
            SUM(CASE WHEN ml.action = 'approve' THEN 1 ELSE 0 END) AS approved,
            SUM(CASE WHEN ml.action = 'reject' THEN 1 ELSE 0 END) AS rejected,
            SUM(CASE WHEN ml.action = 'request_revision' THEN 1 ELSE 0 END) AS revisions,
            COUNT(*) AS total_actions
     FROM users_moderation_log ml
     INNER JOIN users u ON u.id = ml.moderator_id
     WHERE ml.target_type = 'submission' AND DATE(ml.created_at) = CURDATE()
     GROUP BY u.id
     ORDER BY total_actions DESC"
);
$moderationActivity = fromDbRows($stmt->fetchAll());

$stats = [
    'queue_size'          => $queueSize,
    'approved_today'      => $approvedToday,
    'rejected_today'      => $rejectedToday,
    'total_users'         => $totalUsers,
    'active_users'        => $activeUsers,
    'top_contributors'    => $topContributors,
    'by_type'             => $byType,
    'weekly_trend'        => $weeklyTrend,
    'moderation_activity' => $moderationActivity,
];

jsonSuccess($stats);
