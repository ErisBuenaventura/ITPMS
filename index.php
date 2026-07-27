<?php
/**
 * ITPMS — IT Project Management System (single-file build, v2)
 * ------------------------------------------------------------------
 * HOW TO USE
 *   1. Create a MySQL database and import database.sql into it.
 *   2. Edit the 4 constants right below with your DB credentials.
 *   3. Copy this ENTIRE file, paste it into a file named index.php on
 *      your server, and open it in a browser.
 *   4. Log in with admin / admin123 (created automatically on first run),
 *      then use the "Change password" link in the sidebar to set your own.
 * ------------------------------------------------------------------
 */

session_start();

/* ============================ 1. CONFIG — EDIT THESE 4 LINES ============================ */
define('DB_HOST', 'localhost');
define('DB_NAME', 'itpms');
define('DB_USER', 'root');
define('DB_PASS', '');
/* =========================================================================================== */

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
    if (isset($_GET['api'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => 'Database connection failed. Check the DB_* constants at the top of index.php.', 'detail' => $e->getMessage()]));
    }
    die('<h2 style="font-family:sans-serif">Database connection failed.</h2><p style="font-family:sans-serif">Check the DB_* constants at the top of index.php, and make sure you imported database.sql.<br><small>' . htmlspecialchars($e->getMessage()) . '</small></p>');
}

/* ---- self-seed a default admin user the first time this runs ---- */
try {
    $uc = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
    if ($uc == 0) {
        $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)")
            ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (PDOException $e) {
    $msg = 'The `users` table is missing. Please import the latest database.sql (it adds a users table for login) — see the comment at the top of that file if you already imported an older version.';
    if (isset($_GET['api'])) {
        http_response_code(500);
        header('Content-Type: application/json');
        die(json_encode(['error' => $msg]));
    }
    die('<h2 style="font-family:sans-serif">Missing `users` table.</h2><p style="font-family:sans-serif">' . htmlspecialchars($msg) . '</p>');
}

/* ---- logout ---- */
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

/* ---- login form submit ---- */
$loginError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_username']) && !isset($_GET['api'])) {
    $u = trim($_POST['login_username']);
    $p = $_POST['login_password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$u]);
    $user = $stmt->fetch();
    if ($user && password_verify($p, $user['password_hash'])) {
        $_SESSION['user'] = $user['username'];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $loginError = 'Invalid username or password.';
}

$isLoggedIn = isset($_SESSION['user']);

/* ============================ 2. API — every AJAX call hits index.php?api=1 ============================ */
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    if (!$isLoggedIn) {
        http_response_code(401);
        die(json_encode(['error' => 'Not authenticated. Please log in.']));
    }

    function read_json_body() {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    function today_str() { return date('Y-m-d'); }
    function api_fail($code, $message) {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }

    /* ---- change password (its own small action, separate from project CRUD) ---- */
    if (isset($_GET['action']) && $_GET['action'] === 'change_password') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') api_fail(405, 'Method not allowed.');
        $data = read_json_body();
        $current = $data['current_password'] ?? '';
        $new     = $data['new_password'] ?? '';
        if (strlen($new) < 6) api_fail(422, 'New password must be at least 6 characters.');

        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_SESSION['user']]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($current, $user['password_hash'])) api_fail(401, 'Current password is incorrect.');

        $pdo->prepare("UPDATE users SET password_hash = ? WHERE username = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user']]);
        echo json_encode(['success' => true]);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $id     = isset($_GET['id']) ? trim($_GET['id']) : null;

    $VALID_STATUSES   = ['Completed', 'Ongoing', 'Onhold', 'Cancelled', 'Not Started'];
    $VALID_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    function next_project_id(PDO $pdo) {
        $stmt = $pdo->query("SELECT id FROM projects");
        $max = 999;
        foreach ($stmt->fetchAll() as $row) {
            if (preg_match('/PRJ-(\d+)/', $row['id'], $m)) $max = max($max, (int) $m[1]);
        }
        return 'PRJ-' . ($max + 1);
    }

    switch ($method) {

        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
                $stmt->execute([$id]);
                $project = $stmt->fetch();
                if (!$project) api_fail(404, 'Project not found.');

                $hist = $pdo->prepare("SELECT entry_date AS date, progress FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
                $hist->execute([$id]);
                $project['history']  = $hist->fetchAll();
                $project['budget']   = (float) $project['budget'];
                $project['progress'] = (int) $project['progress'];

                echo json_encode($project);
            } else {
                $rows = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
                foreach ($rows as &$r) {
                    $r['budget']   = (float) $r['budget'];
                    $r['progress'] = (int) $r['progress'];
                }
                echo json_encode($rows);
            }
            break;

        case 'POST':
            $data = read_json_body();
            $name = trim($data['name'] ?? '');
            if ($name === '') api_fail(422, 'Project name is required.');

            $status   = in_array($data['status'] ?? '', $VALID_STATUSES) ? $data['status'] : 'Not Started';
            $priority = in_array($data['priority'] ?? '', $VALID_PRIORITIES) ? $data['priority'] : 'Medium';
            $progress = max(0, min(100, (int) ($data['progress'] ?? 0)));
            $owner    = trim($data['owner'] ?? '');
            $start    = !empty($data['start']) ? $data['start'] : null;
            $end      = !empty($data['end']) ? $data['end'] : null;
            $budget   = (float) ($data['budget'] ?? 0);
            $desc     = trim($data['description'] ?? '');
            $newId    = next_project_id($pdo);

            $stmt = $pdo->prepare("INSERT INTO projects (id, name, status, progress, owner, priority, start_date, end_date, budget, description)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$newId, $name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc]);

            $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress) VALUES (?, ?, ?)");
            $h->execute([$newId, today_str(), $progress]);

            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$newId]);
            $project = $stmt->fetch();
            $project['budget']   = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];
            $project['history']  = [['date' => today_str(), 'progress' => $progress]];

            http_response_code(201);
            echo json_encode($project);
            break;

        case 'PUT':
            if (!$id) api_fail(400, 'Missing project id.');
            $data = read_json_body();

            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) api_fail(404, 'Project not found.');

            $name     = trim($data['name'] ?? $existing['name']);
            $status   = in_array($data['status'] ?? '', $VALID_STATUSES) ? $data['status'] : $existing['status'];
            $priority = in_array($data['priority'] ?? '', $VALID_PRIORITIES) ? $data['priority'] : $existing['priority'];
            $progress = isset($data['progress']) ? max(0, min(100, (int) $data['progress'])) : (int) $existing['progress'];
            $owner    = trim($data['owner'] ?? $existing['owner']);
            $start    = array_key_exists('start', $data) ? (!empty($data['start']) ? $data['start'] : null) : $existing['start_date'];
            $end      = array_key_exists('end', $data) ? (!empty($data['end']) ? $data['end'] : null) : $existing['end_date'];
            $budget   = isset($data['budget']) ? (float) $data['budget'] : (float) $existing['budget'];
            $desc     = trim($data['description'] ?? $existing['description']);

            $stmt = $pdo->prepare("UPDATE projects SET name=?, status=?, progress=?, owner=?, priority=?, start_date=?, end_date=?, budget=?, description=? WHERE id=?");
            $stmt->execute([$name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $id]);

            $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress) VALUES (?, ?, ?)
                                 ON DUPLICATE KEY UPDATE progress = VALUES(progress)");
            $h->execute([$id, today_str(), $progress]);

            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch();
            $project['budget']   = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];

            $hist = $pdo->prepare("SELECT entry_date AS date, progress FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
            $hist->execute([$id]);
            $project['history'] = $hist->fetchAll();

            echo json_encode($project);
            break;

        case 'DELETE':
            if (!$id) api_fail(400, 'Missing project id.');
            $stmt = $pdo->prepare("DELETE FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) api_fail(404, 'Project not found.');
            echo json_encode(['success' => true]);
            break;

        default:
            api_fail(405, 'Method not allowed.');
    }
    exit;
}
/* ============================ end API ============================ */

