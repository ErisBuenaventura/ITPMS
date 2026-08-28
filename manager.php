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

use PhpOffice\PhpPresentation\IOFactory;


function mgr_is_overdue(array $p): bool {
    if (empty($p['end_date'])) return false;
    if (in_array($p['status'], ['Completed', 'Cancelled'], true)) return false;
    return $p['end_date'] < date('Y-m-d');
}

function h($s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
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
<style>
  .mgr-filter-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 16px;border-bottom:1px solid var(--border,#e5e7eb);}
  .mgr-filter-input,.mgr-filter-select{font:inherit;font-size:13px;padding:6px 10px;border-radius:8px;border:1px solid var(--border,#d1d5db);background:var(--panel-bg,#fff);color:inherit;}
  .mgr-filter-input{flex:1 1 200px;min-width:140px;}
  .mgr-filter-select{flex:0 0 auto;}
  .mgr-filter-clear{flex:0 0 auto;font:inherit;font-size:13px;padding:6px 10px;border-radius:8px;border:1px solid transparent;background:transparent;color:var(--muted,#6b7280);cursor:pointer;}
  .mgr-filter-clear:hover{text-decoration:underline;}
  #tableBody tr.mgr-row-hidden{display:none !important;}
</style>
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
      <button type="button" id="printReportBtn" class="print-btn no-print"><i data-lucide="printer"></i> Print / Save as PDF</button>
    </div>
  </div>

  <div class="print-only print-meta">
    Generated <?= h(date('F j, Y \a\t g:i A')) ?> · Snapshot for reporting purposes
  </div>

  <div class="fit-outer" id="fitOuter">
    <div class="fit-inner" id="fitInner">
      <div class="stat-grid" id="statGrid"></div>
      <div class="mgr-columns">
        <div class="mgr-col-left">
          <div class="panel">
            <div class="panel-head">All Projects (<span id="projCount"><?= count($projects) ?></span>)</div>
            <div class="mgr-filter-bar no-print" id="mgrFilterBar">
              <input type="text" id="mgrSearchInput" class="mgr-filter-input" placeholder="Search project or owner…" autocomplete="off">
              <select id="mgrStatusFilter" class="mgr-filter-select"><option value="">All statuses</option></select>
              <select id="mgrPriorityFilter" class="mgr-filter-select"><option value="">All priorities</option></select>
              <button type="button" id="mgrFilterClear" class="mgr-filter-clear">Clear</button>
            </div>
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
<script>
(function () {
  var tableBody   = document.getElementById('tableBody');
  var searchInput = document.getElementById('mgrSearchInput');
  var statusSel   = document.getElementById('mgrStatusFilter');
  var prioritySel = document.getElementById('mgrPriorityFilter');
  var clearBtn    = document.getElementById('mgrFilterClear');
  var projCountEl = document.getElementById('projCount');
  if (!tableBody) return;

  // Column positions in the "All Projects" table (see <thead> above).
  var COL_PROJECT  = 2;
  var COL_PRIORITY = 4;
  var COL_STATUS   = 6;

  var totalRowCount = 0; // real project count, from #projCount's original value

  function cellText(row, colIndex) {
    var cell = row.children[colIndex - 1];
    return cell ? cell.textContent.trim() : '';
  }

  function isDataRow(row) {
    // Skip any "no projects yet" / empty-state row manager.js might render
    // (heuristic: a real row has as many cells as the header).
    return row.tagName === 'TR' && row.children.length >= COL_STATUS;
  }

  function syncOptions() {
    var rows = Array.prototype.filter.call(tableBody.children, isDataRow);
    if (totalRowCount === 0 || rows.length > 0) totalRowCount = rows.length;

    var statuses = new Set();
    var priorities = new Set();
    rows.forEach(function (row) {
      var s = cellText(row, COL_STATUS);
      var p = cellText(row, COL_PRIORITY);
      if (s) statuses.add(s);
      if (p) priorities.add(p);
    });

    fillSelect(statusSel, statuses);
    fillSelect(prioritySel, priorities);
  }

  function fillSelect(select, values) {
    var current = select.value;
    var sorted = Array.from(values).sort();
    var placeholder = select.options[0];
    select.innerHTML = '';
    select.appendChild(placeholder);
    sorted.forEach(function (v) {
      var opt = document.createElement('option');
      opt.value = v;
      opt.textContent = v;
      select.appendChild(opt);
    });
    if (sorted.indexOf(current) !== -1) select.value = current;
  }

  function applyFilter() {
    var q = (searchInput.value || '').trim().toLowerCase();
    var status = statusSel.value;
    var priority = prioritySel.value;
    var rows = Array.prototype.filter.call(tableBody.children, isDataRow);
    var visible = 0;

    rows.forEach(function (row) {
      var project = cellText(row, COL_PROJECT).toLowerCase();
      var owner = cellText(row, COL_PROJECT + 1).toLowerCase(); // Owner column
      var rowStatus = cellText(row, COL_STATUS);
      var rowPriority = cellText(row, COL_PRIORITY);

      var matchesSearch = !q || project.indexOf(q) !== -1 || owner.indexOf(q) !== -1;
      var matchesStatus = !status || rowStatus === status;
      var matchesPriority = !priority || rowPriority === priority;
      var show = matchesSearch && matchesStatus && matchesPriority;

      row.classList.toggle('mgr-row-hidden', !show);
      if (show) visible++;
    });

    if (projCountEl) {
      var filtering = q || status || priority;
      projCountEl.textContent = filtering ? (visible + ' / ' + totalRowCount) : String(totalRowCount);
    }
  }

  function refresh() {
    syncOptions();
    applyFilter();
  }

  searchInput.addEventListener('input', applyFilter);
  statusSel.addEventListener('change', applyFilter);
  prioritySel.addEventListener('change', applyFilter);
  clearBtn.addEventListener('click', function () {
    searchInput.value = '';
    statusSel.value = '';
    prioritySel.value = '';
    applyFilter();
  });

  // manager.js re-renders #tableBody on every live refresh; re-sync the
  // filter options and re-apply the current filter whenever that happens.
  var observer = new MutationObserver(refresh);
  observer.observe(tableBody, { childList: true });

  refresh();
})();
</script>

<script>
(function () {
  // Prints the same #tableBody data as a grouped report (overdue first,
  // then by status) without touching manager.js — this only reorders the
  // already-rendered <tr> elements right before printing, then restores
  // the original live order right after. Works regardless of how
  // manager.js builds each row.
  var tableBody = document.getElementById('tableBody');
  var printBtn = document.getElementById('printReportBtn');
  if (!tableBody) return;

  var COL_STATUS = 6; // Status is the 6th column in the All Projects table
  var GROUP_ORDER = ['__overdue__', 'Ongoing', 'On Hold', 'Not Started', 'Completed', 'Cancelled'];
  var GROUP_LABELS = {
    '__overdue__': 'Overdue / At Risk',
    'Ongoing': 'Ongoing',
    'On Hold': 'On Hold',
    'Not Started': 'Not Started',
    'Completed': 'Completed',
    'Cancelled': 'Cancelled'
  };

  var savedOrder = null;
  var insertedHeaders = [];

  function isDataRow(row) {
    return row.tagName === 'TR' && row.children.length >= COL_STATUS;
  }
  function statusText(row) {
    var cell = row.children[COL_STATUS - 1];
    return cell ? cell.textContent.trim() : '';
  }
  function isOverdue(row) {
    // manager.css defines .overdue-dot for rows past their target end date.
    return !!row.querySelector('.overdue-dot');
  }

  function groupForPrint() {
    var rows = Array.prototype.filter.call(tableBody.children, isDataRow);
    if (!rows.length) return;
    savedOrder = rows.slice();

    var buckets = {};
    GROUP_ORDER.forEach(function (k) { buckets[k] = []; });
    rows.forEach(function (row) {
      var status = statusText(row);
      var key = isOverdue(row) ? '__overdue__' : (GROUP_ORDER.indexOf(status) !== -1 ? status : 'Ongoing');
      buckets[key].push(row);
    });

    var colCount = rows[0].children.length;
    var frag = document.createDocumentFragment();
    insertedHeaders = [];
    GROUP_ORDER.forEach(function (key) {
      var items = buckets[key];
      if (!items.length) return;
      var headerRow = document.createElement('tr');
      headerRow.className = 'print-group-row';
      var td = document.createElement('td');
      td.colSpan = colCount;
      td.textContent = GROUP_LABELS[key] + ' (' + items.length + ')';
      headerRow.appendChild(td);
      insertedHeaders.push(headerRow);
      frag.appendChild(headerRow);
      items.forEach(function (r) { frag.appendChild(r); });
    });

    tableBody.innerHTML = '';
    tableBody.appendChild(frag);
  }

  function restoreOrder() {
    if (!savedOrder) return;
    var frag = document.createDocumentFragment();
    savedOrder.forEach(function (r) { frag.appendChild(r); });
    tableBody.innerHTML = '';
    tableBody.appendChild(frag);
    savedOrder = null;
    insertedHeaders = [];
  }

  window.addEventListener('beforeprint', groupForPrint);
  window.addEventListener('afterprint', restoreOrder);
  if (printBtn) printBtn.addEventListener('click', function () { window.print(); });
})();
</script>
<script src="assets/js/manager.js"></script>
<script src="assets/js/mgr-requests.js"></script>
</body>
</html>