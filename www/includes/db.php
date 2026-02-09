<?php

declare(strict_types=1);

/**
 * Database connection singleton.
 *
 * Parses .env from project root for credentials and returns a shared PDO instance
 * configured for the `peoplesru` database with cp1251 charset.
 */

/** @var PDO|null Cached PDO instance */
$_dbInstance = null;

/**
 * Parse the project .env file and return its key-value pairs.
 *
 * @return array<string, string>
 * @throws RuntimeException If .env file cannot be read
 */
function parseEnvFile(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $envPath = dirname(__DIR__, 2) . '/.env';

    if (!is_file($envPath) || !is_readable($envPath)) {
        throw new RuntimeException("Environment file not found or not readable: {$envPath}");
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        throw new RuntimeException("Failed to read environment file: {$envPath}");
    }

    $env = [];
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $eqPos = strpos($line, '=');
        if ($eqPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $eqPos));
        $value = trim(substr($line, $eqPos + 1));

        // Strip surrounding quotes if present
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        $env[$key] = $value;
    }

    $cache = $env;
    return $cache;
}

/**
 * Get the shared PDO database connection.
 *
 * Creates the connection on first call; returns the cached instance on subsequent calls.
 * Connection uses cp1251 charset to match the existing peoplesru database encoding.
 *
 * @return PDO
 * @throws RuntimeException If required environment variables are missing
 * @throws PDOException If database connection fails
 */
function getDb(): PDO
{
    global $_dbInstance;

    if ($_dbInstance !== null) {
        return $_dbInstance;
    }

    $env = parseEnvFile();

    $required = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'];
    foreach ($required as $key) {
        if (!isset($env[$key]) || $env[$key] === '') {
            throw new RuntimeException("Missing required environment variable: {$key}");
        }
    }

    $host = $env['DB_HOST'];
    $port = $env['DB_PORT'] ?? '3306';
    $dbName = $env['DB_NAME'];
    $user = $env['DB_USER'];
    $pass = $env['DB_PASS'];

    $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=cp1251";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES cp1251',
    ];

    $_dbInstance = new PDO($dsn, $user, $pass, $options);

    return $_dbInstance;
}
