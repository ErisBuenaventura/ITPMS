/* ITPMS — front-end logic. Talks to api/projects.php via fetch()/AJAX. */

const API_URL = 'api/projects.php';

const STATUS_CONFIG = {
  'Completed':   { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2', label: 'Completed' },
  'Ongoing':     { color: '#2F5D8A', soft: '#E8EFF6', icon: 'clock',          label: 'Ongoing' },
  'Onhold':      { color: '#D6A419', soft: '#FBF1DA', icon: 'pause-circle',   label: 'On Hold' },
  'Cancelled':   { color: '#C4483C', soft: '#FBE7E5', icon: 'x-circle',       label: 'Cancelled' },
  'Not Started': { color: '#8A8F98', soft: '#EEEFF1', icon: 'circle',         label: 'Not Started' },
};
const STATUSES = Object.keys(STATUS_CONFIG);

const state = {
  projects: [],
  filterStatus: null,
  view: 'dashboard',
};

let chartInstance = null;
let editingId = null; // null = create mode, otherwise editing this project id

/* ------------------------------------------------------------ helpers */

function icons() { if (window.lucide) lucide.createIcons(); }

function money(n) { return '₱' + Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

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
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}">
    <i data-lucide="${cfg.icon}"></i>${cfg.label}</span>`;
}

function progressBarHtml(value, status, compact) {
  const cfg = STATUS_CONFIG[status] || STATUS_CONFIG['Not Started'];
  const ticks = [10, 20, 30, 40, 50, 60, 70, 80, 90].map(
    (t) => `<span class="pbar-tick" style="left:${t}%"></span>`
  ).join('');
  return `<div class="pbar-wrap${compact ? ' pbar-compact' : ''}">
    <div class="pbar-track">
      <div class="pbar-fill" style="width:${value}%;background:${cfg.color}"></div>
      ${ticks}
    </div>
    <span class="pbar-value mono">${value}%</span>
  </div>`;
}

/* ------------------------------------------------------------ API layer */

const api = {
  async list() {
    const res = await fetch(API_URL);
    if (!res.ok) throw new Error('Failed to load projects.');
    return res.json();
  },
  async get(id) {
    const res = await fetch(`${API_URL}?id=${encodeURIComponent(id)}`);
    if (!res.ok) throw new Error('Failed to load project.');
    return res.json();
  },
  async create(data) {
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    if (!res.ok) throw new Error((await res.json()).error || 'Failed to create project.');
    return res.json();
  },
  async update(id, data) {
    const res = await fetch(`${API_URL}?id=${encodeURIComponent(id)}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    if (!res.ok) throw new Error((await res.json()).error || 'Failed to update project.');
    return res.json();
  },
  async remove(id) {
    const res = await fetch(`${API_URL}?id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (!res.ok) throw new Error((await res.json()).error || 'Failed to delete project.');
    return res.json();
  },
};

/* ------------------------------------------------------------ data load + render orchestration */

async function loadProjects() {
  try {
    state.projects = await api.list();
    renderAll();
  } catch (e) {
    showToast(e.message, true);
  }
}

function renderAll() {
  renderStatGrid();
  renderDashboardTable();
  renderProjectsTable();
}

function renderStatGrid() {
  const counts = {};
  STATUSES.forEach((s) => (counts[s] = 0));
  state.projects.forEach((p) => (counts[p.status] = (counts[p.status] || 0) + 1));

  document.getElementById('statGrid').innerHTML = STATUSES.map((s) => {
    const cfg = STATUS_CONFIG[s];
    const active = state.filterStatus === s ? ' stat-card-active' : '';
    return `<button class="stat-card${active}" style="--stat-color:${cfg.color};--stat-soft:${cfg.soft}" data-status="${s}">
      <div class="stat-icon"><i data-lucide="${cfg.icon}"></i></div>
      <div class="stat-count">${counts[s]}</div>
      <div class="stat-label">${cfg.label}</div>
    </button>`;
  }).join('');

  document.querySelectorAll('#statGrid .stat-card').forEach((btn) => {
    btn.addEventListener('click', () => {
      const s = btn.dataset.status;
      state.filterStatus = state.filterStatus === s ? null : s;
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
      <td class="col-no mono">${i + 1}</td>
      <td class="proj-name">${escapeHtml(p.name)}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status">${badgeHtml(p.status)}</td>
    </tr>`).join('') || `<tr><td colspan="4" class="empty-row">No projects yet.</td></tr>`;

  document.querySelectorAll('#dashboardTableBody tr[data-id]').forEach((tr) => {
    tr.addEventListener('click', () => openViewModal(tr.dataset.id));
  });
  icons();
}

