<?php
/**
 * ITPMS — Manager Overview (read-only dashboard)
 * ------------------------------------------------------------------
 * Drop this file next to index.php. It has its own small read-only
 * API (manager.php?api=1) for the initial data and live refreshes,
 * so it works with no login at all.
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
    exit;
}

$projects = $pdo->query("SELECT * FROM projects ORDER BY created_at ASC")->fetchAll();
foreach ($projects as &$p) { $p['overdue'] = mgr_is_overdue($p); }
unset($p);
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
<style>
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
html, body { margin: 0; padding: 0; background: var(--bg); color: var(--ink); font-family: 'Inter', sans-serif; -webkit-font-smoothing: antialiased; }
.mono { font-family: 'IBM Plex Mono', monospace; }
.view-hidden { display: none !important; }

/* Desktop/laptop/tablet: no page scroll, dashboard scales to fit.
   Phone: page scroll is allowed (see media query below) — a data
   dashboard squeezed into a phone screen with no scroll at all
   stops being readable, so phones get a normal scrolling list. */
html, body { height: 100%; overflow: hidden; }

.page { display: flex; flex-direction: column; height: 100vh; padding: 18px 26px; }

.top-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 14px; flex-shrink: 0; }
.brand { display: flex; align-items: center; gap: 10px; }
.brand-mark { width: 32px; height: 32px; border-radius: 8px; background: var(--accent); color: #fff; display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: 12px; flex-shrink: 0; }
.brand-title { font-family: 'Space Grotesk', sans-serif; font-weight: 700; font-size: clamp(15px, 3vw, 18px); line-height: 1.1; }
.brand-sub { font-size: 11.5px; color: var(--ink-soft); }
.top-bar-right { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.read-only-badge { display: inline-flex; align-items: center; gap: 5px; background: var(--notstarted-soft); color: var(--ink-soft); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; padding: 5px 10px; border-radius: 999px; white-space: nowrap; }
.read-only-badge svg { width: 12px; height: 12px; }
.status-line { text-align: right; font-size: 11.5px; color: var(--ink-soft); white-space: nowrap; }
.status-line b { color: var(--ink); }
.live-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--completed); margin-right: 5px; animation: pulse 1.6s infinite; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: .35; } }

.fit-outer { flex: 1; min-height: 0; overflow: hidden; position: relative; }
.fit-inner { position: absolute; top: 0; left: 0; transform-origin: top left; }