/* ============================ 3. LOGIN PAGE (shown if not authenticated) ============================ */
if (!$isLoggedIn) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ITPMS — Log in</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; height: 100%; font-family: 'Inter', sans-serif; background: #F5F6F8; color: #1B2430; }
  .login-wrap { min-height: 100%; display: flex; align-items: center; justify-content: center; padding: 20px; }
  .login-card { background: #fff; border: 1px solid #DCE0E6; border-radius: 14px; padding: 32px 30px; width: 100%; max-width: 360px; box-shadow: 0 20px 50px rgba(27,36,48,0.08); text-align: center; }
  .brand-mark { width: 44px; height: 44px; border-radius: 10px; background: #2F5D8A; color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 16px; margin: 0 auto 14px; }
  .login-card h1 { font-family: 'Space Grotesk', sans-serif; font-size: 20px; margin: 0 0 4px; }
  .login-card > p { font-size: 13px; color: #5B6472; margin: 0 0 20px; }
  .login-error { background: #FBE7E5; color: #C4483C; font-size: 12.5px; font-weight: 600; padding: 8px 12px; border-radius: 8px; margin-bottom: 16px; }
  .login-card form { display: flex; flex-direction: column; gap: 12px; text-align: left; }
  .login-card label { font-size: 12.5px; font-weight: 600; color: #5B6472; display: flex; flex-direction: column; gap: 6px; }
  .login-card input { font-family: 'Inter', sans-serif; font-size: 14px; padding: 10px 12px; border-radius: 8px; border: 1px solid #DCE0E6; background: #FAFBFC; }
  .login-card input:focus { outline: 2px solid rgba(47,93,138,0.2); border-color: #2F5D8A; }
  .login-card button { margin-top: 6px; background: #2F5D8A; color: #fff; border: none; border-radius: 8px; padding: 11px; font-size: 14px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; }
  .login-card button:hover { background: #274d74; }
  .login-hint { font-size: 11.5px; color: #8A8F98; margin: 18px 0 0; line-height: 1.5; }
</style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="brand-mark">IT</div>
      <h1>ITPMS</h1>
      <p>Sign in to manage your projects.</p>
      <?php if ($loginError): ?><div class="login-error"><?= htmlspecialchars($loginError) ?></div><?php endif; ?>
      <form method="post">
        <label>Username <input type="text" name="login_username" required autofocus></label>
        <label>Password <input type="password" name="login_password" required></label>
        <button type="submit">Log in</button>
      </form>
      <p class="login-hint">Login your account.</p>
    </div>
  </div>
</body>
</html>
<?php
    exit;
}
/* ============================ end login page — authenticated app continues below ============================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>ITPMS — IT Project Management System</title>
<script>(function(){ var t = localStorage.getItem('itpms-theme') || 'light'; document.documentElement.setAttribute('data-theme', t); })();</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<style>
/* ---------------------------------------------------------------
   ITPMS design tokens (light + dark)
   Palette:  bg #F5F6F8 · surface #FFFFFF · ink #1B2430 · accent #2F5D8A
   Status:   Completed #2F9E6B · Ongoing #2F5D8A · On Hold #D6A419
             Cancelled #C4483C · Not Started #8A8F98
   Type:     Display "Space Grotesk" · Body "Inter" · Data "IBM Plex Mono"
------------------------------------------------------------------*/
* { box-sizing: border-box; }
:root {
  --bg: #F5F6F8; --surface: #FFFFFF; --ink: #1B2430; --ink-soft: #5B6472; --border: #DCE0E6;
  --surface-alt: #EDEFF3; --divider: #F2F3F5; --input-bg: #FAFBFC; --muted: #8A8F98;
  --accent: #2F5D8A; --accent-soft: #E8EFF6;
  --completed: #2F9E6B; --completed-soft: #E4F5EC;
  --ongoing: #2F5D8A; --ongoing-soft: #E8EFF6;
  --onhold: #D6A419; --onhold-soft: #FBF1DA;
  --cancelled: #C4483C; --cancelled-soft: #FBE7E5;
  --notstarted: #8A8F98; --notstarted-soft: #EEEFF1;
  --shadow: rgba(27,36,48,0.08);
}
[data-theme="dark"] {
  --bg: #12161C; --surface: #1A2028; --ink: #E7EAEE; --ink-soft: #9AA3B2; --border: #2A313D;
  --surface-alt: rgba(255,255,255,0.06); --divider: rgba(255,255,255,0.08); --input-bg: #202730; --muted: #8791A3;
  --accent: #4A7FB0; --accent-soft: rgba(74,127,176,0.16);
  --shadow: rgba(0,0,0,0.35);
}
html, body { margin: 0; padding: 0; height: 100%; background: var(--bg); color: var(--ink); font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; transition: background .15s, color .15s; }
.mono { font-family: 'IBM Plex Mono', monospace; }
.app-shell { display: flex; min-height: 100vh; }

/* ============ SIDEBAR (stays dark in both themes) ============ */
.sidebar { width: 232px; flex-shrink: 0; background: #1B2430; color: #E7EAEE; display: flex; flex-direction: column; padding: 20px 16px; position: sticky; top: 0; height: 100vh; z-index: 30; }
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 4px 22px; }
.brand-mark { width: 34px; height: 34px; border-radius: 8px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; flex-shrink: 0; }
.brand-name { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; line-height: 1.1; color: #fff; }
.brand-sub { font-size: 10.5px; color: #8B94A3; letter-spacing: 0.02em; }
.btn-new-project { width: 100%; margin-bottom: 18px; }
.side-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; border: none; background: transparent; color: #B7BECB; font-size: 13.5px; font-weight: 600; cursor: pointer; text-align: left; text-decoration: none; font-family: 'Inter', sans-serif; transition: background .15s, color .15s; width: 100%; }
.nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
.nav-item-active { background: var(--accent); color: #fff; }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.sidebar-footer { display: flex; flex-direction: column; gap: 2px; font-size: 11px; color: #6B7385; padding: 10px 4px 0; border-top: 1px solid rgba(255,255,255,0.08); margin-top: 12px; }
.sidebar-user { display: flex; align-items: center; gap: 8px; padding: 8px 12px 4px; color: #B7BECB; font-size: 12.5px; font-weight: 600; }
.sidebar-user svg { width: 15px; height: 15px; }
.sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(27,36,48,0.45); z-index: 25; }
.sidebar-toggle { display: none; position: fixed; top: 14px; left: 14px; z-index: 40; width: 38px; height: 38px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface); align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px var(--shadow); color: var(--ink); }

/* ============ MAIN ============ */
.app-main { flex: 1; min-width: 0; padding: clamp(16px, 3vw, 28px) clamp(16px, 4vw, 32px) 48px;
  background-image: repeating-linear-gradient(0deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px), repeating-linear-gradient(90deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px); }
.view-hidden { display: none !important; }
.view-header { margin-bottom: 20px; }
.view-header h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(19px, 3.4vw, 22px); font-weight: 700; margin: 0 0 4px; }
.view-sub { font-size: 13px; color: var(--ink-soft); margin: 0; }

/* ============ buttons ============ */
.btn { display: inline-flex; align-items: center; gap: 6px; justify-content: center; padding: 9px 16px; border-radius: 8px; border: none; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; transition: opacity .15s, background .15s; white-space: nowrap; }
.btn svg { width: 15px; height: 15px; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #274d74; }
.btn-ghost { background: var(--surface-alt); color: var(--ink); }
.btn-ghost:hover { background: var(--border); }
.btn-danger { background: var(--cancelled); color: #fff; }
.btn-danger:hover { background: #a63b31; }
.btn-block { width: 100%; margin-top: 14px; }
.btn-sm { padding: 6px 12px; font-size: 12.5px; }
.btn:disabled { opacity: .4; cursor: not-allowed; }

/* ============ stat cards ============ */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px; margin-bottom: 22px; }
.stat-card { --stat-color: #2F5D8A; --stat-soft: #E8EFF6; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 16px 14px; text-align: left; cursor: pointer; transition: transform .12s, box-shadow .12s, border-color .12s; position: relative; overflow: hidden; }
.stat-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--stat-color); }
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px var(--shadow); }
.stat-card-active { border-color: var(--stat-color); box-shadow: 0 0 0 2px var(--stat-soft); }
.stat-icon { width: 30px; height: 30px; border-radius: 8px; background: var(--stat-soft); color: var(--stat-color); display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.stat-icon svg { width: 16px; height: 16px; }
.stat-count { font-family: 'Space Grotesk', sans-serif; font-size: clamp(20px, 4vw, 26px); font-weight: 700; line-height: 1; }
.stat-label { font-size: 12px; color: var(--ink-soft); margin-top: 4px; font-weight: 500; }

/* ============ insights panel ============ */
.insights-panel { margin-bottom: 22px; }
.insights-grid { display: grid; grid-template-columns: 200px 1fr; gap: 24px; padding: 20px; align-items: center; }
.insights-chart-wrap { height: 180px; position: relative; }
.insights-metrics { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; }
.metric-tile { background: var(--bg); border: 1px solid var(--border); border-radius: 10px; padding: 14px; cursor: default; }
.metric-tile-danger { cursor: pointer; }
.metric-tile-danger:hover { border-color: var(--cancelled); }
.metric-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); font-weight: 600; margin-bottom: 6px; }
.metric-value { font-family: 'Space Grotesk', sans-serif; font-size: 20px; font-weight: 700; }
.metric-tile-danger .metric-value { color: var(--cancelled); }

/* ============ panel / table ============ */
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.panel-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--divider); }
.panel-head h2 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 600; margin: 0; }
.panel-sub { font-size: 12px; color: var(--muted); }

.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 14px 20px; border-bottom: 1px solid var(--divider); }
.toolbar-search { position: relative; flex: 1; min-width: 180px; }
.toolbar-search svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: var(--muted); }
.toolbar-search input { width: 100%; padding: 8px 10px 8px 32px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--ink); font-family: 'Inter', sans-serif; font-size: 13px; }
.toolbar-search input:focus { outline: 2px solid rgba(47,93,138,0.2); border-color: var(--accent); }
.toolbar-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.chip-clear { display: inline-flex; align-items: center; gap: 4px; background: var(--surface-alt); border: none; border-radius: 999px; padding: 4px 10px; font-size: 11.5px; font-weight: 600; color: var(--ink); cursor: pointer; }
.chip-clear svg { width: 12px; height: 12px; }
.chip-clear-danger { background: var(--cancelled-soft); color: var(--cancelled); }

.bulk-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 10px 20px; background: var(--accent-soft); border-bottom: 1px solid var(--divider); font-size: 13px; font-weight: 600; }
.bulk-toolbar select { padding: 7px 10px; border-radius: 8px; border: 1px solid var(--border); background: var(--input-bg); color: var(--ink); font-family: 'Inter', sans-serif; font-size: 13px; }

.table-scroll { overflow-x: auto; }
.proj-table { width: 100%; border-collapse: collapse; min-width: 620px; }
.proj-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600; padding: 10px 16px; border-bottom: 1px solid var(--divider); white-space: nowrap; }
.th-sort { cursor: pointer; user-select: none; }
.th-sort:hover { color: var(--ink); }
.sort-arrow { font-size: 9px; margin-left: 3px; }
.proj-table td { padding: 12px 16px; border-bottom: 1px solid var(--divider); vertical-align: middle; font-size: 13.5px; }
.proj-table tbody tr:last-child td { border-bottom: none; }
.row-clickable { cursor: pointer; }
.row-clickable:hover td { background: var(--input-bg); }
.col-check { width: 34px; }
.col-no { width: 44px; color: var(--muted); }
.col-progress { width: 200px; }
.col-status { width: 190px; }
.col-actions { width: 120px; white-space: nowrap; }
.proj-name { display: flex; flex-direction: column; gap: 2px; font-weight: 600; }
.proj-id { font-size: 11px; color: var(--muted); font-weight: 500; }
.empty-row { text-align: center; color: var(--muted); padding: 30px 0 !important; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 14px 20px; }
.page-info { font-size: 12.5px; color: var(--ink-soft); font-weight: 600; }

/* ============ badges ============ */
.badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.badge svg { width: 13px; height: 13px; }
.badge-overdue { background: var(--cancelled-soft); color: var(--cancelled); margin-left: 6px; }

/* ============ progress bar ============ */
.pbar-wrap { display: flex; align-items: center; gap: 10px; }
.pbar-track { position: relative; flex: 1; height: 8px; background: var(--surface-alt); border-radius: 4px; }
.pbar-fill { height: 100%; border-radius: 4px; transition: width .2s; }
.pbar-tick { position: absolute; top: -2px; bottom: -2px; width: 1px; background: rgba(128,128,128,0.18); }
.pbar-value { font-family: 'IBM Plex Mono', monospace; font-size: 12px; font-weight: 600; color: var(--ink-soft); width: 36px; text-align: right; flex-shrink: 0; }
.pbar-compact .pbar-track { height: 6px; }

/* ============ icon buttons ============ */
.icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 7px; border: none; background: transparent; color: var(--ink-soft); cursor: pointer; margin-right: 2px; transition: background .12s, color .12s; }
.icon-btn svg { width: 16px; height: 16px; }
.icon-btn:hover { background: var(--surface-alt); color: var(--ink); }
.icon-btn-danger:hover { background: var(--cancelled-soft); color: var(--cancelled); }