function renderProjectsTable() {
  const rows = state.filterStatus ? state.projects.filter((p) => p.status === state.filterStatus) : state.projects;

  const chip = document.getElementById('chipClear');
  if (state.filterStatus) {
    chip.classList.remove('view-hidden');
    chip.innerHTML = `${STATUS_CONFIG[state.filterStatus].label} <i data-lucide="x"></i>`;
  } else {
    chip.classList.add('view-hidden');
  }

  document.getElementById('projectsCount').textContent = `${rows.length} shown`;
  document.getElementById('projectsTableBody').innerHTML = rows.map((p, i) => `
    <tr data-id="${p.id}">
      <td class="col-no mono">${i + 1}</td>
      <td class="proj-name">${escapeHtml(p.name)}<span class="proj-id mono">${p.id}</span></td>
      <td class="col-progress">${progressBarHtml(p.progress, p.status, true)}</td>
      <td class="col-status">${badgeHtml(p.status)}</td>
      <td class="col-actions">
        <button class="icon-btn btn-view" title="View" data-id="${p.id}"><i data-lucide="eye"></i></button>
        <button class="icon-btn btn-edit" title="Edit" data-id="${p.id}"><i data-lucide="pencil"></i></button>
        <button class="icon-btn icon-btn-danger btn-delete" title="Delete" data-id="${p.id}"><i data-lucide="trash-2"></i></button>
      </td>
    </tr>`).join('') || `<tr><td colspan="5" class="empty-row">No projects in this status yet.</td></tr>`;

  document.querySelectorAll('.btn-view').forEach((b) => b.addEventListener('click', () => openViewModal(b.dataset.id)));
  document.querySelectorAll('.btn-edit').forEach((b) => b.addEventListener('click', () => openEditModal(b.dataset.id)));
  document.querySelectorAll('.btn-delete').forEach((b) => b.addEventListener('click', () => openDeleteConfirm(b.dataset.id)));

  icons();
}

/* ------------------------------------------------------------ view (detail) modal */

async function openViewModal(id) {
  let p;
  try {
    p = await api.get(id);
  } catch (e) {
    showToast(e.message, true);
    return;
  }

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
  document.getElementById('viewModalEditBtn').onclick = () => {
    closeModal('viewModalOverlay');
    openEditModal(p.id);
  };

  const ctx = document.getElementById('progressChart').getContext('2d');
  if (chartInstance) chartInstance.destroy();
  chartInstance = new Chart(ctx, {
    type: 'line',
    data: {
      labels: p.history.map((h) => h.date.slice(5)),
      datasets: [{
        label: 'Progress',
        data: p.history.map((h) => h.progress),
        borderColor: STATUS_CONFIG[p.status].color,
        backgroundColor: STATUS_CONFIG[p.status].color + '22',
        tension: 0.35,
        fill: true,
        pointRadius: 3,
        pointBackgroundColor: STATUS_CONFIG[p.status].color,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: (c) => `${c.parsed.y}%` } },
      },
      scales: {
        y: { min: 0, max: 100, ticks: { font: { size: 10 }, color: '#8A8F98' }, grid: { color: '#E4E7EC' } },
        x: { ticks: { font: { size: 10 }, color: '#8A8F98' }, grid: { display: false } },
      },
    },
  });

  openModal('viewModalOverlay');
}

