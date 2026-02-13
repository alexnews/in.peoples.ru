<?php

declare(strict_types=1);

/**
 * Dynamic XML Sitemap — /sitemap.xml
 *
 * Generates sitemap for all public booking pages, /adv/, /info-change/.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/encoding.php';

$db = getDb();

header('Content-Type: application/xml; charset=UTF-8');

$base = 'https://in.peoples.ru';
$today = date('Y-m-d');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
    <url>
        <loc><?= $base ?>/booking/</loc>
        <changefreq>weekly</changefreq>
        <priority>1.0</priority>
    </url>
    <url>
        <loc><?= $base ?>/booking/register/</loc>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
    <url>
        <loc><?= $base ?>/adv/</loc>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>
    <url>
        <loc><?= $base ?>/info-change/</loc>
        <changefreq>monthly</changefreq>
        <priority>0.5</priority>
    </url>
<?php
// Active categories
$catStmt = $db->query('SELECT slug FROM booking_categories WHERE is_active = 1 ORDER BY sort_order ASC');
while ($cat = $catStmt->fetch()) {
    $slug = htmlspecialchars($cat['slug'], ENT_XML1, 'UTF-8');
    echo "    <url>\n";
    echo "        <loc>{$base}/booking/category/{$slug}/</loc>\n";
    echo "        <changefreq>weekly</changefreq>\n";
    echo "        <priority>0.8</priority>\n";
    echo "    </url>\n";
}

// Active booking persons
$pStmt = $db->query(
    'SELECT DISTINCT p.path, p.Persons_id
     FROM booking_persons bp
     INNER JOIN persons p ON p.Persons_id = bp.person_id
     WHERE bp.is_active = 1 AND p.DateOut IS NULL AND p.path IS NOT NULL AND p.path != ""
     ORDER BY p.Persons_id ASC'
);
while ($row = $pStmt->fetch()) {
    $personSlug = htmlspecialchars(fromDb($row['path']), ENT_XML1, 'UTF-8');
    echo "    <url>\n";
    echo "        <loc>{$base}/booking/person/{$personSlug}/</loc>\n";
    echo "        <changefreq>weekly</changefreq>\n";
    echo "        <priority>0.7</priority>\n";
    echo "    </url>\n";
}

// Fan club pages (persons with confirmed fans)
$fanStmt = $db->query(
    'SELECT DISTINCT p.path
     FROM user_fan_club_members fcm
     INNER JOIN persons p ON p.Persons_id = fcm.person_id
     WHERE fcm.status = "confirmed" AND p.path IS NOT NULL AND p.path != ""
     ORDER BY p.Persons_id ASC'
);
while ($row = $fanStmt->fetch()) {
    $fanSlug = htmlspecialchars(fromDb($row['path']), ENT_XML1, 'UTF-8');
    echo "    <url>\n";
    echo "        <loc>{$base}/fan/{$fanSlug}/</loc>\n";
    echo "        <changefreq>weekly</changefreq>\n";
    echo "        <priority>0.6</priority>\n";
    echo "    </url>\n";
}
?>
</urlset>
