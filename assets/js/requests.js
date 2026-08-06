/* ITPMS — Quick IT Requests logic.
   Talks to index.php via ?requests_api=1 (AJAX/fetch).
   Reuses shared helpers (icons, escapeHtml, showToast, openModal/closeModal,
   switchView, sidebar toggling) already defined in dashboard.js — load
   dashboard.js BEFORE this file. */

const REQUESTS_API_URL = 'index.php?requests_api=1';

const REQ_STATUS_CONFIG = {
  'Open':        { color: '#2F5D8A', soft: '#E8EFF6', icon: 'circle-dot' },
  'In Progress': { color: '#D6A419', soft: '#FBF1DA', icon: 'loader' },
  'Done':        { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2' },
};
const REQ_STATUSES = Object.keys(REQ_STATUS_CONFIG);

const REQ_CATEGORY_CONFIG = {
  'Hardware':       { color: '#8A5A2B', soft: '#F5EBDD', icon: 'hard-drive' },
  'Software':       { color: '#2E7D9A', soft: '#E3F1F5', icon: 'app-window' },
  'Account/Access': { color: '#B5568C', soft: '#FBEAF2', icon: 'key-round' },
  'Network':        { color: '#3F7D4F', soft: '#E7F3EA', icon: 'wifi' },
  'Other':          { color: '#6B7280', soft: '#EEF0F2', icon: 'more-horizontal' },
};

function reqCategoryBadgeHtml(category) {
  const cfg = REQ_CATEGORY_CONFIG[category] || REQ_CATEGORY_CONFIG['Other'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${escapeHtml(category)}</span>`;
}

const reqState = { requests: [] };

function formatDateTime(str) {
  if (!str) return '—';
  const d = new Date(str.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function nowForDatetimeLocal() {
  const d = new Date();
  d.setMinutes(d.getMinutes() - d.getTimezoneOffset()); // shift so toISOString() reflects local time
  return d.toISOString().slice(0, 16); // "YYYY-MM-DDTHH:MM"
}

// Converts a MySQL DATETIME string ("YYYY-MM-DD HH:MM:SS") coming back from the API
// into the "YYYY-MM-DDTHH:MM" format a <input type="datetime-local"> expects.
// Returns '' if there's nothing to show (e.g. resolved_at is null).
function toDatetimeLocalValue(str) {
  if (!str) return '';
  const normalized = str.replace(' ', 'T');
  return normalized.slice(0, 16);
}

function reqBadgeHtml(status) {
  const cfg = REQ_STATUS_CONFIG[status] || REQ_STATUS_CONFIG['Open'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${status}</span>`;
}

const requestsApi = {
  async list() {
    const r = await fetch(REQUESTS_API_URL);
    if (!r.ok) throw new Error('Failed to load requests.');
    return r.json();
  },
  async create(data) {
    const r = await fetch(REQUESTS_API_URL, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to log request.');
    return r.json();
  },
  async update(id, data) {
    const r = await fetch(`${REQUESTS_API_URL}&id=${encodeURIComponent(id)}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to update request.');
    return r.json();
  },
  async setStatus(id, status) {
    // Quick inline status change (no explicit resolved time) — backend defaults
    // resolved_at to now() when flipping to Done this way. Use requestsApi.update()
    // from the edit modal when you need to backfill a specific resolved date/time.
    return requestsApi.update(id, { status });
  },
  async remove(id) {
    const r = await fetch(`${REQUESTS_API_URL}&id=${encodeURIComponent(id)}`, { method: 'DELETE' });
    if (!r.ok) throw new Error((await r.json()).error || 'Failed to delete request.');
    return r.json();
  },
};

async function loadRequests() {
  try {
    reqState.requests = await requestsApi.list();
    renderRequestsTable();
    if (typeof renderStatGrid === 'function') renderStatGrid(); // refresh the dashboard's Open Requests count
  } catch (e) { showToast(e.message, true); }
}

function renderRequestsTable() {
  const rows = reqState.requests;
  document.getElementById('requestsCount').textContent = `${rows.length} logged`;

  document.getElementById('requestsTableBody').innerHTML = rows.map((r, i) => `
    <tr data-id="${r.id}">
      <td class="col-no mono" data-label="No.">${i + 1}</td>
      <td data-label="Request">
        <span class="req-title">${escapeHtml(r.title)}</span>
        ${r.requester ? `<div class="req-requester">by ${escapeHtml(r.requester)}</div>` : ''}
      </td>
      <td data-label="Category">${reqCategoryBadgeHtml(r.category)}</td>
      <td class="col-status" data-label="Status">
        <select class="req-status-select" data-id="${r.id}">
          ${REQ_STATUSES.map((s) => `<option value="${s}" ${s === r.status ? 'selected' : ''}>${s}</option>`).join('')}
        </select>
      </td>
      <td class="req-date" data-label="Issued">${formatDateTime(r.created_at)}</td>
      <td class="req-date" data-label="Resolved">${formatDateTime(r.resolved_at)}</td>
      <td class="col-actions" data-label="Actions">
        <button class="icon-btn req-edit" title="Edit" data-id="${r.id}"><i data-lucide="pencil"></i></button>
        <button class="icon-btn icon-btn-danger req-delete" title="Delete" data-id="${r.id}"><i data-lucide="trash-2"></i></button>
      </td>
    </tr>`).join('') || `<tr><td colspan="7" class="empty-row"><i data-lucide="clipboard-check" class="empty-icon"></i>No requests logged yet.</td></tr>`;

  document.querySelectorAll('.req-status-select').forEach((sel) => {
    sel.addEventListener('change', async () => {
      try { await requestsApi.setStatus(sel.dataset.id, sel.value); showToast('Request updated.'); await loadRequests(); }
      catch (e) { showToast(e.message, true); }
    });
  });
  document.querySelectorAll('.req-edit').forEach((b) => {
    b.addEventListener('click', () => {
      const req = reqState.requests.find((r) => String(r.id) === String(b.dataset.id));
      if (req) openRequestEdit(req);
    });
  });
  document.querySelectorAll('.req-delete').forEach((b) => {
    b.addEventListener('click', async () => {
      if (!confirm('Delete this request? This can\'t be undone.')) return;
      try { await requestsApi.remove(b.dataset.id); showToast('Request deleted.'); await loadRequests(); }
      catch (e) { showToast(e.message, true); }
    });
  });
  icons();
}

/* ---------- Log-new-request form ---------- */

function toggleResolvedField(statusSelectEl, resolvedWrapEl) {
  resolvedWrapEl.classList.toggle('view-hidden', statusSelectEl.value !== 'Done');
}

async function submitRequestForm(e) {
  e.preventDefault();
  const statusEl = document.getElementById('rStatus');
  const payload = {
    title: document.getElementById('rTitle').value.trim(),
    requester: document.getElementById('rRequester').value.trim(),
    category: document.getElementById('rCategory').value,
    issued: document.getElementById('rIssued').value,
    status: statusEl.value,
    resolved: statusEl.value === 'Done' ? document.getElementById('rResolved').value : '',
    notes: document.getElementById('rNotes').value.trim(),
  };
  if (!payload.title) { showToast('Please describe the request.', true); return; }
  try {
    await requestsApi.create(payload);
    showToast('Request logged.');
    document.getElementById('requestForm').reset();
    document.getElementById('rIssued').value = nowForDatetimeLocal();
    toggleResolvedField(statusEl, document.getElementById('rResolvedWrap'));
    await loadRequests();
  } catch (e2) { showToast(e2.message, true); }
}

/* ---------- Edit-request modal ---------- */

function openRequestEdit(req) {
  document.getElementById('reId').value = req.id;
  document.getElementById('reTitle').value = req.title || '';
  document.getElementById('reRequester').value = req.requester || '';
  document.getElementById('reCategory').value = req.category || 'Other';
  document.getElementById('reIssued').value = toDatetimeLocalValue(req.created_at);
  document.getElementById('reStatus').value = req.status || 'Open';
  document.getElementById('reResolved').value = toDatetimeLocalValue(req.resolved_at);
  document.getElementById('reNotes').value = req.notes || '';
  toggleResolvedField(document.getElementById('reStatus'), document.getElementById('reResolvedWrap'));
  openModal('requestEditOverlay');
}

async function submitRequestEditForm(e) {
  e.preventDefault();
  const id = document.getElementById('reId').value;
  const statusEl = document.getElementById('reStatus');
  const payload = {
    title: document.getElementById('reTitle').value.trim(),
    requester: document.getElementById('reRequester').value.trim(),
    category: document.getElementById('reCategory').value,
    issued: document.getElementById('reIssued').value,
    status: statusEl.value,
    resolved: statusEl.value === 'Done' ? document.getElementById('reResolved').value : '',
    notes: document.getElementById('reNotes').value.trim(),
  };
  if (!payload.title) { showToast('Please describe the request.', true); return; }
  try {
    await requestsApi.update(id, payload);
    showToast('Request updated.');
    closeModal('requestEditOverlay');
    await loadRequests();
  } catch (e2) { showToast(e2.message, true); }
}

document.addEventListener('DOMContentLoaded', () => {
  loadRequests();
  document.getElementById('rIssued').value = nowForDatetimeLocal();

  document.getElementById('requestForm').addEventListener('submit', submitRequestForm);

  const rStatus = document.getElementById('rStatus');
  const rResolvedWrap = document.getElementById('rResolvedWrap');
  toggleResolvedField(rStatus, rResolvedWrap);
  rStatus.addEventListener('change', () => toggleResolvedField(rStatus, rResolvedWrap));

  document.getElementById('requestEditForm').addEventListener('submit', submitRequestEditForm);
  const reStatus = document.getElementById('reStatus');
  const reResolvedWrap = document.getElementById('reResolvedWrap');
  reStatus.addEventListener('change', () => toggleResolvedField(reStatus, reResolvedWrap));
  document.getElementById('requestEditClose').addEventListener('click', () => closeModal('requestEditOverlay'));
  document.getElementById('requestEditCancel').addEventListener('click', () => closeModal('requestEditOverlay'));
});