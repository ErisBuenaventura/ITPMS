<?php
/**
 * ITPMS — IT Project Management System (single-file build, v3)
 * ------------------------------------------------------------------
 * HOW TO USE
 *   1. Create a MySQL database and import database.sql into it.
 *   2. Edit the 4 DB constants at the top of auth.php with your
 *      hosting credentials.
 *   3. Upload index.php, manager.php, auth.php, login.php, logout.php,
 *      and change_password.php together to your server.
 *   4. Log in with admin / admin123 (created automatically on first run),
 *      then use the "Change password" link in the sidebar to set your own.
 * ------------------------------------------------------------------
 */

require_once __DIR__ . '/auth.php';
require_login(); // gates both the page and every ?api=1 request below

// Ensure progress_history has a notes column (safe, idempotent): add if missing.
try {
    $col = $pdo->query("SHOW COLUMNS FROM progress_history LIKE 'notes'")->fetch();
    if (!$col) {
        $pdo->exec("ALTER TABLE progress_history ADD COLUMN notes TEXT NULL");
    }
} catch (Throwable $e) {
    // ignore — table may not exist in some environments (dev/import), rely on DB migration if needed
}

// Export API: authenticated users can request named reports in CSV or JSON.
if (isset($_GET['export'])) {
    $report = isset($_GET['report']) ? trim($_GET['report']) : 'projects';
    $format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

    $allowed = ['projects','history','projects_history','summary'];
    if (!in_array($report, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid report requested.']);
        exit;
    }

    try {
        switch ($report) {
            case 'projects':
                $stmt = $pdo->query("SELECT id,name,status,progress,owner,priority,start_date,end_date,budget,description,file_link,created_at,updated_at FROM projects ORDER BY created_at ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'history':
                            $stmt = $pdo->query("SELECT project_id AS project, entry_date AS date, progress, notes FROM progress_history ORDER BY project_id, entry_date ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'projects_history':
                            $stmt = $pdo->query("SELECT p.id AS project_id,p.name AS project_name,p.status,p.progress AS current_progress,p.owner,p.priority,p.start_date,p.end_date,p.budget,p.description,p.file_link, ph.entry_date AS history_date, ph.progress AS history_progress, ph.notes AS history_notes FROM projects p LEFT JOIN progress_history ph ON p.id=ph.project_id ORDER BY p.id, ph.entry_date ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            case 'summary':
                $stmt = $pdo->query("SELECT p.id,p.name,p.status,p.progress,COUNT(ph.entry_date) AS history_points, MAX(ph.entry_date) AS last_update FROM projects p LEFT JOIN progress_history ph ON p.id=ph.project_id GROUP BY p.id ORDER BY p.name ASC");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
            default:
                $rows = [];
        }

        if ($format === 'json') {
            header('Content-Type: application/json');
            echo json_encode($rows);
            exit;
        }

        // Default CSV
        $datePart = date('Ymd');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="itpms_export_' . $report . '_' . $datePart . '.csv"');
        $out = fopen('php://output', 'w');
        if ($out && count($rows) > 0) {
            // header row
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $r) fputcsv($out, array_values($r));
        } elseif ($out) {
            // nothing to export — still return an empty CSV with no rows
            fputcsv($out, ['no_rows']);
        }
        if ($out) fclose($out);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}


/* ============================ API — every AJAX call hits index.php?api=1 ============================ */
if (isset($_GET['api'])) {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];
    $id     = isset($_GET['id']) ? trim($_GET['id']) : null;

    $VALID_STATUSES   = ['Completed', 'Ongoing', 'Onhold', 'Cancelled', 'Not Started'];
    $VALID_PRIORITIES = ['Low', 'Medium', 'High', 'Critical'];

    function read_json_body() {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    function today_str() { return date('Y-m-d'); }
    function is_overdue(array $p): bool {
        if (empty($p['end_date'])) return false;
        if (in_array($p['status'], ['Completed', 'Cancelled'], true)) return false;
        return $p['end_date'] < today_str();
    }
    function next_project_id(PDO $pdo) {
        $stmt = $pdo->query("SELECT id FROM projects");
        $max = 999;
        foreach ($stmt->fetchAll() as $row) {
            if (preg_match('/PRJ-(\d+)/', $row['id'], $m)) $max = max($max, (int) $m[1]);
        }
        return 'PRJ-' . ($max + 1);
    }
    function api_fail(int $code, string $message): void {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }

    try {
        switch ($method) {

        case 'GET':
            if ($id) {
                $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
                $stmt->execute([$id]);
                $project = $stmt->fetch();
                if (!$project) api_fail(404, 'Project not found.');

                $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
                $hist->execute([$id]);
                $project['history']  = $hist->fetchAll();
                $project['budget']   = (float) $project['budget'];
                $project['progress'] = (int) $project['progress'];
                $project['overdue']  = is_overdue($project);

                echo json_encode($project);
            } else {
                $rows = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
                foreach ($rows as &$r) {
                    $r['budget']   = (float) $r['budget'];
                    $r['progress'] = (int) $r['progress'];
                    $r['overdue']  = is_overdue($r);
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
            $fileLink = trim($data['file_link'] ?? '');
            $newId    = next_project_id($pdo);

            $notes = trim($data['notes'] ?? '');
            $stmt = $pdo->prepare("INSERT INTO projects (id, name, status, progress, owner, priority, start_date, end_date, budget, description, file_link, notes)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$newId, $name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null, $notes ?: null]);

            // If client supplied an explicit history array, upsert each entry. Otherwise insert today's point as before.
            if (array_key_exists('history', $data) && is_array($data['history'])) {
                $h2 = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, ?)
                                                     ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = VALUES(notes)");
                foreach ($data['history'] as $entry) {
                    if (!is_array($entry)) continue;
                    $entryDate = $entry['date'] ?? null;
                    $entryProg = isset($entry['progress']) ? (int) $entry['progress'] : null;
                                    $entryNotes = isset($entry['notes']) ? trim($entry['notes']) : null;
                                    if (!$entryDate || $entryProg === null) continue;
                                    $entryProg = max(0, min(100, $entryProg));
                                    try { $h2->execute([$newId, $entryDate, $entryProg, $entryNotes]); } catch (Throwable $e) { /* ignore invalid rows */ }
                                }
            } else {
                $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress) VALUES (?, ?, ?)");
                $h->execute([$newId, today_str(), $progress]);
            }

            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$newId]);
            $project = $stmt->fetch();
            $project['budget']   = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];

            $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
            $hist->execute([$newId]);
            $project['history'] = $hist->fetchAll();

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
            $fileLink = array_key_exists('file_link', $data) ? trim($data['file_link']) : $existing['file_link'];

            $notes = array_key_exists('notes', $data) ? trim($data['notes']) : $existing['notes'];
            $stmt = $pdo->prepare("UPDATE projects SET name=?, status=?, progress=?, owner=?, priority=?, start_date=?, end_date=?, budget=?, description=?, file_link=?, notes=? WHERE id=?");
            $stmt->execute([$name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null, $notes ?: null, $id]);

            $h = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, NULL)
                                 ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = COALESCE(notes, VALUES(notes))");
            $h->execute([$id, today_str(), $progress]);
 
            // If the client included a 'history' array, upsert each provided entry (date + progress + notes).
            if (array_key_exists('history', $data) && is_array($data['history'])) {
                $h2 = $pdo->prepare("INSERT INTO progress_history (project_id, entry_date, progress, notes) VALUES (?, ?, ?, ?)
                                     ON DUPLICATE KEY UPDATE progress = VALUES(progress), notes = VALUES(notes)");
                foreach ($data['history'] as $entry) {
                    if (!is_array($entry)) continue;
                    $entryDate = $entry['date'] ?? null;
                    $entryProg = isset($entry['progress']) ? (int) $entry['progress'] : null;
                    $entryNotes = isset($entry['notes']) ? trim($entry['notes']) : null;
                    if (!$entryDate || $entryProg === null) continue;
                    $entryProg = max(0, min(100, $entryProg));
                    try { $h2->execute([$id, $entryDate, $entryProg, $entryNotes]); } catch (Throwable $e) { /* ignore invalid rows */ }
                }
            }

            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch();
            $project['budget']   = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];

            $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
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
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit; // stop here for every API request — nothing below this runs
}

/* ============================ Quick IT Requests API — every AJAX call hits index.php?requests_api=1 ============================ */
if (isset($_GET['requests_api'])) {
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];
    $id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

    $VALID_CATEGORIES = ['Hardware', 'Software', 'Account/Access', 'Network', 'Other'];
    $VALID_REQ_STATUSES = ['Open', 'In Progress', 'Done'];

    function read_json_body_req() {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    // Converts a <input type="datetime-local"> value ("YYYY-MM-DDTHH:MM") into MySQL
    // DATETIME format ("YYYY-MM-DD HH:MM:SS"). Returns null if the input is empty/invalid.
    function parse_datetime_local(?string $raw): ?string {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        $normalized = str_replace('T', ' ', $raw);
        // Accept "YYYY-MM-DD HH:MM" or "YYYY-MM-DD HH:MM:SS"
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $normalized)) {
            return strlen($normalized) === 16 ? $normalized . ':00' : $normalized;
        }
        return null;
    }
    function req_fail(int $code, string $message): void {
        http_response_code($code);
        echo json_encode(['error' => $message]);
        exit;
    }

    try {
        switch ($method) {

        case 'GET':
            $rows = $pdo->query("SELECT * FROM it_requests ORDER BY created_at DESC")->fetchAll();
            echo json_encode($rows);
            break;

        case 'POST':
            $data = read_json_body_req();
            $title = trim($data['title'] ?? '');
            if ($title === '') req_fail(422, 'Request title is required.');

            $requester = trim($data['requester'] ?? '');
            $category  = in_array($data['category'] ?? '', $VALID_CATEGORIES) ? $data['category'] : 'Other';
            $notes     = trim($data['notes'] ?? '');
            // "issued" comes from a <input type="datetime-local"> as "YYYY-MM-DDTHH:MM" — swap the
            // T for a space to match MySQL's DATETIME format. Falls back to NOW() if left blank.
            $issuedRaw = trim($data['issued'] ?? '');
            $issuedAt  = $issuedRaw !== '' ? str_replace('T', ' ', $issuedRaw) . ':00' : date('Y-m-d H:i:s');

            // Allow logging a request that's already done (e.g. you forgot to log it
            // when you actually did the work). If status is "Done", accept an optional
            // explicit "resolved" datetime-local value; otherwise default to now.
            $status = in_array($data['status'] ?? '', $VALID_REQ_STATUSES) ? $data['status'] : 'Open';

            $resolvedAt = null;
            if ($status === 'Done') {
                $resolvedAt = parse_datetime_local($data['resolved'] ?? null) ?? date('Y-m-d H:i:s');
            }

            $stmt = $pdo->prepare("INSERT INTO it_requests (title, requester, category, status, notes, created_at, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $requester, $category, $status, $notes ?: null, $issuedAt, $resolvedAt]);

            $newId = (int) $pdo->lastInsertId();
            $stmt  = $pdo->prepare("SELECT * FROM it_requests WHERE id = ?");
            $stmt->execute([$newId]);

            http_response_code(201);
            echo json_encode($stmt->fetch());
            break;

        case 'PUT':
            if (!$id) req_fail(400, 'Missing request id.');
            $data = read_json_body_req();

            $stmt = $pdo->prepare("SELECT * FROM it_requests WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) req_fail(404, 'Request not found.');

            // Full-edit fields (title/requester/category/notes/issued) — all optional in the
            // payload so a status-only PUT (e.g. from a quick "mark Done" button) still works
            // without needing to resend the whole record.
            $title     = array_key_exists('title', $data) ? trim($data['title']) : $existing['title'];
            if ($title === '') req_fail(422, 'Request title is required.');
            $requester = array_key_exists('requester', $data) ? trim($data['requester']) : $existing['requester'];
            $category  = in_array($data['category'] ?? '', $VALID_CATEGORIES) ? $data['category'] : $existing['category'];
            $notes     = array_key_exists('notes', $data) ? trim($data['notes']) : $existing['notes'];
            $issuedAt  = array_key_exists('issued', $data)
                ? (parse_datetime_local($data['issued']) ?? $existing['created_at'])
                : $existing['created_at'];

            $status = in_array($data['status'] ?? '', $VALID_REQ_STATUSES) ? $data['status'] : $existing['status'];

            // Resolved timestamp logic:
            //  - status -> Done and an explicit "resolved" datetime was sent: use it as-is
            //    (lets you backfill a request you forgot to mark done at the time).
            //  - status -> Done with no explicit "resolved": keep existing resolved_at if
            //    already set, otherwise use now().
            //  - status is anything else: clear resolved_at.
            $resolvedAt = $existing['resolved_at'];
            if ($status === 'Done') {
                $explicit = array_key_exists('resolved', $data) ? parse_datetime_local($data['resolved']) : null;
                if ($explicit !== null) {
                    $resolvedAt = $explicit;
                } elseif ($existing['status'] !== 'Done' || empty($existing['resolved_at'])) {
                    $resolvedAt = date('Y-m-d H:i:s');
                }
            } else {
                $resolvedAt = null;
            }

            $stmt = $pdo->prepare("UPDATE it_requests SET title=?, requester=?, category=?, notes=?, created_at=?, status=?, resolved_at=? WHERE id=?");
            $stmt->execute([$title, $requester, $category, $notes ?: null, $issuedAt, $status, $resolvedAt, $id]);

            $stmt = $pdo->prepare("SELECT * FROM it_requests WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode($stmt->fetch());
            break;

        case 'DELETE':
            if (!$id) req_fail(400, 'Missing request id.');
            $stmt = $pdo->prepare("DELETE FROM it_requests WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) req_fail(404, 'Request not found.');
            echo json_encode(['success' => true]);
            break;

        default:
            req_fail(405, 'Method not allowed.');
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}
/* ============================ end API — normal page render continues below ============================ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>ITPMS — IT Project Management System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/requests.css">
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
      <button class="nav-item" data-view="requests"><i data-lucide="clipboard-list"></i> Quick Requests</button>
      <a class="nav-item" href="manager.php" target="_blank" rel="noopener"><i data-lucide="presentation"></i> Manager View</a>
    </nav>

    <div class="sidebar-user">
      <div class="sidebar-user-name"><i data-lucide="user-circle"></i> <?= htmlspecialchars(current_username()) ?></div>
      <a class="sidebar-user-link" href="change_password.php"><i data-lucide="key-round"></i> Change password</a>
      <a class="sidebar-user-link" href="logout.php"><i data-lucide="log-out"></i> Log out</a>
    </div>
    <div class="sidebar-footer"><span>ITPMS v1.0</span></div>
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
          <div class="panel-head-right">
            <div class="search-box">
              <i data-lucide="search"></i>
              <input type="text" id="projectSearch" placeholder="Search name or owner…">
            </div>
            <button class="chip-clear view-hidden" id="chipClear"></button>
            <span class="panel-sub" id="projectsCount"></span>
          </div>
        </div>
        <div class="table-scroll">
          <table class="proj-table">
            <thead><tr>
              <th class="col-no">No.</th>
              <th class="th-sort" data-sort="name">Project<i data-lucide="chevrons-up-down" class="sort-icon"></i></th>
              <th class="col-progress th-sort" data-sort="progress">Progress<i data-lucide="chevrons-up-down" class="sort-icon"></i></th>
              <th class="col-status th-sort" data-sort="status">Status<i data-lucide="chevrons-up-down" class="sort-icon"></i></th>
              <th class="col-actions">Actions</th>
            </tr></thead>
            <tbody id="projectsTableBody"></tbody>
          </table>
        </div>
        <div class="panel-foot" id="projectsPagination"></div>
      </div>
    </section>

    <section class="view view-hidden" id="view-requests">
      <div class="view-header">
        <h1>Quick Requests</h1>
        <p class="view-sub">Small day-to-day IT requests — password resets, printer fixes, app installs. Not full projects, but still real work.</p>
      </div>

      <div class="panel panel-spaced">
        <div class="panel-head"><h2>Log a new request</h2></div>
        <form class="form-grid request-form" id="requestForm">
          <label class="field span-2"><span>What's the request?</span><input type="text" id="rTitle" placeholder="e.g. Reset password for J. Cruz" required></label>
          <label class="field"><span>Requested by</span><input type="text" id="rRequester" placeholder="e.g. J. Cruz (HR)"></label>
          <label class="field"><span>Category</span>
            <select id="rCategory">
              <option value="Hardware">Hardware</option>
              <option value="Software">Software</option>
              <option value="Account/Access">Account/Access</option>
              <option value="Network">Network</option>
              <option value="Other" selected>Other</option>
            </select>
          </label>
          <label class="field"><span>Date &amp; time issued</span><input type="datetime-local" id="rIssued"></label>
          <label class="field"><span>Status</span>
            <select id="rStatus">
              <option value="Open" selected>Open</option>
              <option value="In Progress">In Progress</option>
              <option value="Done">Done (already resolved)</option>
            </select>
          </label>
          <label class="field view-hidden" id="rResolvedWrap"><span>Date &amp; time resolved</span><input type="datetime-local" id="rResolved"></label>
          <label class="field span-2"><span>Notes (optional)</span><textarea id="rNotes" rows="2" placeholder="Anything worth remembering about this one?"></textarea></label>
          <div class="form-actions span-2 form-actions-left">
            <button type="submit" class="btn btn-primary"><i data-lucide="plus"></i> Log request</button>
          </div>
        </form>
      </div>

      <!-- Edit-request modal — reuses the same fields as the log form, pre-filled,
           so a request can be corrected after the fact (e.g. mark Done + backfill
           the resolved date/time you forgot to set at the time). -->
      <div class="modal-overlay view-hidden" id="requestEditOverlay">
        <div class="modal-card modal-form">
          <div class="modal-head">
            <div class="modal-head-left"><h3>Edit request</h3></div>
            <div class="modal-head-right"><button class="icon-btn" id="requestEditClose"><i data-lucide="x"></i></button></div>
          </div>
          <form class="form-grid request-form" id="requestEditForm">
            <input type="hidden" id="reId">
            <label class="field span-2"><span>What's the request?</span><input type="text" id="reTitle" required></label>
            <label class="field"><span>Requested by</span><input type="text" id="reRequester"></label>
            <label class="field"><span>Category</span>
              <select id="reCategory">
                <option value="Hardware">Hardware</option>
                <option value="Software">Software</option>
                <option value="Account/Access">Account/Access</option>
                <option value="Network">Network</option>
                <option value="Other">Other</option>
              </select>
            </label>
            <label class="field"><span>Date &amp; time issued</span><input type="datetime-local" id="reIssued"></label>
            <label class="field"><span>Status</span>
              <select id="reStatus">
                <option value="Open">Open</option>
                <option value="In Progress">In Progress</option>
                <option value="Done">Done</option>
              </select>
            </label>
            <label class="field view-hidden" id="reResolvedWrap"><span>Date &amp; time resolved</span><input type="datetime-local" id="reResolved"></label>
            <label class="field span-2"><span>Notes (optional)</span><textarea id="reNotes" rows="2"></textarea></label>
            <div class="form-actions span-2">
              <button type="button" class="btn btn-ghost" id="requestEditCancel">Cancel</button>
              <button type="submit" class="btn btn-primary">Save changes</button>
            </div>
          </form>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h2>All Requests</h2>
          <span class="panel-sub" id="requestsCount"></span>
        </div>
        <div class="table-scroll">
          <table class="proj-table">
            <thead><tr>
              <th class="col-no">No.</th>
              <th>Request</th>
              <th>Category</th>
              <th class="col-status">Status</th>
              <th>Issued</th>
              <th>Resolved</th>
              <th class="col-actions">Actions</th>
            </tr></thead>
            <tbody id="requestsTableBody"></tbody>
            <!-- requests.js should render an "Edit" (and "Delete") button per row here,
                 with the Edit button calling openRequestEdit(request) below. -->
          </table>
        </div>
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
        <div id="viewFileLinkWrap"></div>
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
      <label class="field span-2"><span>Upload link (Google Drive, SharePoint, etc.)</span><input type="url" id="fFileLink" placeholder="https://drive.google.com/..."></label>

      <!-- Progress history — shown when editing an existing project -->
      <div class="field span-2" id="historySection">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <strong>Progress history</strong>
          <div style="display:flex;gap:8px;align-items:center;">
            <input type="date" id="hDate" style="height:32px;padding:4px;" />
            <input type="number" id="hProgress" min="0" max="100" value="0" style="width:84px;height:32px;padding:4px;" />
            <input type="text" id="hNotes" placeholder="Notes" style="width:320px;height:32px;padding:4px;" />
            <button type="button" class="btn btn-ghost" id="hAddBtn">Add</button>
          </div>
        </div>
        <div id="historyList" style="max-height:180px;overflow:auto;border:1px solid #EDEFF3;border-radius:8px;padding:8px;background:#FBFCFE;"></div>
        <small style="color:#8A8F98;display:block;margin-top:6px;">Entries are stored by date; adding an entry for an existing date will overwrite that date's value.</small>
      </div>

      <label class="field span-2"><span>Previous update (notes)</span><textarea id="fNotes" rows="2" placeholder="Short note or previous update"></textarea></label>

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
    <p><b id="confirmName"></b> will be permanently removed. This can't be undone.</p>
    <div class="form-actions">
      <button class="btn btn-ghost" id="confirmCancel">Cancel</button>
      <button class="btn btn-danger" id="confirmDelete">Delete</button>
    </div>
  </div>
</div>

<div class="toast view-hidden" id="toast"></div>

<script src="assets/js/dashboard.js"></script>
<script src="assets/js/requests.js"></script>
</body>
</html>