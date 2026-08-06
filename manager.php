<?php
/**
 * ITPMS — Manager Overview (read-only dashboard)
 * ------------------------------------------------------------------
 * Drop this file next to index.php. It has its own small read-only
 * API (manager.php?api=1 for projects, manager.php?requests_api=1 for
 * the employee IT-requests feed) for the initial data and live
 * refreshes, so it works with no login at all.
 *
 * No create/edit/delete controls live here — view only. Layout adapts
 * to phone / tablet / laptop / desktop: a stacked card list on narrow
 * screens, a full table on wider ones. On larger screens the whole
 * dashboard scales to fit one screen with no scrolling; on small
 * phones with many projects it gracefully allows scrolling instead of
 * shrinking text past readability.
 *
 * Intentionally NOT gated by login — this is the view meant to be
 * shared with managers/stakeholders who don't need an account. It
 * still reuses auth.php for the DB connection, just without calling
 * require_login().
 */

require_once __DIR__ . '/auth.php';

function mgr_is_overdue(array $p): bool {
    if (empty($p['end_date'])) return false;
    if (in_array($p['status'], ['Completed', 'Cancelled'], true)) return false;
    return $p['end_date'] < date('Y-m-d');
}

/* Public, read-only API for this page's own refresh/view-modal calls — no
   login required, and no write verbs (POST/PUT/DELETE) exist here at all. */
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $id = isset($_GET['id']) ? trim($_GET['id']) : null;

    try {
        if ($id) {
            $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
            $stmt->execute([$id]);
            $project = $stmt->fetch();
            if (!$project) { http_response_code(404); die(json_encode(['error' => 'Project not found.'])); }

            $hist = $pdo->prepare("SELECT entry_date AS date, progress FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
            $hist->execute([$id]);
            $project['history']  = $hist->fetchAll();
            $project['budget']   = (float) $project['budget'];
            $project['progress'] = (int) $project['progress'];
            $project['overdue']  = mgr_is_overdue($project);
            echo json_encode($project);
        } else {
            $rows = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
            foreach ($rows as &$r) {
                $r['budget']   = (float) $r['budget'];
                $r['progress'] = (int) $r['progress'];
                $r['overdue']  = mgr_is_overdue($r);
            }
            echo json_encode($rows);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

/* Read-only "employee concerns" (Quick IT Requests) feed for the right-hand
   panel — same no-login, view-only rule as everything else on this page. */
if (isset($_GET['requests_api'])) {
    header('Content-Type: application/json');
    try {
        $rows = $pdo->query("SELECT * FROM it_requests ORDER BY created_at DESC")->fetchAll();
        echo json_encode($rows);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}

$projects = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
foreach ($projects as &$p) { $p['overdue'] = mgr_is_overdue($p); }
unset($p);
try {
    $mgrRequests = $pdo->query("SELECT * FROM it_requests ORDER BY created_at DESC")->fetchAll();
} catch (Throwable $e) {
    $mgrRequests = []; // table not ready yet — page still renders, panel just starts empty
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>ITPMS — Manager Overview</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://unpkg.com/lucide@latest/dist/umd/lucide.js"></script>
<link rel="stylesheet" href="assets/css/manager.css">
</head>
<body>

<div class="page">
  <div class="top-bar">
    <div class="brand">
      <div class="brand-mark">IT</div>
      <div>
        <div class="brand-title">ITPMS — Manager Overview</div>
        <div class="brand-sub">All projects, one view</div>
      </div>
    </div>
    <div class="top-bar-right">
      <span class="read-only-badge"><i data-lucide="lock"></i> Read only</span>
      <div class="status-line"><span class="live-dot"></span>Live · last updated <b id="lastUpdated">just now</b></div>
    </div>
  </div>

  <div class="fit-outer" id="fitOuter">
    <div class="fit-inner" id="fitInner">
      <div class="stat-grid" id="statGrid"></div>
      <div class="mgr-columns">
        <div class="mgr-col-left">
          <div class="panel">
            <div class="panel-head">All Projects (<span id="projCount"><?= count($projects) ?></span>)</div>
            <div class="table-scroll">
              <table class="mgr-table">
                <thead><tr><th>#</th><th>Project</th><th>Owner</th><th>Priority</th><th>Progress</th><th>Status</th><th>Files</th><th></th></tr></thead>
                <tbody id="tableBody"></tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="mgr-col-right">
          <div class="panel">
            <div class="panel-head">Employee IT Concerns (<span id="reqCount"><?= count($mgrRequests) ?></span>)</div>
            <div class="table-scroll">
              <table class="mgr-table mgr-table-compact">
                <thead><tr><th>Request</th><th>Category</th><th>Status</th><th>Issued</th><th>Resolved</th></tr></thead>
                <tbody id="mgrRequestsTableBody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal-overlay view-hidden" id="viewModalOverlay">
  <div class="modal-card">
    <div class="modal-head">
      <div class="modal-head-left"><span class="modal-id" id="viewModalId"></span><h3 id="viewModalName"></h3></div>
      <div><span id="viewModalBadge"></span> <button class="icon-btn" id="viewModalClose"><i data-lucide="x"></i></button></div>
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
      </div>
      <div class="view-chart">
        <div class="view-chart-label">Progress trend</div>
        <canvas id="progressChart"></canvas>
      </div>
    </div>
  </div>
</div>

<script>
  window.INITIAL_PROJECTS = <?= json_encode($projects, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
  window.INITIAL_MGR_REQUESTS = <?= json_encode($mgrRequests, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
</script>
<script src="assets/js/manager.js"></script>
<script src="assets/js/mgr-requests.js"></script>
</body>
</html>