/* ============ modal ============ */
.modal-overlay { position: fixed; inset: 0; background: rgba(10,14,20,0.55); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
.modal-card { background: var(--surface); border-radius: 14px; width: min(780px, 100%); max-height: 90vh; box-shadow: 0 20px 60px var(--shadow); display: flex; flex-direction: column; overflow: hidden; }
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 20px; border-bottom: 1px solid var(--divider); }
.modal-head-left { display: flex; align-items: baseline; gap: 10px; min-width: 0; }
.modal-head-left h3 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.modal-id { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: var(--muted); background: var(--divider); padding: 3px 7px; border-radius: 5px; flex-shrink: 0; }
.modal-head-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.view-grid { display: grid; grid-template-columns: 1.05fr 1fr; overflow-y: auto; }
.view-details { padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; border-right: 1px solid var(--divider); }
.detail-rows { display: flex; flex-direction: column; gap: 8px; }
.detail-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; padding: 6px 0; border-bottom: 1px dashed var(--divider); }
.detail-row span { color: var(--muted); }
.detail-row b { font-weight: 600; text-align: right; }
.detail-desc { font-size: 13px; color: var(--ink-soft); line-height: 1.5; margin: 0; }
.view-chart { padding: 20px 22px; display: flex; flex-direction: column; height: 320px; }
.view-chart-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); font-weight: 600; margin-bottom: 6px; }
.view-chart canvas { flex: 1; width: 100% !important; height: 100% !important; }

