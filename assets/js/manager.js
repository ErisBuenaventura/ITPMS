const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);
const MIN_SCALE = 0.45; // lower minimum scale so page compresses more instead of enabling scroll

let projects = window.INITIAL_PROJECTS || [];
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

let lineChart2026Instance = null;

function drawLineChart2026() {
  const allLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const counts = new Array(12).fill(0);

  projects.forEach(p => {
    const sd = p.start_date || p.created_at;
    if (!sd) return;
    const d = new Date(sd.replace(' ', 'T'));
    if (isNaN(d)) return;
    if (d.getFullYear() !== 2026) return;
    counts[d.getMonth()]++;
  });

  // determine last month to display: last month with any project, otherwise current month
  let lastIndex = counts.map((c, i) => ({c,i})).filter(x=>x.c>0).map(x=>x.i).pop();
  if (lastIndex === undefined) lastIndex = new Date().getMonth();
  // ensure at least Jan is shown
  lastIndex = Math.max(0, lastIndex);

  const labels = allLabels.slice(0, lastIndex + 1);
  const data = counts.slice(0, lastIndex + 1);

  const ctx = document.getElementById('monthlyLineChart')?.getContext?.('2d');
  if (!ctx) return;

  if (lineChart2026Instance) {
    lineChart2026Instance.data.labels = labels;
    lineChart2026Instance.data.datasets[0].data = data;
    lineChart2026Instance.update();
  } else {
    lineChart2026Instance = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label: 'Projects started', data: data, borderColor: '#2F5D8A', backgroundColor: '#2F5D8A22', tension: 0.35, fill: true, pointRadius: 3 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1 }, grid: { color: '#E4E7EC' } }, x: { grid: { display: false } } } }
    });
  }
}

function render() {
  const counts = {}; STATUSES.forEach((s) => (counts[s] = 0));
  projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));

  const overdueCount = projects.filter(isOverdue).length;

  // build status cards + overdue
  let statusHtml = STATUSES.map((s) => {
    const cfg = STATUS_CONFIG[s];
    return `<div class="stat-card" style="--c:${cfg.color};--s:${cfg.soft}">
      <div class="stat-count">${counts[s]}</div>
      <div class="stat-label">${cfg.label}</div></div>`;
  }).join('');
  statusHtml += `<div class="stat-card" style="--c:#C4483C;--s:#FBE7E5">
      <div class="stat-count">${overdueCount}</div>
      <div class="stat-label">Overdue</div></div>`;

  document.getElementById('statGrid').innerHTML = statusHtml;

  // update charts: line chart for projects per month in 2026 based on start_date
  drawLineChart2026();

  document.getElementById('projCount').textContent = projects.length;

  // table (tablet / laptop / desktop)
  document.getElementById('tableBody').innerHTML = projects.map((p, i) => `
    <tr>
      <td class="mono">${i + 1}</td>
      <td><span class="proj-name" title="${escapeHtml(p.name)}">${escapeHtml(p.name)}</span>${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}<span class="proj-id mono">${p.id}</span></td>
      <td>${escapeHtml(p.owner || '—')}</td>
      <td>${escapeHtml(p.priority)}</td>
      <td>${progressBarHtml(p.progress, p.status)}</td>
      <td>${badgeHtml(p.status)}</td>
      <td>${fileCellHtml(p)}</td>
      <td>
        <div class="row-actions">
          <button class="icon-btn btn-view" title="View details" data-id="${p.id}"><i data-lucide="eye"></i></button>
        </div>
      </td>
    </tr>`).join('') || `<tr><td colspan="8" class="empty-row"><i data-lucide="inbox" class="empty-icon"></i>No projects yet.</td></tr>`;

  document.querySelectorAll('.btn-view').forEach((b) => b.addEventListener('click', () => openViewModal(b.dataset.id)));

  if (window.lucide) lucide.createIcons();
  requestAnimationFrame(fitToScreen);
}

