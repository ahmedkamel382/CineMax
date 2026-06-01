<?php
/**
 * CINEMAX — DATABASE CONNECTION
 * ============================================================
 * Loads credentials from config.php (one level above web root)
 * or falls back to environment variables.
 *
 * Place config.php here (NOT inside public_html):
 *   /home/youruser/config.php      ← safe, not web-accessible
 *   /home/youruser/public_html/    ← web root (files go here)
 * ============================================================
 */
$configPath = dirname(__DIR__) . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$host    = defined('DB_HOST') ? DB_HOST : ($_ENV['DB_HOST'] ?? '127.0.0.1');
$db      = defined('DB_NAME') ? DB_NAME : ($_ENV['DB_NAME'] ?? 'cinema');
$user    = defined('DB_USER') ? DB_USER : ($_ENV['DB_USER'] ?? 'root');
$pass    = defined('DB_PASS') ? DB_PASS : ($_ENV['DB_PASS'] ?? '');

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed.\n");
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Database connection failed.']);
    }
    exit;
}
