<?php
/**
 * ITPMS — Database configuration
 * Update these four constants with your hosting provider's database credentials.
 * On most shared hosts (cPanel, etc.) DB_HOST is "localhost".
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'itpms');
define('DB_USER', 'root');
define('DB_PASS', '');

// Show DB errors as JSON instead of a blank white page while you're setting things up.
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database connection failed. Check config.php credentials.', 'detail' => $e->getMessage()]));
}
