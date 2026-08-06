/* ITPMS — Manager View: read-only "Employee IT Concerns" panel.
   Talks to manager.php via ?requests_api=1 (GET only — this whole page is
   view-only, same rule as the projects table). Reuses escapeHtml() and the
   badge styling approach already defined in manager.js — load manager.js
   BEFORE this file. */

const MGR_REQ_STATUS_CONFIG = {
  'Open':        { color: '#2F5D8A', soft: '#E8EFF6', icon: 'circle-dot' },
  'In Progress': { color: '#D6A419', soft: '#FBF1DA', icon: 'loader' },
  'Done':        { color: '#2F9E6B', soft: '#E4F5EC', icon: 'check-circle-2' },
};

const MGR_REQ_CATEGORY_CONFIG = {
  'Hardware':       { color: '#8A5A2B', soft: '#F5EBDD', icon: 'hard-drive' },
  'Software':       { color: '#2E7D9A', soft: '#E3F1F5', icon: 'app-window' },
  'Account/Access': { color: '#B5568C', soft: '#FBEAF2', icon: 'key-round' },
  'Network':        { color: '#3F7D4F', soft: '#E7F3EA', icon: 'wifi' },
  'Other':          { color: '#6B7280', soft: '#EEF0F2', icon: 'more-horizontal' },
};

function mgrCategoryBadgeHtml(category) {
  const cfg = MGR_REQ_CATEGORY_CONFIG[category] || MGR_REQ_CATEGORY_CONFIG['Other'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${escapeHtml(category)}</span>`;
}

let mgrRequests = window.INITIAL_MGR_REQUESTS || [];

function mgrFormatDateTime(str) {
  if (!str) return '—';
  const d = new Date(str.replace(' ', 'T'));
  if (isNaN(d.getTime())) return '—';
  return d.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function mgrReqBadgeHtml(status) {
  const cfg = MGR_REQ_STATUS_CONFIG[status] || MGR_REQ_STATUS_CONFIG['Open'];
  return `<span class="badge" style="background:${cfg.soft};color:${cfg.color}"><i data-lucide="${cfg.icon}"></i>${status}</span>`;
}

function renderMgrRequests() {
  document.getElementById('reqCount').textContent = mgrRequests.length;

  document.getElementById('mgrRequestsTableBody').innerHTML = mgrRequests.map((r) => `
    <tr>
      <td>
        <span class="proj-name">${escapeHtml(r.title)}</span>
        ${r.requester ? `<div class="req-requester">by ${escapeHtml(r.requester)}</div>` : ''}
      </td>
      <td>${mgrCategoryBadgeHtml(r.category)}</td>
      <td>${mgrReqBadgeHtml(r.status)}</td>
      <td class="req-date">${mgrFormatDateTime(r.created_at)}</td>
      <td class="req-date">${mgrFormatDateTime(r.resolved_at)}</td>
    </tr>`).join('') || `<tr><td colspan="5" class="empty-row"><i data-lucide="clipboard-check" class="empty-icon"></i>No requests logged yet.</td></tr>`;

  if (window.lucide) lucide.createIcons();
  requestAnimationFrame(fitToScreen); // re-measure since this panel's height affects the overall fit-to-screen scale
}

async function refreshMgrRequests() {
  try {
    const res = await fetch('manager.php?requests_api=1');
    if (!res.ok) return;
    mgrRequests = await res.json();
    renderMgrRequests();
  } catch (e) { /* keep showing last known data if the request fails */ }
}

document.addEventListener('DOMContentLoaded', () => {
  renderMgrRequests();
  setInterval(refreshMgrRequests, 30000); // same cadence as the projects panel
});
