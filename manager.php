<?php

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

  /* --- Owner removed from the "All Projects" table and the view modal ---
     Hidden via CSS (rather than deleting the <th>/<col>/detail-row) so the
     JS in assets/js/manager.js that renders each <tr> / sets #viewOwner
     keeps working untouched — it just paints into elements we hide here. */
  .mgr-col-left .mgr-table thead th:nth-child(3),
  .mgr-col-left .mgr-table tbody td:nth-child(3) {
    display: none;
  }
  #viewOwnerRow {
    display: none;
  }

  /* --- Description in the view modal --- */
  .view-details .detail-section-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .03em;
    text-transform: uppercase;
    color: var(--muted, #8A8F98);
    margin: 14px 0 4px;
  }
  #viewDesc:empty::after {
    content: "No description provided.";
    color: var(--muted, #8A8F98);
    font-style: italic;
  }
  #viewNotes:empty::after {
    content: "No previous update recorded.";
    color: var(--muted, #8A8F98);
    font-style: italic;
  }

  /* --- Employee IT Concerns table ---
     This panel sits inside #fitInner, which the page's own script scales
     down to make everything fit one screen with no scroll. Bigger padding
     just adds height, which makes that auto-scale shrink harder and looks
     more compressed, not less. Going smaller/tighter instead — with
     wrapping so nothing gets cut off — nets out more readable after the
     scale is applied. */
  .mgr-col-right .mgr-table-compact {
    font-size: 11.5px;
    table-layout: fixed;
  }
  .mgr-col-right .mgr-table-compact th,
  .mgr-col-right .mgr-table-compact td {
    padding: 5px 8px;
    line-height: 1.3;
    white-space: normal;
    word-break: break-word;
    vertical-align: top;
  }
  .mgr-col-right .mgr-table-compact thead th {
    font-size: 10px;
    letter-spacing: .02em;
    text-transform: uppercase;
    padding: 5px 8px;
  }
  .mgr-col-right .mgr-table-compact tbody tr + tr td {
    border-top: 1px solid var(--border, #eef0f3);
  }

  /* --- Clickable stat cards + active-filter chip --- */
  #statGrid > * {
    cursor: pointer;
    transition: transform .12s ease, box-shadow .12s ease;
  }
  #statGrid > *:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 14px rgba(16,24,40,.08);
  }
  #statGrid > *.mgr-stat-active {
    outline: 2px solid var(--accent, #3b82f6);
    outline-offset: -2px;
  }
  .mgr-chip-clear {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin-left: 8px;
    font: inherit;
    font-size: 12px;
    font-weight: 600;
    padding: 3px 10px 3px 12px;
    border-radius: 999px;
    border: 1px solid var(--accent, #3b82f6);
    background: var(--accent-soft, #eaf2ff);
    color: var(--accent, #3b82f6);
    cursor: pointer;
  }
  .mgr-chip-clear::after {
    content: "✕";
    font-size: 11px;
  }
  .mgr-chip-clear:hover {
    filter: brightness(0.96);
  }

  /* --- Rebalance the two-column layout ---
     manager.css sets .mgr-columns' grid-template-columns; this narrows the
     "All Projects" side and widens the chart / Employee IT Concerns side.
     Only the ratio changes here — layout mode (grid vs. stacked on mobile)
     is still whatever manager.css decides. */
  .mgr-columns {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  }
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
            <div class="panel-head">
              All Projects (<span id="projCount"><?= count($projects) ?></span>)
              <button type="button" class="mgr-chip-clear view-hidden no-print" id="mgrCardFilterChip"></button>
            </div>
            <div class="mgr-filter-bar no-print" id="mgrFilterBar">
              <input type="text" id="mgrSearchInput" class="mgr-filter-input" placeholder="Search project or owner…" autocomplete="off">
              <select id="mgrStatusFilter" class="mgr-filter-select"><option value="">All statuses</option></select>
              <select id="mgrPriorityFilter" class="mgr-filter-select"><option value="">All priorities</option></select>
              <button type="button" id="mgrFilterClear" class="mgr-filter-clear">Clear</button>
            </div>
            <div class="table-scroll">
              <table class="mgr-table">
                <colgroup>
                  <col style="width:44px">
                  <col style="width:auto">
                  <col style="width:110px">
                  <col style="width:80px">
                  <col style="width:20px">
                  <col style="width:20x">
                  <col style="width:52px">
                </colgroup>
                <thead><tr><th>#</th><th>Project</th><th>Owner</th><th>Priority</th><th>Progress</th><th>Status</th><th>Files</th></tr></thead>
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
                  <col style="width:90px">
                  <col style="width:80px">
                  <col style="width:96px">
                  <col style="width:96px">
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
          <div class="detail-row" id="viewOwnerRow"><span>Owner</span><b id="viewOwner"></b></div>
          <div class="detail-row"><span>Priority</span><b id="viewPriority"></b></div>
          <div class="detail-row"><span>Start date</span><b id="viewStart"></b></div>
          <div class="detail-row"><span>Target end</span><b id="viewEnd"></b></div>
          <div class="detail-row"><span>Budget</span><b id="viewBudget"></b></div>
        </div>
        <div class="detail-section-label">Description</div>
        <p class="detail-desc" id="viewDesc"></p>
        <div class="detail-section-label">Previous update (notes)</div>
        <p class="detail-desc" id="viewNotes"></p>
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
  var statGrid    = document.getElementById('statGrid');
  var cardChip    = document.getElementById('mgrCardFilterChip');
  if (!tableBody) return;

  // Column positions in the "All Projects" table (see <thead> above).
  // Owner (col 3) is still rendered into the DOM by manager.js and is still
  // searchable below — it's just hidden visually via CSS, so these indices
  // are unchanged.
  var COL_PROJECT  = 2;
  var COL_PRIORITY = 4;
  var COL_STATUS   = 6;

  var totalRowCount = 0; // real project count, from #projCount's original value
  var overdueOnly   = false; // set when the "Overdue" stat card is active
  var cardStatus    = null; // status forced by a clicked stat card — tracked
                           // separately from the <select>'s value, since a
                           // status with zero matching rows (e.g. clicking
                           // "Cancelled" when count is 0) never gets an
                           // <option> in the dropdown, so select.value can't
                           // hold it.

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

  function isOverdueRow(row) {
    // manager.css defines .overdue-dot for rows past their target end date.
    return !!row.querySelector('.overdue-dot');
  }

  function applyFilter() {
    var q = (searchInput.value || '').trim().toLowerCase();
    var status = cardStatus !== null ? cardStatus : statusSel.value;
    var priority = prioritySel.value;
    var rows = Array.prototype.filter.call(tableBody.children, isDataRow);
    var visible = 0;

    rows.forEach(function (row) {
      var project = cellText(row, COL_PROJECT).toLowerCase();
      var owner = cellText(row, COL_PROJECT + 1).toLowerCase(); // Owner column (hidden, still searchable)
      var rowStatus = cellText(row, COL_STATUS);
      var rowPriority = cellText(row, COL_PRIORITY);

      var matchesSearch = !q || project.indexOf(q) !== -1 || owner.indexOf(q) !== -1;
      var matchesStatus = !status || rowStatus === status;
      var matchesPriority = !priority || rowPriority === priority;
      var matchesOverdue = !overdueOnly || isOverdueRow(row);
      var show = matchesSearch && matchesStatus && matchesPriority && matchesOverdue;

      row.classList.toggle('mgr-row-hidden', !show);
      if (show) visible++;
    });

    if (projCountEl) {
      var filtering = q || status || priority || overdueOnly;
      projCountEl.textContent = filtering ? (visible + ' / ' + totalRowCount) : String(totalRowCount);
    }

    if (cardChip) {
      if (activeCardLabel) {
        cardChip.textContent = activeCardLabel;
        cardChip.classList.remove('view-hidden');
      } else {
        cardChip.classList.add('view-hidden');
      }
    }
  }

  function refresh() {
    syncOptions();
    applyFilter();
  }

  function setCardHighlight(cardEl) {
    if (!statGrid) return;
    Array.prototype.forEach.call(statGrid.children, function (c) {
      c.classList.remove('mgr-stat-active');
    });
    if (cardEl) cardEl.classList.add('mgr-stat-active');
  }

  function clearCardFilter() {
    overdueOnly = false;
    activeCardLabel = null;
    cardStatus = null;   // add this line
    setCardHighlight(null);
  }

  searchInput.addEventListener('input', applyFilter);
  statusSel.addEventListener('change', function () { clearCardFilter(); applyFilter(); });
  prioritySel.addEventListener('change', applyFilter);
  clearBtn.addEventListener('click', function () {
    searchInput.value = '';
    statusSel.value = '';
    prioritySel.value = '';
    clearCardFilter();
    applyFilter();
  });
  if (cardChip) {
    cardChip.addEventListener('click', function () {
      statusSel.value = '';
      clearCardFilter();
      applyFilter();
    });
  }

  // --- Clickable stat cards ---------------------------------------------
  // #statGrid's cards are rendered by manager.js, whose markup we don't
  // control from here, so this reads each card's own text (stripping any
  // numbers/currency so "Completed 12" or "₱12,000" -> "Completed") and
  // matches it against known status/overdue wording. If your card labels
  // use different wording than this, the matching below is the only place
  // that needs adjusting.
  function normalizeCardLabel(rawText) {
    return (rawText || '').replace(/[\d,.$₱%]+/g, ' ').replace(/\s+/g, ' ').trim();
  }

  if (statGrid) {
    statGrid.addEventListener('click', function (e) {
      var card = e.target.closest('#statGrid > *');
      if (!card) return;

      var lower = normalizeCardLabel(card.textContent).toLowerCase();

      if (/overdue|at risk/.test(lower)) {
        statusSel.value = '';
        overdueOnly = true;
        activeCardLabel = 'Overdue';
        setCardHighlight(card);
        applyFilter();
        return;
      }
      if (/total|all projects/.test(lower)) {
        statusSel.value = '';
        clearCardFilter();
        applyFilter();
        return;
      }

      var matchedStatus = null;
      if (/on\s*hold/.test(lower)) matchedStatus = 'On Hold';
      else if (/not started/.test(lower)) matchedStatus = 'Not Started';
      else if (/cancell?ed/.test(lower)) matchedStatus = 'Cancelled';
      else if (/completed/.test(lower)) matchedStatus = 'Completed';
      else if (/ongoing|in progress/.test(lower)) matchedStatus = 'Ongoing';
      if (!matchedStatus) return; // unrecognized card — leave current filters alone

      // Match case-insensitively against whatever's actually in the
      // dropdown (it's only populated with statuses present in the data).
      var matchedOption = Array.prototype.find.call(statusSel.options, function (o) {
        return o.value && o.value.toLowerCase() === matchedStatus.toLowerCase();
      });

      overdueOnly = false;
      cardStatus = matchedStatus;
      statusSel.value = matchedOption ? matchedOption.value : ''; // best-effort visual sync only
      activeCardLabel = matchedStatus;
      setCardHighlight(card);
      applyFilter();
    });
  }

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

<script>
(function () {
  // Fills in the "Previous update (notes)" field added to the view modal.
  // manager.js doesn't know about #viewNotes, so rather than touch that
  // file, this pulls the value straight from this page's own read-only
  // ?api=1&id=... endpoint whenever the modal is opened (detected by
  // watching #viewModalOverlay lose its "view-hidden" class).
  var overlay = document.getElementById('viewModalOverlay');
  var idEl    = document.getElementById('viewModalId');
  var notesEl = document.getElementById('viewNotes');
  if (!overlay || !idEl || !notesEl) return;

  function currentProjectId() {
    return (idEl.textContent || '').trim().replace(/^#/, '');
  }

  function loadNotes() {
    var id = currentProjectId();
    if (!id) { notesEl.textContent = ''; return; }
    fetch('manager.php?api=1&id=' + encodeURIComponent(id))
      .then(function (r) { return r.json(); })
      .then(function (project) {
        notesEl.textContent = (project && project.notes) ? project.notes : '';
      })
      .catch(function () { notesEl.textContent = ''; });
  }

  var modalObserver = new MutationObserver(function () {
    if (!overlay.classList.contains('view-hidden')) loadNotes();
  });
  modalObserver.observe(overlay, { attributes: true, attributeFilter: ['class'] });
})();
</script>
<script src="assets/js/manager.js"></script>
<script src="assets/js/mgr-requests.js"></script>
</body>
</html>