.stat-grid { display: flex; flex-wrap: wrap; gap: 12px; margin-bottom: 14px; }
.stat-card { --c: #2F5D8A; --s: #E8EFF6; background: var(--surface); border: 1px solid var(--border); border-left: 4px solid var(--c); border-radius: 10px; padding: 12px 18px; flex: 1; min-width: 140px; }
.stat-count { font-family: 'Space Grotesk', sans-serif; font-size: 24px; font-weight: 700; }
.stat-label { font-size: 11.5px; color: var(--ink-soft); font-weight: 500; margin-top: 2px; }

.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.panel-head { padding: 12px 20px; border-bottom: 1px solid #EDEFF3; font-family: 'Space Grotesk', sans-serif; font-weight: 600; font-size: 15px; }

/* ---------- table (tablet / laptop / desktop) ---------- */
table.mgr-table { border-collapse: collapse; width: 100%; }
table.mgr-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: .04em; color: #8A8F98; font-weight: 600; padding: 9px 18px; border-bottom: 1px solid #EDEFF3; white-space: nowrap; }
table.mgr-table td { padding: 10px 18px; border-bottom: 1px solid #F2F3F5; vertical-align: middle; font-size: 13px; white-space: nowrap; }
table.mgr-table tbody tr:last-child td { border-bottom: none; }
.proj-name { font-weight: 600; }
.proj-id { font-size: 10.5px; color: #8A8F98; font-weight: 500; margin-left: 6px; }
.overdue-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: var(--cancelled); margin-left: 6px; vertical-align: middle; }

.badge { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.badge svg { width: 12px; height: 12px; }

.pbar-wrap { display: flex; align-items: center; gap: 8px; width: 190px; }
.pbar-track { position: relative; flex: 1; height: 7px; background: #EDEFF3; border-radius: 4px; }
.pbar-fill { height: 100%; border-radius: 4px; }
.pbar-value { font-family: 'IBM Plex Mono', monospace; font-size: 11px; font-weight: 600; color: var(--ink-soft); width: 32px; text-align: right; }

.row-actions { display: flex; align-items: center; gap: 2px; }
.icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; border-radius: 7px; border: none; background: transparent; color: var(--ink-soft); cursor: pointer; text-decoration: none; transition: background .12s, color .12s; }
.icon-btn svg { width: 15px; height: 15px; }
.icon-btn:hover { background: #EDEFF3; color: var(--ink); }
.icon-btn-disabled { opacity: .3; cursor: default; }
.icon-btn-disabled:hover { background: transparent; color: var(--ink-soft); }

/* ---------- card list (phone) ---------- */
.card-list { display: none; flex-direction: column; gap: 10px; padding: 14px; overflow-y: auto; }
.proj-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 14px 16px; }
.proj-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
.proj-card-name { font-weight: 600; font-size: 14px; }
.proj-card-id { font-size: 10.5px; color: #8A8F98; font-weight: 500; }
.proj-card-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12px; color: var(--ink-soft); margin-bottom: 10px; }
.proj-card-meta b { color: var(--ink); font-weight: 600; }
.proj-card-foot { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

/* ---------- view modal (progress chart, read-only) ---------- */
.modal-overlay { position: fixed; inset: 0; background: rgba(27,36,48,0.45); backdrop-filter: blur(2px); display: flex; align-items: center; justify-content: center; z-index: 50; padding: 16px; }
.modal-card { background: var(--surface); border-radius: 14px; width: min(760px, 100%); max-height: 90vh; box-shadow: 0 20px 60px rgba(27,36,48,0.25); display: flex; flex-direction: column; overflow: hidden; }
.modal-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 16px 20px; border-bottom: 1px solid #EDEFF3; }
.modal-head-left { display: flex; align-items: baseline; gap: 10px; min-width: 0; }
.modal-head-left h3 { font-family: 'Space Grotesk', sans-serif; font-size: 16px; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.modal-id { font-family: 'IBM Plex Mono', monospace; font-size: 11px; color: #8A8F98; background: #F2F3F5; padding: 3px 7px; border-radius: 5px; flex-shrink: 0; }
.view-grid { display: grid; grid-template-columns: 1.05fr 1fr; overflow-y: auto; }
.view-details { padding: 18px 20px; display: flex; flex-direction: column; gap: 12px; border-right: 1px solid #EDEFF3; }
.detail-rows { display: flex; flex-direction: column; gap: 8px; }
.detail-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; font-size: 13px; padding: 6px 0; border-bottom: 1px dashed #EDEFF3; }
.detail-row span { color: #8A8F98; }
.detail-row b { font-weight: 600; text-align: right; }
.detail-desc { font-size: 13px; color: #4B5563; line-height: 1.5; margin: 0; }
.view-chart { padding: 18px 20px; display: flex; flex-direction: column; height: 300px; }
.view-chart-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: #8A8F98; font-weight: 600; margin-bottom: 6px; }
.view-chart canvas { flex: 1; width: 100% !important; height: 100% !important; }
.btn-block { display: flex; align-items: center; justify-content: center; gap: 6px; width: 100%; padding: 9px 16px; border-radius: 8px; border: none; font-size: 13.5px; font-weight: 600; cursor: pointer; background: #EDEFF3; color: var(--ink); text-decoration: none; }
.btn-block:hover { background: #DCE0E6; }
.btn-block svg { width: 15px; height: 15px; }

@media (max-width: 720px) {
  .view-grid { grid-template-columns: 1fr; }
  .view-details { border-right: none; border-bottom: 1px solid #EDEFF3; }
}

/* ---------- PHONE breakpoint: switch table -> cards, allow scroll ---------- */
@media (max-width: 680px) {
  html, body { height: auto; overflow: auto; }
  .page { height: auto; padding: 14px 14px 28px; }
  .fit-outer { overflow: visible; }
  .fit-inner { position: static; transform: none !important; width: 100%; }
  .panel > div:not(.panel-head) { display: none; }
  .card-list { display: flex; }
  .status-line { text-align: left; }
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
    </div>
  </div>

  <div class="fit-outer" id="fitOuter">
    <div class="fit-inner" id="fitInner">
      <div class="stat-grid" id="statGrid"></div>
      <div class="panel">
        <div class="panel-head">All Projects (<span id="projCount"><?= count($projects) ?></span>)</div>
        <table class="mgr-table">
          <thead><tr><th>#</th><th>Project</th><th>Owner</th><th>Priority</th><th>Progress</th><th>Status</th><th>Files</th><th></th></tr></thead>
          <tbody id="tableBody"></tbody>
        </table>
        <div class="card-list" id="cardList"></div>
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
const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);
const MIN_SCALE = 0.55; // below this, stop shrinking further and allow internal scroll instead

let projects = <?= json_encode($projects, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
let chartInstance = null;

function money(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

function isOverdue(p) {
  if (p.overdue !== undefined) return !!p.overdue;
  if (!p.end_date || ['Completed', 'Cancelled'].includes(p.status)) return false;
  return p.end_date < new Date().toISOString().slice(0, 10);
}

function badgeHtml(status) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG['Not Started'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${cfg.label}</span>`;
}

function progressBarHtml(value, status) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG['Not Started'];
  return `<div class="pbar-wrap">
    <div class="pbar-track"><div class="pbar-fill" style="width:${value}%;background:${cfg.color}"></div></div>
    <span class="pbar-value mono">${value}%</span></div>`;
}

function fileCellHtml(p) {
  return p.file_link
    ? `<a class="icon-btn" href="${escapeHtml(p.file_link)}" target="_blank" rel="noopener" title="Open project files"><i data-lucide="folder-open"></i></a>`
    : `<span class="icon-btn icon-btn-disabled" title="No upload link set"><i data-lucide="folder-open"></i></span>`;
}

function render() {
  const counts = {}; STATUSES.forEach((s) => (counts[s] = 0));
  projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));

  const overdueCount = projects.filter(isOverdue).length;

  document.getElementById('statGrid').innerHTML = STATUSES.map((s) => {
    const cfg = STATUS_CONFIG[s];
    return `<div class="stat-card" style="--c:${cfg.color};--s:${cfg.soft}">
      <div class="stat-count">${counts[s]}</div>
      <div class="stat-label">${cfg.label}</div></div>`;
  }).join('') + `<div class="stat-card" style="--c:#C4483C;--s:#FBE7E5">
      <div class="stat-count">${overdueCount}</div>
      <div class="stat-label">Overdue</div></div>`;

  document.getElementById('projCount').textContent = projects.length;

  // table (tablet / laptop / desktop)
  document.getElementById('tableBody').innerHTML = projects.map((p, i) => `
    <tr>
      <td class="mono">${i + 1}</td>
      <td><span class="proj-name">${escapeHtml(p.name)}</span>${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}<span class="proj-id mono">${p.id}</span></td>
      <td>${escapeHtml(p.owner || '—')}</td>
      <td>${escapeHtml(p.priority)}</td>
      <td>${progressBarHtml(p.progress, p.status)}</td>
      <td>${badgeHtml(p.status)}</td>
      <td>${fileCellHtml(p)}</td>
      <td><div class="row-actions"><button class="icon-btn btn-view" title="View progress" data-id="${p.id}"><i data-lucide="eye"></i></button></div></td>
    </tr>`).join('') || `<tr><td colspan="8" style="text-align:center;color:#8A8F98;padding:24px 0">No projects yet.</td></tr>`;

  // card list (phone)
  document.getElementById('cardList').innerHTML = projects.map((p) => `
    <div class="proj-card">
      <div class="proj-card-head">
        <div><div class="proj-card-name">${escapeHtml(p.name)}${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}</div><div class="proj-card-id mono">${p.id}</div></div>
        ${badgeHtml(p.status)}
      </div>
      <div class="proj-card-meta">
        <span>Owner: <b>${escapeHtml(p.owner || '—')}</b></span>
        <span>Priority: <b>${escapeHtml(p.priority)}</b></span>
      </div>
      <div class="proj-card-foot">
        ${progressBarHtml(p.progress, p.status)}
        <div class="row-actions">
          ${fileCellHtml(p)}
          <button class="icon-btn btn-view" title="View progress" data-id="${p.id}"><i data-lucide="eye"></i></button>
        </div>
      </div>
    </div>`).join('') || `<div style="text-align:center;color:#8A8F98;padding:24px 0">No projects yet.</div>`;

  document.querySelectorAll('.btn-view').forEach((b) => b.addEventListener('click', () => openViewModal(b.dataset.id)));

  if (window.lucide) lucide.createIcons();
  requestAnimationFrame(fitToScreen);
}

function fitToScreen() {
  // Phones use natural document flow + scrolling (see CSS media query) — skip scaling there.
  if (window.matchMedia('(max-width: 680px)').matches) return;

  const outer = document.getElementById('fitOuter');
  const inner = document.getElementById('fitInner');
  const outerW = outer.clientWidth;
  const outerH = outer.clientHeight;

  // Step 1: render at the container's full width (no transform yet) to
  // measure how tall the content naturally is once it's stretched out.
  inner.style.transform = 'none';
  inner.style.width = outerW + 'px';
  const naturalH = inner.scrollHeight;

  let scale = Math.min(1, outerH / naturalH);
  if (scale < MIN_SCALE) {
    scale = MIN_SCALE;
    outer.style.overflowY = 'auto';
  } else {
    outer.style.overflowY = 'hidden';
  }

  // Step 2: widen the content by 1/scale so that after scaling it back
  // down, it exactly fills outerW again — no leftover space on the right.
  inner.style.width = (outerW / scale) + 'px';
  inner.style.transform = `scale(${scale})`;
}

async function openViewModal(id) {
  const p = projects.find((x) => x.id === id);
  if (!p) return;
  let full;
  try {
    const res = await fetch(`manager.php?api=1&id=${encodeURIComponent(id)}`);
    full = await res.json();
  } catch (e) { full = { ...p, history: [] }; }

  document.getElementById('viewModalId').textContent = p.id;
  document.getElementById('viewModalName').textContent = p.name;
  document.getElementById('viewModalBadge').innerHTML = badgeHtml(p.status);
  document.getElementById('viewModalProgress').innerHTML = progressBarHtml(p.progress, p.status);
  document.getElementById('viewOwner').textContent = p.owner || '—';
  document.getElementById('viewPriority').textContent = p.priority;
  document.getElementById('viewStart').textContent = p.start_date || '—';
  document.getElementById('viewEnd').textContent = p.end_date || '—';
  document.getElementById('viewBudget').textContent = money(p.budget);
  document.getElementById('viewDesc').textContent = p.description || 'No description provided.';
  document.getElementById('viewFileLinkWrap').innerHTML = p.file_link
    ? `<a href="${escapeHtml(p.file_link)}" target="_blank" rel="noopener" class="btn-block"><i data-lucide="folder-open"></i> Open project files</a>`
    : `<span class="detail-row"><span>Files</span><b>No link added</b></span>`;

  const history = full.history || [];
  const ctx = document.getElementById('progressChart').getContext('2d');
  if (chartInstance) chartInstance.destroy();
  chartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: history.map((h) => h.date.slice(5)),
      datasets: [{
        label: 'Progress', data: history.map((h) => h.progress),
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

  document.getElementById('viewModalOverlay').classList.remove('view-hidden');
  if (window.lucide) lucide.createIcons();
}

function closeViewModal() { document.getElementById('viewModalOverlay').classList.add('view-hidden'); }

async function refresh() {
  try {
    const res = await fetch('manager.php?api=1');
    if (!res.ok) return;
    projects = await res.json();
    document.getElementById('lastUpdated').textContent = new Date().toLocaleTimeString();
    render();
  } catch (e) { /* keep showing last known data if the request fails */ }
}

window.addEventListener('resize', () => requestAnimationFrame(fitToScreen));
document.addEventListener('DOMContentLoaded', () => {
  render();
  document.getElementById('viewModalClose').addEventListener('click', closeViewModal);
  document.getElementById('viewModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'viewModalOverlay') closeViewModal(); });
  setInterval(refresh, 30000); // background refresh every 30s — fully live, no page reload
});
</script>
</body>
</html>
