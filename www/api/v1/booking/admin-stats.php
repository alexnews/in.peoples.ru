<?php

declare(strict_types=1);

/**
 * GET /api/v1/booking/admin-stats.php
 *
 * Admin endpoint — booking dashboard statistics.
 * Requires admin role.
 */

require_once __DIR__ . '/../config.php';

requireMethod('GET');
requireRole('admin');

$db = getDb();

// Request counts by status
$stmt = $db->query(
    "SELECT status, COUNT(*) AS cnt FROM booking_requests GROUP BY status"
);
$statusCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['cnt'];
}

// Total requests
$totalRequests = array_sum($statusCounts);

// New requests (unprocessed)
$newCount = $statusCounts['new'] ?? 0;

// Requests this week
$stmt = $db->query(
    "SELECT COUNT(*) FROM booking_requests
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
);
$weeklyRequests = (int) $stmt->fetchColumn();

// Requests this month
$stmt = $db->query(
    "SELECT COUNT(*) FROM booking_requests
     WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')"
);
$monthlyRequests = (int) $stmt->fetchColumn();

// Active bookable persons
$stmt = $db->query("SELECT COUNT(*) FROM booking_persons WHERE is_active = 1");
$activePersons = (int) $stmt->fetchColumn();

// Active categories
$stmt = $db->query("SELECT COUNT(*) FROM booking_categories WHERE is_active = 1");
$activeCategories = (int) $stmt->fetchColumn();

// Top requested persons (this month)
$stmt = $db->query(
    "SELECT br.person_id, p.FullNameRus, p.NamePhoto, COUNT(*) AS request_count
     FROM booking_requests br
     INNER JOIN persons p ON p.Persons_id = br.person_id
     WHERE br.person_id IS NOT NULL
       AND br.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
     GROUP BY br.person_id
     ORDER BY request_count DESC
     LIMIT 10"
);
$topPersons = fromDbRows($stmt->fetchAll());

// Requests by day (last 30 days)
$stmt = $db->query(
    "SELECT DATE(created_at) AS date, COUNT(*) AS count
     FROM booking_requests
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
     GROUP BY DATE(created_at)
     ORDER BY date ASC"
);
$dailyTrend = $stmt->fetchAll();

// Requests by event type
$stmt = $db->query(
    "SELECT event_type, COUNT(*) AS cnt
     FROM booking_requests
     WHERE event_type IS NOT NULL AND event_type != ''
     GROUP BY event_type
     ORDER BY cnt DESC"
);
$byEventType = $stmt->fetchAll();

jsonSuccess([
    'total_requests'    => $totalRequests,
    'new_count'         => $newCount,
    'weekly_requests'   => $weeklyRequests,
    'monthly_requests'  => $monthlyRequests,
    'active_persons'    => $activePersons,
    'active_categories' => $activeCategories,
    'status_counts'     => $statusCounts,
    'top_persons'       => $topPersons,
    'daily_trend'       => $dailyTrend,
    'by_event_type'     => $byEventType,
]);
