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

  // Open Requests card — only shown once requests.js has loaded its data (reqState is defined there).
  let requestsCard = '';
  if (typeof reqState !== 'undefined') {
    const openCount = reqState.requests.filter((r) => r.status !== 'Done').length;
    requestsCard = `<button class="stat-card" style="--stat-color:#2F5D8A;--stat-soft:#E8EFF6" id="statOpenRequests">
      <div class="stat-icon"><i data-lucide="clipboard-list"></i></div>
      <div class="stat-count">${openCount}</div>
      <div class="stat-label">Open Requests</div></button>`;
  }

  document.getElementById('statGrid').innerHTML = statusCards + overdueCard + requestsCard;
  const statOpenRequests = document.getElementById('statOpenRequests');
  if (statOpenRequests) statOpenRequests.addEventListener('click', () => switchView('requests'));

  document.querySelectorAll('#statGrid .stat-card').forEach((btn) => {
    if (!btn.dataset.status) return; // skip the Open Requests card — it has its own click handler above
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
    </tr>`).join('') || `<tr><td colspan="4" class="empty-row"><i data-lucide="inbox" class="empty-icon"></i>No projects yet.</td></tr>`;

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
    </tr>`).join('') || `<tr><td colspan="5" class="empty-row"><i data-lucide="search-x" class="empty-icon"></i>No projects match your filters.</td></tr>`;

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
  document.getElementById('view-requests').classList.toggle('view-hidden', view !== 'requests');
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