/* ------------------------------------------------------------ create / edit form modal */

function resetForm() {
  document.getElementById('projectForm').reset();
  document.getElementById('fProgress').value = 0;
  document.getElementById('fProgressLabel').textContent = '0';
  document.getElementById('fStart').value = '';
  document.getElementById('fEnd').value = '';
}

function openCreateModal() {
  editingId = null;
  resetForm();
  document.getElementById('formModalId').textContent = 'NEW';
  document.getElementById('formModalTitle').textContent = 'New Project';
  document.getElementById('formModalSubmit').textContent = 'Create project';
  openModal('formModalOverlay');
}

async function openEditModal(id) {
  let p;
  try {
    p = await api.get(id);
  } catch (e) {
    showToast(e.message, true);
    return;
  }
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
    description: document.getElementById('fDescription').value.trim(),
  };
  if (!payload.name) { showToast('Project name is required.', true); return; }

  try {
    if (editingId) {
      await api.update(editingId, payload);
      showToast('Project updated.');
    } else {
      await api.create(payload);
      showToast('Project created.');
    }
    closeModal('formModalOverlay');
    await loadProjects();
  } catch (e2) {
    showToast(e2.message, true);
  }
}

/* ------------------------------------------------------------ delete confirm */

let pendingDeleteId = null;

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
  } catch (e) {
    showToast(e.message, true);
  }
}

/* ------------------------------------------------------------ modal + view helpers */

function openModal(id) { document.getElementById(id).classList.remove('view-hidden'); icons(); }
function closeModal(id) { document.getElementById(id).classList.add('view-hidden'); }

function switchView(view) {
  state.view = view;
  document.getElementById('view-dashboard').classList.toggle('view-hidden', view !== 'dashboard');
  document.getElementById('view-projects').classList.toggle('view-hidden', view !== 'projects');
  document.querySelectorAll('.nav-item').forEach((btn) => {
    btn.classList.toggle('nav-item-active', btn.dataset.view === view);
  });
  document.getElementById('sidebar').classList.remove('sidebar-open');
}

/* ------------------------------------------------------------ init */

document.addEventListener('DOMContentLoaded', () => {
  icons();
  loadProjects();

  document.querySelectorAll('.nav-item').forEach((btn) => {
    btn.addEventListener('click', () => switchView(btn.dataset.view));
  });

  document.getElementById('btnNewProject').addEventListener('click', openCreateModal);

  document.getElementById('viewModalClose').addEventListener('click', () => closeModal('viewModalOverlay'));
  document.getElementById('viewModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'viewModalOverlay') closeModal('viewModalOverlay'); });

  document.getElementById('formModalClose').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalCancel').addEventListener('click', () => closeModal('formModalOverlay'));
  document.getElementById('formModalOverlay').addEventListener('click', (e) => { if (e.target.id === 'formModalOverlay') closeModal('formModalOverlay'); });
  document.getElementById('projectForm').addEventListener('submit', submitForm);
  document.getElementById('fProgress').addEventListener('input', (e) => {
    document.getElementById('fProgressLabel').textContent = e.target.value;
  });

  document.getElementById('confirmCancel').addEventListener('click', () => { closeModal('confirmOverlay'); pendingDeleteId = null; });
  document.getElementById('confirmDelete').addEventListener('click', confirmDelete);
  document.getElementById('confirmOverlay').addEventListener('click', (e) => { if (e.target.id === 'confirmOverlay') { closeModal('confirmOverlay'); pendingDeleteId = null; } });

  document.getElementById('chipClear').addEventListener('click', () => {
    state.filterStatus = null;
    renderAll();
  });

  document.getElementById('sidebarToggle').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('sidebar-open');
  });
});