/* ============ form ============ */
.modal-form .form-grid, #pwForm { padding: 20px 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0; overflow-y: auto; }
#pwForm { grid-template-columns: 1fr; }
.span-2 { grid-column: span 2; }
.field { display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--ink-soft); }
.field input, .field select, .field textarea { font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500; color: var(--ink); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; background: var(--input-bg); resize: none; width: 100%; }
.field input:focus, .field select:focus, .field textarea:focus { outline: 2px solid rgba(47,93,138,0.2); border-color: var(--accent); }
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }

/* ============ confirm dialog ============ */
.confirm-card { background: var(--surface); border-radius: 14px; padding: 22px; width: 100%; max-width: 380px; box-shadow: 0 20px 60px var(--shadow); }
.confirm-card h4 { margin: 0 0 8px; font-family: 'Space Grotesk', sans-serif; font-size: 16px; }
.confirm-card p { margin: 0 0 16px; font-size: 13.5px; color: var(--ink-soft); line-height: 1.5; }

/* ============ toast ============ */
.toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: #1B2430; color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 13px; z-index: 60; box-shadow: 0 10px 30px rgba(0,0,0,0.35); max-width: 90vw; text-align: center; }

/* ============ RESPONSIVE ============ */
@media (max-width: 980px) {
  .insights-grid { grid-template-columns: 1fr; }
  .insights-chart-wrap { height: 160px; }
}
@media (max-width: 900px) {
  .sidebar { position: fixed; left: 0; top: 0; transform: translateX(-100%); transition: transform .2s; }
  .sidebar.sidebar-open { transform: translateX(0); }
  .sidebar-backdrop.show { display: block; }
  .sidebar-toggle { display: flex; }
  .app-main { padding-top: 68px; }
}
@media (max-width: 720px) {
  .view-grid { grid-template-columns: 1fr; }
  .view-details { border-right: none; border-bottom: 1px solid var(--divider); }
  .modal-form .form-grid { grid-template-columns: 1fr; }
  .span-2 { grid-column: span 1; }
  .modal-card { max-height: 94vh; }
  .insights-metrics { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
  .table-scroll { overflow: visible; }
  .proj-table { min-width: 0; }
  .proj-table thead { display: none; }
  .proj-table, .proj-table tbody, .proj-table tr, .proj-table td { display: block; width: 100%; }
  .proj-table tr { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; padding: 6px 14px; }
  .proj-table td { padding: 8px 0; border-bottom: 1px dashed var(--divider); }
  .proj-table td:last-child { border-bottom: none; }
  .proj-table td::before { content: attr(data-label); display: block; font-size: 10.5px; color: var(--muted); font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
  .col-check { display: flex; align-items: center; }
  .col-check::before { display: none; }
  .col-no { display: none; }
  .col-actions { display: flex; gap: 4px; padding-top: 10px !important; }
  .col-actions::before { display: none; }
  .panel-head, .toolbar, .modal-head { padding: 14px 16px; }
  .view-details, .modal-form .form-grid, .view-chart, #pwForm { padding: 16px; }
}
</style>
</head>
<body>

<div class="app-shell">

  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">IT</div>
      <div>
        <div class="brand-name">ITPMS</div>
        <div class="brand-sub">Project Management</div>
      </div>
    </div>

    <button class="btn btn-primary btn-new-project" id="btnNewProject">
      <i data-lucide="plus"></i> New Project
    </button>

    <nav class="side-nav">
      <button class="nav-item nav-item-active" data-view="dashboard"><i data-lucide="layout-dashboard"></i> Dashboard</button>
      <button class="nav-item" data-view="projects"><i data-lucide="folder-kanban"></i> Projects</button>
      <a class="nav-item" href="manager.php" target="_blank" rel="noopener"><i data-lucide="presentation"></i> Manager View</a>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-user"><i data-lucide="user-circle"></i> <?= htmlspecialchars($_SESSION['user']) ?></div>
      <button class="nav-item" id="themeToggle" type="button"><i data-lucide="moon"></i> Dark mode</button>
      <button class="nav-item" id="btnChangePassword" type="button"><i data-lucide="key"></i> Change password</button>
      <a class="nav-item" href="?logout=1"><i data-lucide="log-out"></i> Logout</a>
    </div>
  </aside>

  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>
  <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle menu"><i data-lucide="menu"></i></button>

  <main class="app-main">

    <section class="view" id="view-dashboard">
      <div class="view-header">
        <h1>Dashboard</h1>
        <p class="view-sub">Snapshot of all IT projects, at a glance.</p>
      </div>
      <div class="stat-grid" id="statGrid"></div>

      <div class="panel insights-panel">
        <div class="panel-head"><h2>Insights</h2></div>
        <div class="insights-grid">
          <div class="insights-chart-wrap"><canvas id="statusDonut"></canvas></div>
          <div class="insights-metrics">
            <div class="metric-tile"><span class="metric-label">Total Budget</span><span class="metric-value" id="metricBudget">₱0</span></div>
            <div class="metric-tile metric-tile-danger" id="metricOverdueTile"><span class="metric-label">Overdue Projects</span><span class="metric-value" id="metricOverdue">0</span></div>
            <div class="metric-tile"><span class="metric-label">Average Progress</span><span class="metric-value" id="metricAvgProgress">0%</span></div>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>Project Progress</h2>
          <span class="panel-sub" id="dashboardCount"></span>
        </div>
        <div class="table-scroll">
          <table class="proj-table">
            <thead><tr><th class="col-no">No.</th><th>Project</th><th class="col-progress">Progress</th><th class="col-status">Status</th></tr></thead>
            <tbody id="dashboardTableBody"></tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="view view-hidden" id="view-projects">
      <div class="view-header">
        <h1>Projects</h1>
        <p class="view-sub">Create, review, and manage every project record.</p>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>All Projects</h2>
          <span class="panel-sub" id="projectsCount"></span>
        </div>

        <div class="toolbar">
          <div class="toolbar-search">
            <i data-lucide="search"></i>
            <input type="text" id="searchInput" placeholder="Search by name, owner, or ID...">
          </div>
          <div class="toolbar-chips" id="filterChips"></div>
          <button class="btn btn-ghost" id="btnExport"><i data-lucide="download"></i> Export CSV</button>
        </div>

        <div class="bulk-toolbar view-hidden" id="bulkToolbar">
          <span id="bulkCount"></span>
          <select id="bulkStatusSelect">
            <option value="Not Started">Not Started</option>
            <option value="Ongoing">Ongoing</option>
            <option value="Onhold">On Hold</option>
            <option value="Completed">Completed</option>
            <option value="Cancelled">Cancelled</option>
          </select>
          <button class="btn btn-ghost btn-sm" id="bulkApplyBtn">Apply status</button>
          <button class="btn btn-danger btn-sm" id="bulkDeleteBtn">Delete selected</button>
          <button class="icon-btn" id="bulkClearBtn"><i data-lucide="x"></i></button>
        </div>

        <div class="table-scroll">
          <table class="proj-table">
            <thead>
              <tr>
                <th class="col-check"><input type="checkbox" id="selectAllCheckbox"></th>
                <th class="col-no">No.</th>
                <th class="th-sort" data-sort="name">Project <span class="sort-arrow" data-arrow="name"></span></th>
                <th class="col-progress th-sort" data-sort="progress">Progress <span class="sort-arrow" data-arrow="progress"></span></th>
                <th class="col-status th-sort" data-sort="status">Status <span class="sort-arrow" data-arrow="status"></span></th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody id="projectsTableBody"></tbody>
          </table>
        </div>
        <div class="pagination" id="pagination"></div>
      </div>
    </section>

  </main>
</div>

<div class="modal-overlay view-hidden" id="viewModalOverlay">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-head-left"><span class="modal-id" id="viewModalId"></span><h3 id="viewModalName"></h3></div>
      <div class="modal-head-right"><span id="viewModalBadge"></span><button class="icon-btn" id="viewModalClose"><i data-lucide="x"></i></button></div>
    </div>
    <div class="view-grid">
      <div class="view-details">
        <div id="viewModalProgress"></div>
        <div class="detail-rows">
          <div class="detail-row"><span>Owner</span><b id="viewOwner"></b></div>
          <div class="detail-row"><span>Priority</span><b id="viewPriority"></b></div>
          <div class="detail-row"><span>Start date</span><b id="viewStart"></b></div>
          <div class="detail-row"><span>Target end</span><b id="viewEnd"></b></div>
          <div class="detail-row"><span>Budget</span><b id="viewBudget"></b></div>
        </div>
        <p class="detail-desc" id="viewDesc"></p>
        <button class="btn btn-primary btn-block" id="viewModalEditBtn"><i data-lucide="pencil"></i> Edit project</button>
      </div>
      <div class="view-chart">
        <div class="view-chart-label">Progress trend</div>
        <canvas id="progressChart"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay view-hidden" id="formModalOverlay">
  <div class="modal-card modal-form">
    <div class="modal-head">
      <div class="modal-head-left"><span class="modal-id" id="formModalId">NEW</span><h3 id="formModalTitle">New Project</h3></div>
      <div class="modal-head-right"><button class="icon-btn" id="formModalClose"><i data-lucide="x"></i></button></div>
    </div>
    <form class="form-grid" id="projectForm">
      <label class="field span-2"><span>Project name</span><input type="text" id="fName" placeholder="e.g. Core Banking Migration" required></label>
      <label class="field"><span>Status</span>
        <select id="fStatus">
          <option value="Not Started">Not Started</option>
          <option value="Ongoing">Ongoing</option>
          <option value="Onhold">On Hold</option>
          <option value="Completed">Completed</option>
          <option value="Cancelled">Cancelled</option>
        </select>
      </label>
      <label class="field"><span>Priority</span>
        <select id="fPriority">
          <option value="Low">Low</option>
          <option value="Medium" selected>Medium</option>
          <option value="High">High</option>
          <option value="Critical">Critical</option>
        </select>
      </label>
      <label class="field"><span>Progress (<span id="fProgressLabel">0</span>%)</span><input type="range" id="fProgress" min="0" max="100" value="0"></label>
      <label class="field"><span>Owner</span><input type="text" id="fOwner" placeholder="e.g. J. Santos"></label>
      <label class="field"><span>Start date</span><input type="date" id="fStart"></label>
      <label class="field"><span>Target end date</span><input type="date" id="fEnd"></label>
      <label class="field span-2"><span>Budget (₱)</span><input type="number" id="fBudget" min="0" step="0.01" value="0"></label>
      <label class="field span-2"><span>Description</span><textarea id="fDescription" rows="3" placeholder="What is this project about?"></textarea></label>
      <div class="form-actions span-2">
        <button type="button" class="btn btn-ghost" id="formModalCancel">Cancel</button>
        <button type="submit" class="btn btn-primary" id="formModalSubmit">Create project</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay view-hidden" id="confirmOverlay">
  <div class="confirm-card">
    <h4>Delete project?</h4>
    <p id="confirmMessage"><b id="confirmName"></b> will be permanently removed. This can't be undone.</p>
    <div class="form-actions">
      <button class="btn btn-ghost" id="confirmCancel">Cancel</button>
      <button class="btn btn-danger" id="confirmDelete">Delete</button>
    </div>
  </div>
</div>

<div class="modal-overlay view-hidden" id="pwModalOverlay">
  <div class="confirm-card">
    <h4>Change password</h4>
    <form id="pwForm">
      <label class="field"><span>Current password</span><input type="password" id="pwCurrent" required></label>
      <label class="field"><span>New password</span><input type="password" id="pwNew" required minlength="6"></label>
      <label class="field"><span>Confirm new password</span><input type="password" id="pwConfirm" required minlength="6"></label>
      <div class="form-actions" style="margin-top:14px">
        <button type="button" class="btn btn-ghost" id="pwCancel">Cancel</button>
        <button type="submit" class="btn btn-primary">Update password</button>
      </div>
    </form>
  </div>
</div>

<div class="toast view-hidden" id="toast"></div>

<script>
/* ITPMS — front-end logic. Talks to THIS SAME FILE via ?api=1 (AJAX/fetch). */

const API_URL = 'index.php?api=1';
const PAGE_SIZE = 8;

const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);

const state = {
  projects: [], filterStatus: null, overdueOnly: false, search: '',
  sortKey: null, sortDir: 'asc', page: 1, selected: new Set(), view: 'dashboard',
};

let chartInstance = null;
let donutInstance = null;
let editingId = null;
let pendingDelete = null;
let currentViewedId = null;

function icons() { if (window.lucide) lucide.createIcons(); }
function money(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function escapeHtml(str) { return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }
function todayStr() { return new Date().toISOString().slice(0, 10); }
function cssVar(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
function isOverdue(p) {
  if (!p.end_date) return false;
  if (p.status === 'Completed' || p.status === 'Cancelled') return false;
  return p.end_date < todayStr();
}

function showToast(msg, isError) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.style.background = isError ? '#C4483C' : '#1B2430';
  el.classList.remove('view-hidden');
  clearTimeout(showToast._t);
  showToast._t = setTimeout(() => el.classList.add('view-hidden'), 2600);
}

function badgeHtml(status) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG['Not Started'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${cfg.label}</span>`;
}

function progressBarHtml(value, status, compact) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG['Not Started'];
  const ticks = [10,20,30,40,50,60,70,80,90].map((t) => `<span class="pbar-tick" style="left:${t}%"></span>`).join('');
  return `<div class="pbar-wrap${compact ? ' pbar-compact' : ''}">
    <div class="pbar-track"><div class="pbar-fill" style="width:${value}%;background:${cfg.color}"></div>${ticks}</div>
    <span class="pbar-value mono">${value}%</span></div>`;
}

const api = {
  async list() { const r = await fetch(API_URL); if (!r.ok) throw new Error('Failed to load projects.'); return r.json(); },
  async get(id) { const r = await fetch(`${API_URL}&id=${encodeURIComponent(id)}`); if (!r.ok) throw new Error('Failed to load project.'); return r.json(); },
  async create(data) {
    const r = await fetch(API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to create project.');
    return r.json();
  },
  async update(id, data) {
    const r = await fetch(`${API_URL}&id=${encodeURIComponent(id)}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to update project.');
    return r.json();
  },
  async remove(id) {
    const r = await fetch(`${API_URL}&id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to delete project.');
    return r.json();
  },
  async changePassword(current_password, new_password) {
    const r = await fetch(`${API_URL}&action=change_password`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ current_password, new_password }) });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to change password.');
    return r.json();
  },
};

async function loadProjects() {
  try {
    state.projects = await api.list();
    renderAll();
  } catch (e) {
    showToast(e.message, true);
  }
}

function renderAll() {
  renderStatGrid();
  renderInsights();
  renderDashboardTable();
  renderProjectsTable();
}

function renderStatGrid() {
  const counts = {}; STATUSES.forEach((s) => (counts[s] = 0));
  state.projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));

  document.getElementById('statGrid').innerHTML = STATUSES.map((s) => {
    const cfg = STATUS_CONFIG[s];
    const active = state.filterStatus === s ? ' stat-card-active' : '';
    return `<button class="stat-card${active}" style="--stat-color:${cfg.color};--stat-soft:${cfg.soft}" data-status="${s}">
      <div class="stat-icon"><i data-lucide="${cfg.icon}"></i></div>
      <div class="stat-count">${counts[s]}</div>
      <div class="stat-label">${cfg.label}</div></button>`;
  }).join('');

  document.querySelectorAll('#statGrid .stat-card').forEach((btn) => {
    btn.addEventListener('click', () => {
      const s = btn.dataset.status;
      state.filterStatus = state.filterStatus === s ? null : s;
      state.overdueOnly = false;
      state.page = 1;
      switchView('projects');
      renderAll();
    });
  });
  icons();
}

function renderInsights() {
  const totalBudget = state.projects.reduce((s, p) => s + Number(p.budget || 0), 0);
  const overdueCount = state.projects.filter(isOverdue).length;
  const avgProgress = state.projects.length ? Math.round(state.projects.reduce((s, p) => s + p.progress, 0) / state.projects.length) : 0;

  document.getElementById('metricBudget').textContent = money(totalBudget);
  document.getElementById('metricOverdue').textContent = overdueCount;
  document.getElementById('metricAvgProgress').textContent = avgProgress + '%';

  const counts = {}; STATUSES.forEach((s) => (counts[s] = 0));
  state.projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));

  const ctx = document.getElementById('statusDonut').getContext('2d');
  if (donutInstance) donutInstance.destroy();
  donutInstance = new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: STATUSES.map((s) => STATUS_CONFIG[s].label),
      datasets: [{ data: STATUSES.map((s) => counts[s]), backgroundColor: STATUSES.map((s) => STATUS_CONFIG[s].color), borderWidth: 0 }],
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '65%',
      plugins: { legend: { position: 'bottom', labels: { font: { size: 11, family: 'Inter' }, color: cssVar('--ink-soft'), padding: 10, boxWidth: 10 } } },
    },
  });
}

function renderDashboardTable() {
  const rows = state.projects;
  document.getElementById('dashboardCount').textContent = `${rows.length} total projects`;
  document.getElementById('dashboardTableBody').innerHTML = rows.map((p, i) => `
    <tr class="row-clickable" data-id="${p.id}">
      <td class="col-no mono" data-label="No.">${i + 1}</td>
      <td class="proj-name" data-label="Project">${escapeHtml(p.name)}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress" data-label="Progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status" data-label="Status">${badgeHtml(p.status)}${isOverdue(p) ? '<span class="badge badge-overdue">Overdue</span>' : ''}</td>
    </tr>`).join('') || `<tr><td colspan="4" class="empty-row">No projects yet.</td></tr>`;

  document.querySelectorAll('#dashboardTableBody tr[data-id]').forEach((tr) => tr.addEventListener('click', () => openViewModal(tr.dataset.id)));
  icons();
}

function getFilteredSortedProjects() {
  let rows = state.projects.slice();
  if (state.filterStatus) rows = rows.filter((p) => p.status === state.filterStatus);
  if (state.overdueOnly) rows = rows.filter(isOverdue);
  if (state.search.trim()) {
    const q = state.search.trim().toLowerCase();
    rows = rows.filter((p) => p.name.toLowerCase().includes(q) || (p.owner || '').toLowerCase().includes(q) || p.id.toLowerCase().includes(q));
  }
  if (state.sortKey) {
    const dir = state.sortDir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      let av = a[state.sortKey], bv = b[state.sortKey];
      if (state.sortKey === 'name' || state.sortKey === 'status') { av = String(av).toLowerCase(); bv = String(bv).toLowerCase(); }
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      return 0;
    });
  }
  return rows;
}

function renderProjectsTable() {
  const all = getFilteredSortedProjects();
  const totalPages = Math.max(1, Math.ceil(all.length / PAGE_SIZE));
  if (state.page > totalPages) state.page = totalPages;
  const startIdx = (state.page - 1) * PAGE_SIZE;
  const rows = all.slice(startIdx, startIdx + PAGE_SIZE);

  document.getElementById('projectsCount').textContent = `${all.length} shown`;

  const chips = [];
  if (state.filterStatus) chips.push(`<button class="chip-clear" data-chip="status">${STATUS_CONFIG[state.filterStatus].label} <i data-lucide="x"></i></button>`);
  if (state.overdueOnly) chips.push(`<button class="chip-clear chip-clear-danger" data-chip="overdue">Overdue <i data-lucide="x"></i></button>`);
  document.getElementById('filterChips').innerHTML = chips.join('');
  document.querySelectorAll('#filterChips .chip-clear').forEach((btn) => {
    btn.addEventListener('click', () => {
      if (btn.dataset.chip === 'status') state.filterStatus = null;
      if (btn.dataset.chip === 'overdue') state.overdueOnly = false;
      state.page = 1;
      renderProjectsTable();
    });
  });

  document.querySelectorAll('.sort-arrow').forEach((s) => (s.textContent = ''));
  if (state.sortKey) {
    const el = document.querySelector(`.sort-arrow[data-arrow="${state.sortKey}"]`);
    if (el) el.textContent = state.sortDir === 'asc' ? '▲' : '▼';
  }

  document.getElementById('projectsTableBody').innerHTML = rows.map((p, i) => `
    <tr data-id="${p.id}">
      <td class="col-check" data-label=""><input type="checkbox" class="row-check" data-id="${p.id}" ${state.selected.has(p.id) ? 'checked' : ''}></td>
      <td class="col-no mono" data-label="No.">${startIdx + i + 1}</td>
      <td class="proj-name" data-label="Project">${escapeHtml(p.name)}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress" data-label="Progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status" data-label="Status">${badgeHtml(p.status)}${isOverdue(p) ? '<span class="badge badge-overdue">Overdue</span>' : ''}</td>
      <td class="col-actions" data-label="Actions">
        <button class="icon-btn btn-view" title="View" data-id="${p.id}"><i data-lucide="eye"></i></button>
        <button class="icon-btn btn-edit" title="Edit" data-id="${p.id}"><i data-lucide="pencil"></i></button>
        <button class="icon-btn icon-btn-danger btn-delete" title="Delete" data-id="${p.id}"><i data-lucide="trash-2"></i></button>
      </td>
    </tr>`).join('') || `<tr><td colspan="6" class="empty-row">No projects match your filters.</td></tr>`;

  document.getElementById('pagination').innerHTML = totalPages > 1 ? `
    <button class="btn btn-ghost btn-sm" id="pagePrev" ${state.page <= 1 ? 'disabled' : ''}>Prev</button>
    <span class="page-info">Page ${state.page} of ${totalPages}</span>
    <button class="btn btn-ghost btn-sm" id="pageNext" ${state.page >= totalPages ? 'disabled' : ''}>Next</button>` : '';

  document.querySelectorAll('.btn-view').forEach((b) => b.addEventListener('click', () => openViewModal(b.dataset.id)));
  document.querySelectorAll('.btn-edit').forEach((b) => b.addEventListener('click', () => openEditModal(b.dataset.id)));
  document.querySelectorAll('.btn-delete').forEach((b) => b.addEventListener('click', () => openDeleteConfirm(b.dataset.id)));
  document.querySelectorAll('.row-check').forEach((cb) => cb.addEventListener('change', () => {
    if (cb.checked) state.selected.add(cb.dataset.id); else state.selected.delete(cb.dataset.id);
    renderBulkToolbar();
  }));
  const prevBtn = document.getElementById('pagePrev');
  const nextBtn = document.getElementById('pageNext');
  if (prevBtn) prevBtn.addEventListener('click', () => { state.page--; renderProjectsTable(); });
  if (nextBtn) nextBtn.addEventListener('click', () => { state.page++; renderProjectsTable(); });

  const selectAll = document.getElementById('selectAllCheckbox');
  selectAll.checked = rows.length > 0 && rows.every((p) => state.selected.has(p.id));
  renderBulkToolbar();
  icons();
}

function renderBulkToolbar() {
  const n = state.selected.size;
  const bar = document.getElementById('bulkToolbar');
  if (n === 0) { bar.classList.add('view-hidden'); return; }
  bar.classList.remove('view-hidden');
  document.getElementById('bulkCount').textContent = `${n} selected`;
}

async function openViewModal(id) {
  let p;
  try { p = await api.get(id); } catch (e) { showToast(e.message, true); return; }
  currentViewedId = id;

  document.getElementById('viewModalId').textContent = p.id;
  document.getElementById('viewModalName').textContent = p.name;
  document.getElementById('viewModalBadge').innerHTML = badgeHtml(p.status) + (isOverdue(p) ? '<span class="badge badge-overdue">Overdue</span>' : '');
  document.getElementById('viewModalProgress').innerHTML = progressBarHtml(p.progress, p.status, false);
  document.getElementById('viewOwner').textContent = p.owner || '—';
  document.getElementById('viewPriority').textContent = p.priority;
  document.getElementById('viewStart').textContent = p.start_date || '—';
  document.getElementById('viewEnd').textContent = p.end_date || '—';
  document.getElementById('viewBudget').textContent = money(p.budget);
  document.getElementById('viewDesc').textContent = p.description || 'No description provided.';
  document.getElementById('viewModalEditBtn').onclick = () => { closeModal('viewModalOverlay'); openEditModal(p.id); };

  drawProgressChart(p);
  openModal('viewModalOverlay');
}

function drawProgressChart(p) {
  const ctx = document.getElementById('progressChart').getContext('2d');
  if (chartInstance) chartInstance.destroy();
  chartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: p.history.map((h) => h.date.slice(5)),
      datasets: [{
        label: 'Progress', data: p.history.map((h) => h.progress),
        borderColor: STATUS_CONFIG[p.status].color, backgroundColor: STATUS_CONFIG[p.status].color + '22',
        tension: 0.35, fill: true, pointRadius: 3, pointBackgroundColor: STATUS_CONFIG[p.status].color,
      }],
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => `${c.parsed.y}%` } } },
      scales: {
        y: { min: 0, max: 100, ticks: { font: { size: 10 }, color: cssVar('--muted') }, grid: { color: cssVar('--divider') } },
        x: { ticks: { font: { size: 10 }, color: cssVar('--muted') }, grid: { display: false } },
      },
    },
  });
}

function resetForm() {
  document.getElementById('projectForm').reset();
  document.getElementById('fProgress').value = 0;
  document.getElementById('fProgressLabel').textContent = '0';
  document.getElementById('fStart').value = '';
  document.getElementById('fEnd').value = '';
}

function openCreateModal() {
  editingId = null;
  resetForm();
  document.getElementById('formModalId').textContent = 'NEW';
  document.getElementById('formModalTitle').textContent = 'New Project';
  document.getElementById('formModalSubmit').textContent = 'Create project';
  openModal('formModalOverlay');
}

async function openEditModal(id) {
  let p;
  try { p = await api.get(id); } catch (e) { showToast(e.message, true); return; }
  editingId = p.id;
  document.getElementById('formModalId').textContent = p.id;
  document.getElementById('formModalTitle').textContent = 'Edit Project';
  document.getElementById('formModalSubmit').textContent = 'Save changes';
  document.getElementById('fName').value = p.name;
  document.getElementById('fStatus').value = p.status;
  document.getElementById('fPriority').value = p.priority;
  document.getElementById('fProgress').value = p.progress;
  document.getElementById('fProgressLabel').textContent = p.progress;
  document.getElementById('fOwner').value = p.owner || '';
  document.getElementById('fStart').value = p.start_date || '';
  document.getElementById('fEnd').value = p.end_date || '';
  document.getElementById('fBudget').value = p.budget;
  document.getElementById('fDescription').value = p.description || '';
  openModal('formModalOverlay');
}

async function submitForm(e) {
  e.preventDefault();
  const payload = {
    name: document.getElementById('fName').value.trim(),
    status: document.getElementById('fStatus').value,
    priority: document.getElementById('fPriority').value,
    progress: Number(document.getElementById('fProgress').value),
    owner: document.getElementById('fOwner').value.trim(),
    start: document.getElementById('fStart').value || null,
    end: document.getElementById('fEnd').value || null,
    budget: Number(document.getElementById('fBudget').value) || 0,
    description: document.getElementById('fDescription').value.trim(),
  };
  if (!payload.name) { showToast('Project name is required.', true); return; }
  try {
    if (editingId) { await api.update(editingId, payload); showToast('Project updated.'); }
    else { await api.create(payload); showToast('Project created.'); }
    closeModal('formModalOverlay');
    await loadProjects();
  } catch (e2) { showToast(e2.message, true); }
}

function openDeleteConfirm(id) {
  const p = state.projects.find((x) => x.id === id);
  if (!p) return;
  pendingDelete = { type: 'single', id };
  document.getElementById('confirmMessage').innerHTML = `<b>${escapeHtml(p.name)}</b> (${p.id}) will be permanently removed. This can't be undone.`;
  openModal('confirmOverlay');
}

function openBulkDeleteConfirm() {
  const ids = [...state.selected];
  if (!ids.length) return;
  pendingDelete = { type: 'bulk', ids };
  document.getElementById('confirmMessage').innerHTML = `<b>${ids.length} project(s)</b> will be permanently removed. This can't be undone.`;
  openModal('confirmOverlay');
}

async function confirmDeleteAction() {
  if (!pendingDelete) return;
  try {
    if (pendingDelete.type === 'bulk') {
      await Promise.all(pendingDelete.ids.map((id) => api.remove(id)));
      showToast(`${pendingDelete.ids.length} project(s) deleted.`);
      state.selected.clear();
    } else {
      await api.remove(pendingDelete.id);
      showToast('Project deleted.');
    }
    closeModal('confirmOverlay');
    pendingDelete = null;
    await loadProjects();
  } catch (e) { showToast(e.message, true); }
}

async function bulkApplyStatus() {
  const status = document.getElementById('bulkStatusSelect').value;
  const ids = [...state.selected];
  if (!ids.length) return;
  try {
    await Promise.all(ids.map((id) => api.update(id, { status })));
    showToast(`Updated status for ${ids.length} project(s).`);
    state.selected.clear();
    await loadProjects();
  } catch (e) { showToast(e.message, true); }
}

function exportCSV() {
  const rows = getFilteredSortedProjects();
  if (!rows.length) { showToast('Nothing to export with the current filters.', true); return; }
  const header = ['ID', 'Name', 'Status', 'Progress(%)', 'Owner', 'Priority', 'Start Date', 'End Date', 'Budget', 'Description'];
  const csvRows = [header.join(',')];
  rows.forEach((p) => {
    const vals = [p.id, p.name, p.status, p.progress, p.owner, p.priority, p.start_date || '', p.end_date || '', p.budget, (p.description || '').replace(/\n/g, ' ')];
    csvRows.push(vals.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(','));
  });
  const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = `itpms-projects-${todayStr()}.csv`;
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

async function submitPasswordChange(e) {
  e.preventDefault();
  const current = document.getElementById('pwCurrent').value;
  const next = document.getElementById('pwNew').value;
  const confirmVal = document.getElementById('pwConfirm').value;
  if (next !== confirmVal) { showToast("New passwords don't match.", true); return; }
  try {
    await api.changePassword(current, next);
    showToast('Password updated.');
    document.getElementById('pwForm').reset();
    closeModal('pwModalOverlay');
  } catch (e2) { showToast(e2.message, true); }
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('itpms-theme', theme);
  updateThemeButton();
  renderInsights();
  if (currentViewedId && !document.getElementById('viewModalOverlay').classList.contains('view-hidden')) {
    api.get(currentViewedId).then(drawProgressChart).catch(() => {});
  }
}
function updateThemeButton() {
  const btn = document.getElementById('themeToggle');
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  btn.innerHTML = isDark ? '<i data-lucide="sun"></i> Light mode' : '<i data-lucide="moon"></i> Dark mode';
  icons();
}

function openModal(id) { document.getElementById(id).classList.remove('view-hidden'); icons(); }
function closeModal(id) { document.getElementById(id).classList.add('view-hidden'); }

function switchView(view) {
  state.view = view;
  document.getElementById('view-dashboard').classList.toggle('view-hidden', view !== 'dashboard');
  document.getElementById('view-projects').classList.toggle('view-hidden', view !== 'projects');
  document.querySelectorAll('.nav-item[data-view]').forEach((btn) => btn.classList.toggle('nav-item-active', btn.dataset.view === view));
  closeSidebar();
}

function openSidebar() { document.getElementById('sidebar').classList.add('sidebar-open'); document.getElementById('sidebarBackdrop').classList.add('show'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('sidebar-open'); document.getElementById('sidebarBackdrop').classList.remove('show'); }

document.addEventListener('DOMContentLoaded', () => {
  icons();
  updateThemeButton();
  loadProjects();

  document.querySelectorAll('.nav-item[data-view]').forEach((btn) => btn.addEventListener('click', () => switchView(btn.dataset.view)));
  document.getElementById('btnNewProject').addEventListener('click', openCreateModal);

  document.getElementById('viewModalClose').addEventListener('click', () => closeModal('viewModalOverlay'));
  document.getElementById('viewModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'viewModalOverlay') closeModal('viewModalOverlay'); });

  document.getElementById('formModalClose').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalCancel').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'formModalOverlay') closeModal('formModalOverlay'); });
  document.getElementById('projectForm').addEventListener('submit', submitForm);
  document.getElementById('fProgress').addEventListener('input', (e) => { document.getElementById('fProgressLabel').textContent = e.target.value; });

  document.getElementById('confirmCancel').addEventListener('click', () => { closeModal('confirmOverlay'); pendingDelete = null; });
  document.getElementById('confirmDelete').addEventListener('click', confirmDeleteAction);
  document.getElementById('confirmOverlay').addEventListener('click', (e) => { if (e.target.id === 'confirmOverlay') { closeModal('confirmOverlay'); pendingDelete = null; } });

  document.getElementById('searchInput').addEventListener('input', (e) => { state.search = e.target.value; state.page = 1; renderProjectsTable(); });
  document.querySelectorAll('.th-sort').forEach((th) => th.addEventListener('click', () => {
    const key = th.dataset.sort;
    if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
    else { state.sortKey = key; state.sortDir = 'asc'; }
    renderProjectsTable();
  }));
  document.getElementById('selectAllCheckbox').addEventListener('change', (e) => {
    const all = getFilteredSortedProjects();
    const startIdx = (state.page - 1) * PAGE_SIZE;
    const pageRows = all.slice(startIdx, startIdx + PAGE_SIZE);
    pageRows.forEach((p) => { if (e.target.checked) state.selected.add(p.id); else state.selected.delete(p.id); });
    renderProjectsTable();
  });
  document.getElementById('btnExport').addEventListener('click', exportCSV);
  document.getElementById('bulkApplyBtn').addEventListener('click', bulkApplyStatus);
  document.getElementById('bulkDeleteBtn').addEventListener('click', openBulkDeleteConfirm);
  document.getElementById('bulkClearBtn').addEventListener('click', () => { state.selected.clear(); renderProjectsTable(); });

  document.getElementById('metricOverdueTile').addEventListener('click', () => {
    state.overdueOnly = true; state.filterStatus = null; state.page = 1;
    switchView('projects'); renderAll();
  });

  document.getElementById('themeToggle').addEventListener('click', () => {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    applyTheme(isDark ? 'light' : 'dark');
  });

  document.getElementById('btnChangePassword').addEventListener('click', () => openModal('pwModalOverlay'));
  document.getElementById('pwCancel').addEventListener('click', () => closeModal('pwModalOverlay'));
  document.getElementById('pwModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'pwModalOverlay') closeModal('pwModalOverlay'); });
  document.getElementById('pwForm').addEventListener('submit', submitPasswordChange);

  document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
  });
  document.getElementById('sidebarBackdrop').addEventListener('click', closeSidebar);
});
</script>
</body>
</html>