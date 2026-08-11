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

// PPTX export handler — manager.php?export=pptx
if (isset($_GET['export']) && (isset($_GET['format']) && $_GET['format'] === 'pptx' || (isset($_GET['export_pptx']) && $_GET['export_pptx']))) {
    $template = __DIR__ . '/assets/templates/IT_DEPARTMENT_MANCOM_REPORT.pptx';
    if (!file_exists($template)) {
        http_response_code(500);
        echo "Template not found at: {$template}. Please place your PPTX template at that path.";
        exit;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        http_response_code(500);
        echo "PHPPresentation not installed. Run in your project root: composer require phpoffice/phppresentation";
        exit;
    }

    require_once $autoload;

    try {
        // Load template
        $ppt = \PhpOffice\PhpPresentation\IOFactory::load($template);

        // Gather projects and group them
        $stmt = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC");
        $rows = $stmt->fetchAll();

        $completed = '';
        $ongoing = '';
        $onhold = '';
        $notstarted = '';

        foreach ($rows as $p) {
            $line = '• ' . ($p['name'] ?? '') . ' (' . ($p['priority'] ?? 'Unknown') . ' Priority)';
            $status = strtolower(trim($p['status'] ?? ''));
            if ($status === 'completed') {
                $completed .= $line . PHP_EOL;
                continue;
            }
            if ($status === 'on hold' || $status === 'on-hold') {
                $onhold .= $line . PHP_EOL;
                continue;
            }
            if ($status === 'not started' || $status === 'not-started' || $status === '') {
                $notstarted .= $line . PHP_EOL;
                continue;
            }
            // treat others as ongoing/in progress
            // fetch last two history notes
            $hstmt = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date DESC LIMIT 2");
            $hstmt->execute([$p['id']]);
            $hist = $hstmt->fetchAll();
            $curr = isset($hist[0]['notes']) && strlen(trim((string)$hist[0]['notes'])) ? $hist[0]['notes'] : (isset($p['notes']) && strlen(trim((string)$p['notes'])) ? $p['notes'] : (isset($p['description']) ? $p['description'] : 'None'));
            $prev = isset($hist[1]['notes']) && strlen(trim((string)$hist[1]['notes'])) ? $hist[1]['notes'] : 'None';
            $link = !empty($p['file_link']) ? $p['file_link'] : '';

            $ongoing .= $line . PHP_EOL;
            $ongoing .= 'Previous: ' . $prev . PHP_EOL;
            $ongoing .= 'Current: ' . $curr . PHP_EOL;
            if ($link) { $ongoing .= 'Link:' . PHP_EOL . $link . PHP_EOL; }
            $ongoing .= PHP_EOL;
        }

        $placeholders = [
            '{{REPORT_TITLE}}' => 'IT Project Status Update',
            '{{REPORT_DATE}}' => 'As of ' . date('F d, Y'),
            '{{COMPLETED_LIST}}' => $completed,
            '{{ONGOING_LIST}}' => $ongoing,
            '{{ON_HOLD_LIST}}' => $onhold,
            '{{NOT_STARTED_LIST}}' => $notstarted,
            '{{ALL_TRACKER_LINK}}' => 'https://itpms.infinityfreeapp.com/manager.php',
        ];

        // Replace placeholders in all text shapes
        foreach ($ppt->getAllSlides() as $slide) {
            foreach ($slide->getShapeCollection() as $shape) {
                // RichText shapes
                if ($shape instanceof \PhpOffice\PhpPresentation\Shape\RichText) {
                    foreach ($shape->getParagraphs() as $p) {
                        foreach ($p->getRichTextElements() as $rte) {
                            if ($rte instanceof \PhpOffice\PhpPresentation\Shape\RichText\TextElement) {
                                $text = $rte->getText();
                                $new = strtr($text, $placeholders);
                                if ($new !== $text) { $rte->setText($new); }
                            }
                        }
                    }
                } else {
                    // Some shapes expose getText/setText
                    if (method_exists($shape, 'getText') && method_exists($shape, 'setText')) {
                        try {
                            $text = $shape->getText();
                            $new = strtr($text, $placeholders);
                            if ($new !== $text) { $shape->setText($new); }
                        } catch (Throwable $e) { /* ignore shapes that don't support text access */ }
                    }
                }
            }
        }

        // Stream PPTX to client
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="IT_Project_Status_Update_' . date('Ymd') . '.pptx"');

        $writer = \PhpOffice\PhpPresentation\IOFactory::createWriter($ppt, 'PowerPoint2007');
        $writer->save('php://output');
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo 'Export error: ' . $e->getMessage();
        exit;
    }
}


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

            $hist = $pdo->prepare("SELECT entry_date AS date, progress, notes FROM progress_history WHERE project_id = ? ORDER BY entry_date ASC");
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
      <button id="exportTxtBtn" class="btn export-btn" title="Export status as text"><i data-lucide="download"></i> Export</button>
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
                <colgroup>
                  <col style="width:48px">
                  <col style="width:auto">
                  <col style="width:110px">
                  <col style="width:90px">
                  <col style="width:220px">
                  <col style="width:120px">
                  <col style="width:56px">
                  <col style="width:56px">
                </colgroup>
                <thead><tr><th>#</th><th>Project</th><th>Owner</th><th>Priority</th><th>Progress</th><th>Status</th><th>Files</th><th></th></tr></thead>
                <tbody id="tableBody"></tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="mgr-col-right">
          <div class="panel">
            <div class="panel-head">Projects — 2026</div>
            <div class="panel-body">
              <div class="chart-grid single-chart">
                <div class="chart-box full-width"><canvas id="monthlyLineChart"></canvas></div>
              </div>
            </div>
          </div>

          <div class="panel">
            <div class="panel-head">Employee IT Concerns (<span id="reqCount"><?= count($mgrRequests) ?></span>)</div>
            <div class="table-scroll">
              <table class="mgr-table mgr-table-compact">
                <colgroup>
                  <col style="width:auto">
                  <col style="width:120px">
                  <col style="width:120px">
                  <col style="width:120px">
                  <col style="width:120px">
                </colgroup>
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