function fitToScreen() {
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
  // If content is taller than container even at MIN_SCALE, allow vertical scrolling
  let allowVScroll = false;
  if (scale < MIN_SCALE) {
    scale = MIN_SCALE;
    if (naturalH > outerH) allowVScroll = true;
  }
  outer.style.overflowY = allowVScroll ? 'auto' : 'hidden';

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

// Build a plain-text export that mirrors the report layout you provided.
function formatDateLong(d) {
  return new Intl.DateTimeFormat('en-US', { year: 'numeric', month: 'long', day: '2-digit' }).format(d);
}

function normalizePriority(p) {
  if (!p) return '';
  const s = String(p).toLowerCase();
  if (s.includes('top')) return 'Top Priority';
  if (s.includes('med')) return 'Medium Priority';
  if (s.includes('low')) return 'Low Priority';
  return p;
}

async function buildExportText(projects) {
  const now = new Date();
  let out = '';
  out += 'IT Project Status Update\n';
  out += 'As of ' + formatDateLong(now) + '\n\n';

  const groups = { 'Completed': [], 'Ongoing': [], 'On Hold': [], 'Not Started': [] };
  projects.forEach((p) => {
    let s = p.status || '';
    if (s === 'Onhold') s = 'On Hold';
    if (s === 'On Hold' || s === 'Onhold') s = 'On Hold';
    if (!groups[s]) groups[s] = [];
    groups[s].push(p);
  });

  for (const section of ['Completed', 'Ongoing', 'On Hold', 'Not Started']) {
    out += section.toUpperCase() + '\n';
    const list = groups[section] || [];
    if (list.length === 0) {
      out += '\n';
      continue;
    }
    for (const p of list) {
      const pr = normalizePriority(p.priority);
      out += `• ${p.name}${pr ? ' (' + pr + ')' : ''}\n`;

      if (section === 'Ongoing') {
        // Try to fetch detailed history for richer notes (previous/current) — fall back to description if fetch fails
        let previous = '';
        let current = '';
        try {
          const res = await fetch(`manager.php?api=1&id=${encodeURIComponent(p.id)}`);
          if (res.ok) {
            const full = await res.json();
            const hist = Array.isArray(full.history) ? full.history.slice().sort((a,b) => a.date.localeCompare(b.date)) : [];
            if (hist.length >= 2) previous = (hist[hist.length - 2].notes || '').trim();
            if (hist.length >= 1) current = (hist[hist.length - 1].notes || '').trim();
          }
        } catch (e) { /* ignore — leave notes empty */ }

        if (!previous) previous = 'None';
        if (!current) current = p.description ? String(p.description).trim() : 'None';

        out += `Previous: ${previous.replace(/\n+/g, ' ')}\n`;
        out += `Current: ${current.replace(/\n+/g, ' ')}\n`;
        if (p.file_link) out += `Link:\n${p.file_link}\n`;
        out += '\n';
      }
    }
    out += '\n';
  }

  out += 'All Project Tracker:\n' + window.location.href + '\n\n';
  out += '___________________________________________________\n';
  return out;
}

function downloadText(filename, text) {
  const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

async function exportAllAsText() {
  const txt = await buildExportText(projects);
  const datePart = new Date().toISOString().slice(0,10).replace(/-/g,'');
  downloadText(`IT_Project_Status_Update_${datePart}.txt`, txt);
}

function exportAllAsPptx() {
  // Trigger server-side PPTX generation and download using the template
  const url = `manager.php?export=1&format=pptx`;
  // open in a new tab to allow the browser to handle the download
  window.open(url, '_blank');
}

window.addEventListener('resize', () => requestAnimationFrame(fitToScreen));
document.addEventListener('DOMContentLoaded', () => {
  render();
  document.getElementById('viewModalClose').addEventListener('click', closeViewModal);
  document.getElementById('viewModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'viewModalOverlay') closeViewModal(); });

  const exportBtn = document.getElementById('exportTxtBtn');
  if (exportBtn) exportBtn.addEventListener('click', exportAllAsPptx);

  setInterval(refresh, 30000); // background refresh every 30s — fully live, no page reload
});
