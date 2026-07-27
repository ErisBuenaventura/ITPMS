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

            $stmt = $pdo->prepare("INSERT INTO projects (id, name, status, progress, owner, priority, start_date, end_date, budget, description, file_link)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$newId, $name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null]);

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
            $fileLink = array_key_exists('file_link', $data) ? trim($data['file_link']) : $existing['file_link'];

            $stmt = $pdo->prepare("UPDATE projects SET name=?, status=?, progress=?, owner=?, priority=?, start_date=?, end_date=?, budget=?, description=?, file_link=? WHERE id=?");
            $stmt->execute([$name, $status, $progress, $owner, $priority, $start, $end, $budget, $desc, $fileLink ?: null, $id]);

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
    exit; // stop here for every API request — nothing below this runs
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
<style>
/* ---------------------------------------------------------------
   ITPMS design tokens
   Palette:  bg #F5F6F8 · surface #FFFFFF · ink #1B2430 · accent #2F5D8A
   Status:   Completed #2F9E6B · Ongoing #2F5D8A · On Hold #D6A419
             Cancelled #C4483C · Not Started #8A8F98
   Type:     Display "Space Grotesk" · Body "Inter" · Data "IBM Plex Mono"
   Fully fluid/responsive: works unchanged from small phones to wide desktops.
------------------------------------------------------------------*/
* { box-sizing: border-box; }
:root {
  --bg: #F5F6F8; --surface: #FFFFFF; --ink: #1B2430; --ink-soft: #5B6472; --border: #DCE0E6;
  --accent: #2F5D8A; --accent-soft: #E8EFF6;
  --completed: #2F9E6B; --completed-soft: #E4F5EC;
  --ongoing: #2F5D8A; --ongoing-soft: #E8EFF6;
  --onhold: #D6A419; --onhold-soft: #FBF1DA;
  --cancelled: #C4483C; --cancelled-soft: #FBE7E5;
  --notstarted: #8A8F98; --notstarted-soft: #EEEFF1;
}
html, body { margin: 0; padding: 0; height: 100%; background: var(--bg); color: var(--ink); font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
.mono { font-family: 'IBM Plex Mono', monospace; }
.app-shell { display: flex; min-height: 100vh; }

/* ============ SIDEBAR ============ */
.sidebar { width: 232px; flex-shrink: 0; background: var(--ink); color: #E7EAEE; display: flex; flex-direction: column; padding: 20px 16px; position: sticky; top: 0; height: 100vh; z-index: 30; }
.brand { display: flex; align-items: center; gap: 10px; padding: 4px 4px 22px; }
.brand-mark { width: 34px; height: 34px; border-radius: 8px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 13px; flex-shrink: 0; }
.brand-name { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 15px; line-height: 1.1; color: #fff; }
.brand-sub { font-size: 10.5px; color: #8B94A3; letter-spacing: 0.02em; }
.btn-new-project { width: 100%; margin-bottom: 18px; }
.side-nav { display: flex; flex-direction: column; gap: 4px; flex: 1; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; border: none; background: transparent; color: #B7BECB; font-size: 13.5px; font-weight: 600; cursor: pointer; text-align: left; font-family: 'Inter', sans-serif; transition: background .15s, color .15s; }
.nav-item:hover { background: rgba(255,255,255,0.06); color: #fff; }
.nav-item-active { background: var(--accent); color: #fff; }
.nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }
.sidebar-user { display: flex; flex-direction: column; gap: 2px; padding: 10px 4px 0; border-top: 1px solid rgba(255,255,255,0.08); margin-top: 12px; }
.sidebar-user-name { display: flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; color: #E7EAEE; padding: 6px 8px; }
.sidebar-user-name svg { width: 15px; height: 15px; flex-shrink: 0; }
.sidebar-user-link { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #8B94A3; text-decoration: none; padding: 6px 8px; border-radius: 6px; transition: background .12s, color .12s; }
.sidebar-user-link:hover { background: rgba(255,255,255,0.06); color: #fff; }
.sidebar-user-link svg { width: 14px; height: 14px; flex-shrink: 0; }
.sidebar-footer { font-size: 11px; color: #6B7385; padding: 10px 4px 0; margin-top: 4px; }
.sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(27,36,48,0.45); z-index: 25; }
.sidebar-toggle { display: none; position: fixed; top: 14px; left: 14px; z-index: 40; width: 38px; height: 38px; border-radius: 9px; border: 1px solid var(--border); background: var(--surface); align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(27,36,48,0.1); }

/* ============ MAIN ============ */
.app-main { flex: 1; min-width: 0; padding: clamp(16px, 3vw, 28px) clamp(16px, 4vw, 32px) 48px;
  background: repeating-linear-gradient(0deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px), repeating-linear-gradient(90deg, rgba(47,93,138,0.04) 0 1px, transparent 1px 28px); }
.view-hidden { display: none !important; }
.view-header { margin-bottom: 20px; }
.view-header h1 { font-family: 'Space Grotesk', sans-serif; font-size: clamp(19px, 3.4vw, 22px); font-weight: 700; margin: 0 0 4px; }
.view-sub { font-size: 13px; color: var(--ink-soft); margin: 0; }

/* ============ buttons ============ */
.btn { display: inline-flex; align-items: center; gap: 6px; justify-content: center; padding: 9px 16px; border-radius: 8px; border: none; font-size: 13.5px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; transition: opacity .15s, background .15s; }
.btn svg { width: 15px; height: 15px; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #274d74; }
.btn-ghost { background: #EDEFF3; color: var(--ink); }
.btn-ghost:hover { background: #DCE0E6; }
.btn-danger { background: var(--cancelled); color: #fff; }
.btn-danger:hover { background: #a63b31; }
.btn-block { width: 100%; margin-top: 14px; }

/* ============ stat cards — fluid, auto-fit at any width ============ */
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 14px; margin-bottom: 22px; }
.stat-card { --stat-color: #2F5D8A; --stat-soft: #E8EFF6; background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 16px 14px; text-align: left; cursor: pointer; transition: transform .12s, box-shadow .12s, border-color .12s; position: relative; overflow: hidden; }
.stat-card::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--stat-color); }
.stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(27,36,48,0.08); }
.stat-card-active { border-color: var(--stat-color); box-shadow: 0 0 0 2px var(--stat-soft); }
.stat-icon { width: 30px; height: 30px; border-radius: 8px; background: var(--stat-soft); color: var(--stat-color); display: flex; align-items: center; justify-content: center; margin-bottom: 10px; }
.stat-icon svg { width: 16px; height: 16px; }
.stat-count { font-family: 'Space Grotesk', sans-serif; font-size: clamp(20px, 4vw, 26px); font-weight: 700; line-height: 1; }
.stat-label { font-size: 12px; color: var(--ink-soft); margin-top: 4px; font-weight: 500; }

/* ============ panel / table ============ */
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.panel-head { display: flex; flex-wrap: wrap; gap: 8px; align-items: baseline; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid #EDEFF3; }
.panel-head h2 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 600; margin: 0; }
.panel-sub { font-size: 12px; color: #8A8F98; }
.panel-head-right { display: flex; align-items: center; gap: 10px; }
.chip-clear { display: inline-flex; align-items: center; gap: 4px; background: #EDEFF3; border: none; border-radius: 999px; padding: 4px 10px; font-size: 11.5px; font-weight: 600; color: var(--ink); cursor: pointer; }
.chip-clear svg { width: 12px; height: 12px; }

/* ============ search box ============ */
.search-box { display: flex; align-items: center; gap: 6px; background: #F5F6F8; border: 1px solid var(--border); border-radius: 8px; padding: 6px 10px; }
.search-box svg { width: 14px; height: 14px; color: #8A8F98; flex-shrink: 0; }
.search-box input { border: none; background: transparent; font-family: 'Inter', sans-serif; font-size: 13px; color: var(--ink); outline: none; width: 170px; }

/* ============ sortable headers ============ */
.th-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.th-sort:hover { color: var(--ink); }
.sort-icon { width: 12px; height: 12px; vertical-align: -2px; margin-left: 3px; opacity: .45; }
.th-sort-active .sort-icon { opacity: 1; color: var(--accent); }

/* ============ overdue indicator ============ */
.overdue-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--cancelled); margin-left: 6px; flex-shrink: 0; vertical-align: middle; }
.overdue-tag { color: var(--cancelled); font-weight: 600; }

/* ============ pagination ============ */
.panel-foot { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 20px; border-top: 1px solid #EDEFF3; }
.panel-foot-info { font-size: 12px; color: #8A8F98; }
.pager { display: flex; align-items: center; gap: 4px; }
.pager button { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 6px; border-radius: 7px; border: 1px solid var(--border); background: var(--surface); color: var(--ink); font-size: 12px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; }
.pager button:hover:not(:disabled) { background: #EDEFF3; }
.pager button:disabled { opacity: .4; cursor: default; }
.pager button.pager-active { background: var(--accent); border-color: var(--accent); color: #fff; }
.pager svg { width: 14px; height: 14px; }

.table-scroll { overflow-x: auto; }
.proj-table { width: 100%; border-collapse: collapse; min-width: 560px; }
.proj-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #8A8F98; font-weight: 600; padding: 10px 20px; border-bottom: 1px solid #EDEFF3; white-space: nowrap; }
.proj-table td { padding: 12px 20px; border-bottom: 1px solid #F2F3F5; vertical-align: middle; font-size: 13.5px; }
.proj-table tbody tr:last-child td { border-bottom: none; }
.row-clickable { cursor: pointer; }
.row-clickable:hover td { background: #FAFBFC; }
.col-no { width: 48px; color: #8A8F98; }
.col-progress { width: 220px; }
.col-status { width: 150px; }
.col-actions { width: 156px; white-space: nowrap; }
.proj-name { display: flex; flex-direction: column; gap: 2px; font-weight: 600; }
.proj-id { font-size: 11px; color: #8A8F98; font-weight: 500; }
.empty-row { text-align: center; color: #8A8F98; padding: 30px 0 !important; }

/* ============ badges ============ */
.badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.badge svg { width: 13px; height: 13px; }

/* ============ progress bar (signature element) ============ */
.pbar-wrap { display: flex; align-items: center; gap: 10px; }
.pbar-track { position: relative; flex: 1; height: 8px; background: #EDEFF3; border-radius: 4px; }
.pbar-fill { height: 100%; border-radius: 4px; transition: width .2s; }
.pbar-tick { position: absolute; top: -2px; bottom: -2px; width: 1px; background: rgba(27,36,48,0.08); }
.pbar-value { font-family: 'IBM Plex Mono', monospace; font-size: 12px; font-weight: 600; color: var(--ink-soft); width: 36px; text-align: right; flex-shrink: 0; }
.pbar-compact .pbar-track { height: 6px; }

/* ============ icon buttons ============ */
.icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 7px; border: none; background: transparent; color: var(--ink-soft); cursor: pointer; margin-right: 2px; transition: background .12s, color .12s; }
.icon-btn svg { width: 16px; height: 16px; }
.icon-btn:hover { background: #EDEFF3; color: var(--ink); }
.icon-btn-danger:hover { background: var(--cancelled-soft); color: var(--cancelled); }

/* ============ modal — fluid width, capped height, no forced scroll on desktop ============ */
.modal-overlay { position: fixed; inset: 0; background: rgba(27,36,48,0.45); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
.modal-card { background: var(--surface); border-radius: 14px; width: min(780px, 100%); max-height: 90vh; box-shadow: 0 20px 60px rgba(27,36,48,0.25); display: flex; flex-direction: column; overflow: hidden; }
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 20px; border-bottom: 1px solid #EDEFF3; }
.modal-head-left { display: flex; align-items: baseline; gap: 10px; min-width: 0; }
.modal-head-left h3 { font-family: 'Space Grotesk', sans-serif; font-size: 17px; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.modal-id { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: #8A8F98; background: #F2F3F5; padding: 3px 7px; border-radius: 5px; flex-shrink: 0; }
.modal-head-right { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.view-grid { display: grid; grid-template-columns: 1.05fr 1fr; overflow-y: auto; }
.view-details { padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; border-right: 1px solid #EDEFF3; }
.detail-rows { display: flex; flex-direction: column; gap: 8px; }
.detail-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; padding: 6px 0; border-bottom: 1px dashed #EDEFF3; }
.detail-row span { color: #8A8F98; }
.detail-row b { font-weight: 600; text-align: right; }
.detail-desc { font-size: 13px; color: #4B5563; line-height: 1.5; margin: 0; }
.view-chart { padding: 20px 22px; display: flex; flex-direction: column; height: 320px; }
.view-chart-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #8A8F98; font-weight: 600; margin-bottom: 6px; }
.view-chart canvas { flex: 1; width: 100% !important; height: 100% !important; }

/* ============ form ============ */
.modal-form .form-grid { padding: 20px 22px; display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 0; overflow-y: auto; }
.span-2 { grid-column: span 2; }
.field { display: flex; flex-direction: column; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--ink-soft); }
.field input, .field select, .field textarea { font-family: 'Inter', sans-serif; font-size: 13.5px; font-weight: 500; color: var(--ink); border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; background: #FAFBFC; resize: none; width: 100%; }
.field input:focus, .field select:focus, .field textarea:focus { outline: 2px solid rgba(47,93,138,0.2); border-color: var(--accent); }
.form-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }

/* ============ confirm dialog ============ */
.confirm-card { background: var(--surface); border-radius: 14px; padding: 22px; width: 100%; max-width: 380px; box-shadow: 0 20px 60px rgba(27,36,48,0.25); }
.confirm-card h4 { margin: 0 0 8px; font-family: 'Space Grotesk', sans-serif; font-size: 16px; }
.confirm-card p { margin: 0 0 16px; font-size: 13.5px; color: var(--ink-soft); line-height: 1.5; }

/* ============ toast ============ */
.toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 13px; z-index: 60; box-shadow: 0 10px 30px rgba(0,0,0,0.25); max-width: 90vw; text-align: center; }

/* ============================================================================
   RESPONSIVE BREAKPOINTS — sidebar collapses, table becomes stacked cards
   ============================================================================ */

/* Laptop / narrow desktop: sidebar becomes an overlay drawer */
@media (max-width: 900px) {
  .sidebar { position: fixed; left: 0; top: 0; transform: translateX(-100%); transition: transform .2s; }
  .sidebar.sidebar-open { transform: translateX(0); }
  .sidebar-backdrop.show { display: block; }
  .sidebar-toggle { display: flex; }
  .app-main { padding-top: 68px; }
}

/* Tablet: modal stacks, form goes single column */
@media (max-width: 720px) {
  .view-grid { grid-template-columns: 1fr; }
  .view-details { border-right: none; border-bottom: 1px solid #EDEFF3; }
  .modal-form .form-grid { grid-template-columns: 1fr; }
  .span-2 { grid-column: span 1; }
  .modal-card { max-height: 94vh; }
}

/* Mobile: tables become stacked cards instead of horizontal-scroll grids */
@media (max-width: 640px) {
  .table-scroll { overflow: visible; }
  .proj-table { min-width: 0; }
  .proj-table thead { display: none; }
  .proj-table, .proj-table tbody, .proj-table tr, .proj-table td { display: block; width: 100%; }
  .proj-table tr { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 12px; padding: 6px 14px; }
  .proj-table td { padding: 8px 0; border-bottom: 1px dashed #EDEFF3; }
  .proj-table td:last-child { border-bottom: none; }
  .proj-table td::before { content: attr(data-label); display: block; font-size: 10.5px; color: #8A8F98; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 4px; }
  .col-no { display: none; }
  .col-actions { display: flex; gap: 4px; padding-top: 10px !important; }
  .col-actions::before { display: none; }
  .panel-head { padding: 14px 16px; }
  .modal-head { padding: 14px 16px; }
  .view-details, .modal-form .form-grid, .view-chart { padding: 16px; }
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

<script>
/* ITPMS — front-end logic. Talks to THIS SAME FILE via ?api=1 (AJAX/fetch). */

const API_URL = 'index.php?api=1';

const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);

const OVERDUE = '__OVERDUE__';
const PAGE_SIZE = 10;
const state = {
  projects: [], filterStatus: null, view: 'dashboard',
  search: '', sortKey: null, sortDir: 'asc', page: 1,
};
let chartInstance = null;
let editingId = null;
let pendingDeleteId = null;

function isOverdue(p) {
  if (p.overdue !== undefined) return !!p.overdue; // trust the server-computed flag when present
  if (!p.end_date || ['Completed', 'Cancelled'].includes(p.status)) return false;
  return p.end_date < new Date().toISOString().slice(0, 10);
}

function icons() { if (window.lucide) lucide.createIcons(); }
function money(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function escapeHtml(str) { return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])); }

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
};

async function loadProjects() {
  try { state.projects = await api.list(); renderAll(); }
  catch (e) { showToast(e.message, true); }
}

function renderAll() { renderStatGrid(); renderDashboardTable(); renderProjectsTable(); }

function renderStatGrid() {
  const counts = {}; STATUSES.forEach((s) => (counts[s] = 0));
  state.projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));
  const overdueCount = state.projects.filter(isOverdue).length;

  const statusCards = STATUSES.map((s) => {
    const cfg = STATUS_CONFIG[s];
    const active = state.filterStatus === s ? ' stat-card-active' : '';
    return `<button class="stat-card${active}" style="--stat-color:${cfg.color};--stat-soft:${cfg.soft}" data-status="${s}">
      <div class="stat-icon"><i data-lucide="${cfg.icon}"></i></div>
      <div class="stat-count">${counts[s]}</div>
      <div class="stat-label">${cfg.label}</div></button>`;
  }).join('');

  const overdueActive = state.filterStatus === OVERDUE ? ' stat-card-active' : '';
  const overdueCard = `<button class="stat-card${overdueActive}" style="--stat-color:#C4483C;--stat-soft:#FBE7E5" data-status="${OVERDUE}">
    <div class="stat-icon"><i data-lucide="alert-triangle"></i></div>
    <div class="stat-count">${overdueCount}</div>
    <div class="stat-label">Overdue</div></button>`;

  document.getElementById('statGrid').innerHTML = statusCards + overdueCard;

  document.querySelectorAll('#statGrid .stat-card').forEach((btn) => {
    btn.addEventListener('click', () => {
      const s = btn.dataset.status;
      state.filterStatus = state.filterStatus === s ? null : s;
      state.page = 1;
      switchView('projects');
      renderAll();
    });
  });
  icons();
}

function renderDashboardTable() {
  const rows = state.projects;
  document.getElementById('dashboardCount').textContent = `${rows.length} total projects`;
  document.getElementById('dashboardTableBody').innerHTML = rows.map((p, i) => `
    <tr class="row-clickable" data-id="${p.id}">
      <td class="col-no mono" data-label="No.">${i + 1}</td>
      <td class="proj-name" data-label="Project">${escapeHtml(p.name)}${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress" data-label="Progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status" data-label="Status">${badgeHtml(p.status)}</td>
    </tr>`).join('') || `<tr><td colspan="4" class="empty-row">No projects yet.</td></tr>`;

  document.querySelectorAll('#dashboardTableBody tr[data-id]').forEach((tr) => tr.addEventListener('click', () => openViewModal(tr.dataset.id)));
  icons();
}

function renderProjectsTable() {
  // 1. filter by status card / overdue card
  let rows = state.projects;
  if (state.filterStatus === OVERDUE) rows = rows.filter(isOverdue);
  else if (state.filterStatus) rows = rows.filter((p) => p.status === state.filterStatus);

  // 2. filter by search text (name or owner)
  const q = state.search.trim().toLowerCase();
  if (q) rows = rows.filter((p) => p.name.toLowerCase().includes(q) || (p.owner || '').toLowerCase().includes(q));

  // 3. sort
  if (state.sortKey) {
    const dir = state.sortDir === 'asc' ? 1 : -1;
    rows = [...rows].sort((a, b) => {
      let av = a[state.sortKey], bv = b[state.sortKey];
      if (state.sortKey === 'name') { av = av.toLowerCase(); bv = bv.toLowerCase(); }
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      return 0;
    });
  }

  const totalFiltered = rows.length;
  const totalPages = Math.max(1, Math.ceil(totalFiltered / PAGE_SIZE));
  if (state.page > totalPages) state.page = totalPages;
  if (state.page < 1) state.page = 1;
  const pageRows = rows.slice((state.page - 1) * PAGE_SIZE, state.page * PAGE_SIZE);

  const chip = document.getElementById('chipClear');
  if (state.filterStatus) {
    chip.classList.remove('view-hidden');
    chip.innerHTML = `${state.filterStatus === OVERDUE ? 'Overdue' : STATUS_CONFIG[state.filterStatus].label} <i data-lucide="x"></i>`;
  } else chip.classList.add('view-hidden');

  document.getElementById('projectsCount').textContent = `${totalFiltered} shown`;

  document.querySelectorAll('#view-projects .th-sort').forEach((th) => {
    th.classList.toggle('th-sort-active', th.dataset.sort === state.sortKey);
    const icon = th.querySelector('.sort-icon');
    icon.setAttribute('data-lucide', state.sortKey === th.dataset.sort ? (state.sortDir === 'asc' ? 'chevron-up' : 'chevron-down') : 'chevrons-up-down');
  });

  document.getElementById('projectsTableBody').innerHTML = pageRows.map((p, i) => `
    <tr data-id="${p.id}">
      <td class="col-no mono" data-label="No.">${(state.page - 1) * PAGE_SIZE + i + 1}</td>
      <td class="proj-name" data-label="Project">${escapeHtml(p.name)}${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress" data-label="Progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status" data-label="Status">${badgeHtml(p.status)}</td>
      <td class="col-actions" data-label="Actions">
        ${p.file_link
          ? `<a class="icon-btn" title="Open project files" href="${escapeHtml(p.file_link)}" target="_blank" rel="noopener"><i data-lucide="folder-open"></i></a>`
          : `<span class="icon-btn" title="No upload link set" style="opacity:.3;cursor:default"><i data-lucide="folder-open"></i></span>`}
        <button class="icon-btn btn-view" title="View" data-id="${p.id}"><i data-lucide="eye"></i></button>
        <button class="icon-btn btn-edit" title="Edit" data-id="${p.id}"><i data-lucide="pencil"></i></button>
        <button class="icon-btn icon-btn-danger btn-delete" title="Delete" data-id="${p.id}"><i data-lucide="trash-2"></i></button>
      </td>
    </tr>`).join('') || `<tr><td colspan="5" class="empty-row">No projects match your filters.</td></tr>`;

  renderPagination(totalFiltered, totalPages);

  document.querySelectorAll('.btn-view').forEach((b) => b.addEventListener('click', () => openViewModal(b.dataset.id)));
  document.querySelectorAll('.btn-edit').forEach((b) => b.addEventListener('click', () => openEditModal(b.dataset.id)));
  document.querySelectorAll('.btn-delete').forEach((b) => b.addEventListener('click', () => openDeleteConfirm(b.dataset.id)));
  icons();
}

function renderPagination(totalFiltered, totalPages) {
  const foot = document.getElementById('projectsPagination');
  if (totalFiltered === 0) { foot.innerHTML = ''; return; }

  const from = (state.page - 1) * PAGE_SIZE + 1;
  const to = Math.min(state.page * PAGE_SIZE, totalFiltered);

  let pageBtns = '';
  for (let n = 1; n <= totalPages; n++) {
    // Keep the pager compact: show first, last, current, and immediate neighbors; collapse the rest with an ellipsis.
    if (n === 1 || n === totalPages || Math.abs(n - state.page) <= 1) {
      pageBtns += `<button data-page="${n}" class="${n === state.page ? 'pager-active' : ''}">${n}</button>`;
    } else if (n === 2 || n === totalPages - 1) {
      pageBtns += `<span style="padding:0 2px;color:#8A8F98">…</span>`;
    }
  }

  foot.innerHTML = `
    <span class="panel-foot-info">Showing ${from}–${to} of ${totalFiltered}</span>
    <div class="pager">
      <button data-page="${state.page - 1}" ${state.page === 1 ? 'disabled' : ''}><i data-lucide="chevron-left"></i></button>
      ${pageBtns}
      <button data-page="${state.page + 1}" ${state.page === totalPages ? 'disabled' : ''}><i data-lucide="chevron-right"></i></button>
    </div>`;

  foot.querySelectorAll('button[data-page]').forEach((btn) => {
    btn.addEventListener('click', () => { state.page = Number(btn.dataset.page); renderProjectsTable(); icons(); });
  });
  icons();
}

async function openViewModal(id) {
  let p;
  try { p = await api.get(id); } catch (e) { showToast(e.message, true); return; }

  document.getElementById('viewModalId').textContent = p.id;
  document.getElementById('viewModalName').textContent = p.name;
  document.getElementById('viewModalBadge').innerHTML = badgeHtml(p.status);
  document.getElementById('viewModalProgress').innerHTML = progressBarHtml(p.progress, p.status, false);
  document.getElementById('viewOwner').textContent = p.owner || '—';
  document.getElementById('viewPriority').textContent = p.priority;
  document.getElementById('viewStart').textContent = p.start_date || '—';
  document.getElementById('viewEnd').textContent = p.end_date || '—';
  document.getElementById('viewBudget').textContent = money(p.budget);
  document.getElementById('viewDesc').textContent = p.description || 'No description provided.';
  document.getElementById('viewFileLinkWrap').innerHTML = p.file_link
    ? `<a href="${escapeHtml(p.file_link)}" target="_blank" rel="noopener" class="btn btn-ghost btn-block"><i data-lucide="folder-open"></i> Open project files</a>`
    : `<span class="detail-row"><span>Files</span><b>No link added</b></span>`;
  document.getElementById('viewModalEditBtn').onclick = () => { closeModal('viewModalOverlay'); openEditModal(p.id); };

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
        y: { min: 0, max: 100, ticks: { font: { size: 10 }, color: '#8A8F98' }, grid: { color: '#E4E7EC' } },
        x: { ticks: { font: { size: 10 }, color: '#8A8F98' }, grid: { display: false } },
      },
    },
  });

  openModal('viewModalOverlay');
}

function resetForm() {
  document.getElementById('projectForm').reset();
  document.getElementById('fProgress').value = 0;
  document.getElementById('fProgressLabel').textContent = '0';
  document.getElementById('fStart').value = '';
  document.getElementById('fEnd').value = '';
  document.getElementById('fFileLink').value = '';
}

function openCreateModal() {
  editingId = null; resetForm();
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
  document.getElementById('fFileLink').value = p.file_link || '';
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
    file_link: document.getElementById('fFileLink').value.trim(),
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
  pendingDeleteId = id;
  document.getElementById('confirmName').textContent = `${p.name} (${p.id})`;
  openModal('confirmOverlay');
}

async function confirmDelete() {
  if (!pendingDeleteId) return;
  try {
    await api.remove(pendingDeleteId);
    showToast('Project deleted.');
    closeModal('confirmOverlay');
    pendingDeleteId = null;
    await loadProjects();
  } catch (e) { showToast(e.message, true); }
}

function openModal(id) { document.getElementById(id).classList.remove('view-hidden'); icons(); }
function closeModal(id) { document.getElementById(id).classList.add('view-hidden'); }

function switchView(view) {
  state.view = view;
  document.getElementById('view-dashboard').classList.toggle('view-hidden', view !== 'dashboard');
  document.getElementById('view-projects').classList.toggle('view-hidden', view !== 'projects');
  document.querySelectorAll('.nav-item').forEach((btn) => btn.classList.toggle('nav-item-active', btn.dataset.view === view));
  closeSidebar();
}

function openSidebar() { document.getElementById('sidebar').classList.add('sidebar-open'); document.getElementById('sidebarBackdrop').classList.add('show'); }
function closeSidebar() { document.getElementById('sidebar').classList.remove('sidebar-open'); document.getElementById('sidebarBackdrop').classList.remove('show'); }

document.addEventListener('DOMContentLoaded', () => {
  icons();
  loadProjects();

  document.querySelectorAll('.nav-item').forEach((btn) => btn.addEventListener('click', () => switchView(btn.dataset.view)));
  document.getElementById('btnNewProject').addEventListener('click', openCreateModal);

  document.getElementById('viewModalClose').addEventListener('click', () => closeModal('viewModalOverlay'));
  document.getElementById('viewModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'viewModalOverlay') closeModal('viewModalOverlay'); });

  document.getElementById('formModalClose').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalCancel').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'formModalOverlay') closeModal('formModalOverlay'); });
  document.getElementById('projectForm').addEventListener('submit', submitForm);
  document.getElementById('fProgress').addEventListener('input', (e) => { document.getElementById('fProgressLabel').textContent = e.target.value; });

  document.getElementById('confirmCancel').addEventListener('click', () => { closeModal('confirmOverlay'); pendingDeleteId = null; });
  document.getElementById('confirmDelete').addEventListener('click', confirmDelete);
  document.getElementById('confirmOverlay').addEventListener('click', (e) => { if (e.target.id === 'confirmOverlay') { closeModal('confirmOverlay'); pendingDeleteId = null; } });

  document.getElementById('chipClear').addEventListener('click', () => { state.filterStatus = null; state.page = 1; renderAll(); });

  let searchDebounce;
  document.getElementById('projectSearch').addEventListener('input', (e) => {
    clearTimeout(searchDebounce);
    const value = e.target.value;
    searchDebounce = setTimeout(() => { state.search = value; state.page = 1; renderProjectsTable(); }, 150);
  });

  document.querySelectorAll('#view-projects .th-sort').forEach((th) => {
    th.addEventListener('click', () => {
      const key = th.dataset.sort;
      if (state.sortKey === key) state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
      else { state.sortKey = key; state.sortDir = 'asc'; }
      state.page = 1;
      renderProjectsTable();
    });
  });

  document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.contains('sidebar-open') ? closeSidebar() : openSidebar();
  });
  document.getElementById('sidebarBackdrop').addEventListener('click', closeSidebar);
});
</script>
</body>
</html>