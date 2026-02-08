<?php
/**
 * Migration runner
 * Usage: php SOURCE/MIGRATIONS/run_migrations.php [up|down]
 *
 * Runs all migration SQL files in order (001, 002, 003, 004).
 * With 'down' argument, runs rollbacks in reverse order.
 */

$direction = $argv[1] ?? 'up';

// Database connection — update these for your environment
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'peoplesru';
$user = getenv('DB_USER') ?: 'localalex';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$dbname};charset=cp1251",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "Connected to {$dbname}\n";
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . "\n");
}

$dir = __DIR__;

if ($direction === 'up') {
    $files = glob("{$dir}/[0-9]*_create_*.sql");
    sort($files);
    $files[] = "{$dir}/005_seed_admin_user.sql";
} elseif ($direction === 'down') {
    $files = glob("{$dir}/[0-9]*_rollback_*.sql");
    rsort($files); // reverse order for rollbacks
} else {
    die("Usage: php run_migrations.php [up|down]\n");
}

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    $filename = basename($file);
    echo "Running: {$filename} ... ";

    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        echo "OK\n";
    } catch (PDOException $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
