<?php
/**
 * ITPMS — Shared auth/session bootstrap
 * ------------------------------------------------------------------
 * Included by index.php, manager.php, login.php, logout.php, and
 * change_password.php. Responsible for:
 *   - starting the session
 *   - opening the shared PDO connection
 *   - making sure a `users` table exists, seeding a default admin
 *     account (admin / admin123) the very first time it runs
 *   - require_login(), which every protected page/endpoint calls
 *
 * IMPORTANT: change the admin123 password immediately after first
 * login (there's a "Change password" link in the sidebar).
 */

if (session_status() === PHP_SESSION_NONE) {
    // Keep the login session alive for 8 hours of inactivity.
    ini_set('session.gc_maxlifetime', 8 * 60 * 60);
    session_set_cookie_params(8 * 60 * 60);
    session_start();
}

/* ============================ CONFIG — EDIT THESE 4 LINES ============================ */
define('DB_HOST', 'localhost');
define('DB_NAME', 'itpms2');
define('DB_USER', 'root');
define('DB_PASS', '');
/* ======================================================================================= */

function auth_fatal(string $message, string $detail = ''): void {
    if (isset($_GET['api']) || isset($_GET['requests_api'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => $message, 'detail' => $detail]));
    }
    die('<h2 style="font-family:sans-serif">' . htmlspecialchars($message) . '</h2>'
        . '<p style="font-family:sans-serif"><small>' . htmlspecialchars($detail) . '</small></p>');
}

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
    auth_fatal('Database connection failed. Check the DB_* constants in auth.php.', $e->getMessage());
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(60) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (id),
        UNIQUE KEY username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $userCount = (int) $pdo->query("SELECT COUNT(*) AS c FROM users")->fetch()['c'];
    if ($userCount === 0) {
        $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
        $stmt->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (PDOException $e) {
    auth_fatal('Could not set up the users table.', $e->getMessage());
}

try {
    // Lightweight day-to-day request log (separate from `projects`) —
    // see database.sql for the full write-up of why this exists.
    $pdo->exec("CREATE TABLE IF NOT EXISTS it_requests (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title VARCHAR(255) NOT NULL,
        requester VARCHAR(150) DEFAULT '',
        category ENUM('Hardware','Software','Account/Access','Network','Other') NOT NULL DEFAULT 'Other',
        status ENUM('Open','In Progress','Done') NOT NULL DEFAULT 'Open',
        notes TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
        resolved_at TIMESTAMP NULL DEFAULT NULL,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    auth_fatal('Could not set up the it_requests table.', $e->getMessage());
}

function current_user_id() {
    return $_SESSION['user_id'] ?? null;
}

function current_username() {
    return $_SESSION['username'] ?? null;
}

function is_logged_in(): bool {
    return current_user_id() !== null;
}

/**
 * Call at the top of every protected page and every API request.
 * Normal page loads get redirected to login.php; API requests
 * (?api=1) get a 401 JSON response instead, since the frontend's
 * fetch() calls can't follow an HTML redirect meaningfully.
 */
function require_login(): void {
    if (is_logged_in()) return;

    if (isset($_GET['api']) || isset($_GET['requests_api'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Session expired. Please log in again.']));
    }

    header('Location: login.php');
    exit;
}
