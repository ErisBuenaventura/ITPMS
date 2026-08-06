const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);
const MIN_SCALE = 0.55; // below this, stop shrinking further and allow internal scroll instead

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
    </tr>`).join('') || `<tr><td colspan="8" class="empty-row"><i data-lucide="inbox" class="empty-icon"></i>No projects yet.</td></tr>`;

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
