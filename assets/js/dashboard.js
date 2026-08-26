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

// Export current project details as a plain text file (download)
function exportProjectAsText(p) {
  try {
    const lines = [];
    lines.push(`Project: ${p.name} (${p.id})`);
    lines.push(`Status: ${p.status}`);
    lines.push(`Progress: ${p.progress}%`);
    lines.push(`Owner: ${p.owner || '—'}`);
    lines.push(`Priority: ${p.priority}`);
    lines.push(`Start date: ${p.start_date || '—'}`);
    lines.push(`Target end: ${p.end_date || '—'}`);
    lines.push(`Budget: ${money(p.budget)}`);
    lines.push('');
    lines.push('Description:');
    lines.push(p.description || 'No description provided.');
    lines.push('');
    lines.push('Previous update (notes):');
    lines.push(p.notes || 'No previous update recorded.');

    const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    const safeName = `${p.id} - ${p.name}`.replace(/[\\\/:*?"<>|]/g, '');
    a.download = `${safeName}.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    showToast('Exported project as text.');
  } catch (e) {
    showToast('Failed to export text: ' + (e.message || e), true);
  }
}

// Placeholder: Export to PPT — template integration will be added when the template is provided.
function exportProjectAsPpt(p) {
  showToast('PPT export not yet configured. Please provide the PPT template to integrate.', true);
}

// Export all projects as a single combined text file
function exportAllProjectsAsText() {
  try {
    if (!state.projects || state.projects.length === 0) { showToast('No projects to export.', true); return; }
    const parts = [];
    state.projects.forEach((p, idx) => {
      parts.push(`=== Project ${idx + 1} / ${state.projects.length} ===`);
      parts.push(`Project: ${p.name} (${p.id})`);
      parts.push(`Status: ${p.status}`);
      parts.push(`Progress: ${p.progress}%`);
      parts.push(`Owner: ${p.owner || '—'}`);
      parts.push(`Priority: ${p.priority}`);
      parts.push(`Start date: ${p.start_date || '—'}`);
      parts.push(`Target end: ${p.end_date || '—'}`);
      parts.push(`Budget: ${money(p.budget)}`);
      parts.push('');
      parts.push('Description:');
      parts.push(p.description || 'No description provided.');
      parts.push('');
      parts.push('Previous update (notes):');
      parts.push(p.notes || 'No previous update recorded.');
      parts.push('');
    });

    const blob = new Blob([parts.join('\n')], { type: 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `All Projects - ${new Date().toISOString().slice(0,10)}.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
    showToast('Exported all projects as text.');
  } catch (e) {
    showToast('Failed to export all projects: ' + (e.message || e), true);
  }
}

// Export all projects — generates the fixed 10-slide MANCOM report from the backend
function exportAllProjectsAsPpt() {
  if (!state.projects || state.projects.length === 0) { showToast('No projects to export.', true); return; }
  try {
    const url = `${API_URL}&export=1&format=pptx`;
    const a = document.createElement('a');
    a.href = url;
    a.rel = 'noopener';
    document.body.appendChild(a);
    a.click();
    a.remove();
    showToast('Exporting MANCOM report…');
  } catch (e) {
    showToast('Failed to export PPT: ' + (e.message || e), true);
  }
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
  async list() {
    const r = await fetch(API_URL, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store'
    });

    const text = await r.text();

    if (!r.ok) {
      console.error('PROJECT API ERROR:', r.status, text);
      throw new Error(`Failed to load projects. HTTP ${r.status}: ${text}`);
    }

    try {
      return JSON.parse(text);
    } catch (e) {
      console.error('INVALID JSON FROM PROJECT API:', text);
      throw new Error('Server returned invalid JSON. Check PHP error.');
    }
  },

  async get(id) {
    const r = await fetch(
      `${API_URL}&id=${encodeURIComponent(id)}`,
      {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store'
      }
    );

    const text = await r.text();

    if (!r.ok) {
      console.error('PROJECT GET ERROR:', r.status, text);
      throw new Error(`Failed to load project. HTTP ${r.status}: ${text}`);
    }

    try {
      return JSON.parse(text);
    } catch (e) {
      console.error('INVALID JSON FROM PROJECT:', text);
      throw new Error('Server returned invalid JSON.');
    }
  },

  async create(data) {
    const r = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(data)
    });

    const text = await r.text();

    if (!r.ok) {
      console.error('CREATE ERROR:', r.status, text);
      let error = text;

      try {
        error = JSON.parse(text).error || text;
      } catch (e) {}

      throw new Error(error || 'Failed to create project.');
    }

    return JSON.parse(text);
  },

  async update(id, data) {
    const r = await fetch(
      `${API_URL}&id=${encodeURIComponent(id)}`,
      {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(data)
      }
    );

    const text = await r.text();

    if (!r.ok) {
      console.error('UPDATE ERROR:', r.status, text);
      let error = text;

      try {
        error = JSON.parse(text).error || text;
      } catch (e) {}

      throw new Error(error || 'Failed to update project.');
    }

    return JSON.parse(text);
  },

  async remove(id) {
    const r = await fetch(
      `${API_URL}&id=${encodeURIComponent(id)}`,
      {
        method: 'DELETE',
        credentials: 'same-origin'
      }
    );

    const text = await r.text();

    if (!r.ok) {
      console.error('DELETE ERROR:', r.status, text);
      let error = text;

      try {
        error = JSON.parse(text).error || text;
      } catch (e) {}

      throw new Error(error || 'Failed to delete project.');
    }

    return JSON.parse(text);
  }
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

  document.getElementById('dashboardCount').textContent =
    `${rows.length} total projects`;

  document.getElementById('dashboardTableBody').innerHTML = rows.map((p, i) => `
    <tr class="row-clickable" data-id="${escapeHtml(p.id)}">

      <td class="col-no mono" data-label="No.">
        ${i + 1}
      </td>

      <td class="proj-name" data-label="Project">
        ${escapeHtml(p.name)}
        ${isOverdue(p)
          ? '<span class="overdue-dot" title="Overdue"></span>'
          : ''
        }
        <span class="proj-id mono">${escapeHtml(p.id)}</span>
      </td>

      <td class="col-status" data-label="Status">
        ${badgeHtml(p.status)}
      </td>

      <td class="col-progress" data-label="Progress">
        ${progressBarHtml(p.progress, p.status, true)}
      </td>

      <td class="col-priority" data-label="Priority">
        ${escapeHtml(p.priority || '—')}
      </td>

      <td class="col-start_date" data-label="Start Date">
        ${escapeHtml(p.start_date || '—')}
      </td>

      <td class="col-end_date" data-label="End Date">
        ${escapeHtml(p.end_date || '—')}
      </td>

    </tr>
  `).join('') || `
    <tr>
      <td colspan="7" class="empty-row">
        <i data-lucide="inbox" class="empty-icon"></i>
        No projects yet.
      </td>
    </tr>
  `;

  document
    .querySelectorAll('#dashboardTableBody tr[data-id]')
    .forEach((tr) => {
      tr.addEventListener('click', () => {
        openViewModal(tr.dataset.id);
      });
    });

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
      <td class="col-no mono" data-label="No.">
        ${(state.page - 1) * PAGE_SIZE + i + 1}
      </td>

      <td class="proj-name" data-label="Project">
        ${escapeHtml(p.name)}
        ${isOverdue(p) ? '<span class="overdue-dot" title="Overdue"></span>' : ''}
        <span class="proj-id mono">${p.id}</span>
      </td>

      <td class="col-status" data-label="Status">
        ${badgeHtml(p.status)}
      </td>

      <td class="col-progress" data-label="Progress">
        ${progressBarHtml(p.progress, p.status, true)}
      </td>

      <td data-label="Owner">
        ${escapeHtml(p.owner || '—')}
      </td>

      <td data-label="Priority">
        ${escapeHtml(p.priority || '—')}
      </td>

      <td data-label="Start Date">
        ${escapeHtml(p.start_date || '—')}
      </td>

      <td data-label="End Date">
        ${escapeHtml(p.end_date || '—')}
      </td>

      <td class="col-actions" data-label="Actions">
        ${p.file_link
          ? `<a class="icon-btn" title="Open project files" href="${escapeHtml(p.file_link)}" target="_blank" rel="noopener"><i data-lucide="folder-open"></i></a>`
          : `<span class="icon-btn" title="No upload link set" style="opacity:.3;cursor:default"><i data-lucide="folder-open"></i></span>`}

        <button class="icon-btn btn-view" title="View" data-id="${p.id}">
          <i data-lucide="eye"></i>
        </button>

        <button class="icon-btn btn-edit" title="Edit" data-id="${p.id}">
          <i data-lucide="pencil"></i>
        </button>

        <button class="icon-btn icon-btn-danger btn-delete" title="Delete" data-id="${p.id}">
          <i data-lucide="trash-2"></i>
        </button>
      </td>
    </tr>
  `).join('') || `<tr>
    <td colspan="9" class="empty-row">
      <i data-lucide="search-x" class="empty-icon"></i>
      No projects match your filters.
    </td>
  </tr>`;

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

  // wire up export buttons (if present in the DOM)
  const exportTextBtn = document.getElementById('exportTextBtn');
  if (exportTextBtn) exportTextBtn.onclick = () => exportProjectAsText(p);
  const exportPptBtn = document.getElementById('exportPptBtn');
  if (exportPptBtn) exportPptBtn.onclick = () => exportProjectAsPpt(p);

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
  // clear history UI and show it for create by default
  document.getElementById('historyList').innerHTML = '';
  document.getElementById('historySection').style.display = '';
}

function openCreateModal() {
  editingId = null; resetForm();
  document.getElementById('formModalId').textContent = 'NEW';
  document.getElementById('formModalTitle').textContent = 'New Project';
  document.getElementById('formModalSubmit').textContent = 'Create project';
  openModal('formModalOverlay');
  // ensure the history section is visible to the user and focus its date input
  setTimeout(() => {
    const hs = document.getElementById('historySection');
    if (hs) hs.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    const hd = document.getElementById('hDate'); if (hd) hd.focus();
  }, 120);
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
  document.getElementById('fNotes').value = p.notes || '';

  // render history UI
  const histSection = document.getElementById('historySection');
  const histList = document.getElementById('historyList');
  histList.innerHTML = '';
  if (Array.isArray(p.history) && p.history.length > 0) {
    histSection.style.display = '';
    // sort by date asc
    p.history.sort((a,b) => a.date.localeCompare(b.date));
    p.history.forEach((h) => addHistoryRow(h.date, h.progress, h.notes || ''));
  } else {
    histSection.style.display = '';
  }

  openModal('formModalOverlay');
  // scroll history into view and focus first history field when editing
  setTimeout(() => {
    const hs = document.getElementById('historySection');
    if (hs) hs.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    // focus first row's notes or date if present
    const firstRow = document.querySelector('#historyList .history-row');
    if (firstRow) {
      const noteInput = firstRow.querySelector('input[type="text"]');
      const dateInput = firstRow.querySelector('input[type="date"]');
      (noteInput || dateInput)?.focus();
    }
  }, 120);
}

// history helpers
function addHistoryRow(date, progress, notes) {
  const list = document.getElementById('historyList');
  const row = document.createElement('div');
  row.className = 'history-row';
  row.style = 'display:flex;align-items:center;justify-content:space-between;padding:6px 4px;border-bottom:1px solid #EEF1F4;';
  const left = document.createElement('div');
  left.style = 'display:flex;gap:12px;align-items:center;';
  const d = document.createElement('input'); d.type = 'date'; d.value = date || ''; d.style = 'height:32px;';
  const p = document.createElement('input'); p.type = 'number'; p.min = 0; p.max = 100; p.value = typeof progress !== 'undefined' ? progress : 0; p.style = 'width:84px;height:32px;';
  const n = document.createElement('input'); n.type = 'text'; n.placeholder = 'Notes'; n.value = notes || ''; n.style = 'width:320px;height:32px;';
  left.appendChild(d); left.appendChild(p); left.appendChild(n);
  const right = document.createElement('div');
  const removeBtn = document.createElement('button'); removeBtn.type = 'button'; removeBtn.className = 'btn btn-ghost'; removeBtn.textContent = 'Remove';
  removeBtn.addEventListener('click', () => { row.remove(); });
  right.appendChild(removeBtn);
  row.appendChild(left); row.appendChild(right);
  list.appendChild(row);
}

function collectHistoryFromForm() {
  const rows = Array.from(document.getElementById('historyList').children || []);
  const out = [];
  for (const r of rows) {
    const inputs = r.querySelectorAll('input');
    if (inputs.length >= 3) {
      const date = inputs[0].value;
      const progress = Number(inputs[1].value) || 0;
      const notes = inputs[2].value || '';
      if (date) out.push({ date, progress, notes });
    }
  }
  return out;
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
    notes: document.getElementById('fNotes') ? document.getElementById('fNotes').value.trim() : ''
  };
  // include history for both create and edit if present
  const hist = collectHistoryFromForm();
  if (hist.length > 0) payload.history = hist;

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
  document.getElementById('hAddBtn').addEventListener('click', () => {
    const d = document.getElementById('hDate').value;
    const p = Number(document.getElementById('hProgress').value) || 0;
    const n = (document.getElementById('hNotes') && document.getElementById('hNotes').value) ? document.getElementById('hNotes').value : '';
    if (!d) { showToast('Please pick a date for the history entry.', true); return; }
    addHistoryRow(d, p, n);
    // clear inputs
    document.getElementById('hDate').value = '';
    document.getElementById('hProgress').value = 0;
    if (document.getElementById('hNotes')) document.getElementById('hNotes').value = '';
  });
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

  // wire up export-all buttons in the Projects view header
  const exportAllTextBtn = document.getElementById('exportAllTextBtn');
  if (exportAllTextBtn) exportAllTextBtn.addEventListener('click', () => exportAllProjectsAsText());
  const exportAllPptBtn = document.getElementById('exportAllPptBtn');
  if (exportAllPptBtn) exportAllPptBtn.addEventListener('click', () => exportAllProjectsAsPpt());

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