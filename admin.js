// Keep admin login scope in the URL only.
// This is important for testing manager + regional side by side: duplicated
// tabs can clone sessionStorage, but they cannot silently change each other's
// URL. Use:
//   admin.html?admin_scope=manager
//   admin.html?admin_scope=regional
const ADMIN_SCOPE_KEY = 'cinemaxAdminLoginScope';

function normalizeAdminScope(scope) {
  return scope === 'regional' ? 'regional' : 'manager';
}

function adminScopeFromUrl() {
  const scope = new URLSearchParams(window.location.search).get('admin_scope');
  return scope === 'regional' || scope === 'manager' ? scope : '';
}

function writeAdminScope(scope) {
  const normalized = normalizeAdminScope(scope);
  try { sessionStorage.removeItem(ADMIN_SCOPE_KEY); } catch (e) {}

  const url = new URL(window.location.href);
  if (url.searchParams.get('admin_scope') !== normalized) {
    url.searchParams.set('admin_scope', normalized);
    window.history.replaceState(null, '', url);
  }
  return normalized;
}

const initialAdminScope = adminScopeFromUrl() || 'manager';
const adminState = { csrfToken: null, user: null, cinemas: [], loginScope: writeAdminScope(initialAdminScope) };
let adminLiveRefreshTimer = null;
let adminLiveRefreshRunning = false;

function adminCtx() {
  return adminState.loginScope === 'regional' ? 'regional_admin' : 'manager_admin';
}

function adminScopeTitle() {
  return adminState.loginScope === 'regional' ? 'Regional Admin / Staff' : 'Manager Admin';
}

function setAdminLoginScope(scope) {
  adminState.loginScope = writeAdminScope(scope);
  adminState.csrfToken = null;
  adminState.user = null;
  showAdminLogin();
  api('csrf_token').then(res => { if (res.status === 'success') adminState.csrfToken = res.csrf_token; }).catch(() => {});
}

async function api(action, params = {}, method = 'GET') {
  // Admin login is isolated into two separate cookies:
  // manager_admin for full manager accounts and regional_admin for regional/staff accounts.
  let url = `api.php?action=${encodeURIComponent(action)}&ctx=${encodeURIComponent(adminCtx())}`;
  const opts = {};
  if (method === 'GET') {
    const qs = new URLSearchParams(params).toString();
    if (qs) url += '&' + qs;
  } else {
    opts.method = method;
    opts.headers = { 'Content-Type': 'application/json' };
    opts.body = JSON.stringify({ action, ...params, admin_login_scope: adminState.loginScope, csrf_token: adminState.csrfToken });
  }
  const res = await fetch(url, opts);
  return res.json();
}

function money(v) { return `${parseFloat(v || 0).toFixed(0)} EGP`; }
function esc(s) { return String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
function isFullAdmin() { return adminState.user?.role === 'admin'; }
/** Manager sees all tabs as a fallback; regional/staff hide manager-only tabs. */
function applyRoleVisibility() {
  const fullAdmin = isFullAdmin();
  document.querySelectorAll('.full-admin-only').forEach(el => el.classList.toggle('hidden', !fullAdmin));
  document.querySelectorAll('.regional-only').forEach(el => el.classList.remove('hidden'));

  // If a tab is hidden or was removed in a newer version, send the admin back to Dashboard.
  const activeTab = document.querySelector('.tab.active');
  if (activeTab && activeTab.classList.contains('hidden')) {
    switchTab('dashboard');
  }
}
function badge(status) {
  const cls = ['paid', 'success', 'valid', 'closed', 'active'].includes(status) ? 'badge-paid' : (status === 'pending' ? 'badge-pending' : 'badge-bad');
  return `<span class="badge ${cls}">${esc(status || '-')}</span>`;
}

function updateAdminHeaderScope() {
  const subtitle = document.querySelector('header p');
  if (!subtitle || !adminState.user) return;

  const regions = (adminState.user.governorate_names || []).join(', ');
  subtitle.textContent = adminState.user.role === 'regional_admin'
    ? `Regional admin for ${regions || adminState.user.governorate_name || 'assigned region'}`
    : (adminState.user.role === 'admin'
        ? 'Manager: revenue, losses, regional-admin management, and fallback operations.'
        : 'Payment verification, refunds, cinema operations, and customer support.');
}

function regionPopupPendingKey(user = adminState.user) {
  return user?.id ? `cinemaxRegionalTransferPopupPending:${user.id}` : '';
}

function savePendingRegionTransfer(previousRegion, newRegion, signature) {
  const key = regionPopupPendingKey();
  if (!key) return;
  localStorage.setItem(key, JSON.stringify({
    previousRegion: previousRegion || 'your previous region assignment',
    newRegion: newRegion || 'your new assigned region',
    signature: signature || '',
    createdAt: new Date().toISOString(),
  }));
}

function getPendingRegionTransfer(user = adminState.user) {
  const key = regionPopupPendingKey(user);
  if (!key) return null;
  try {
    return JSON.parse(localStorage.getItem(key) || 'null');
  } catch (e) {
    return null;
  }
}

async function clearPendingRegionTransfer(notificationId = null) {
  const key = regionPopupPendingKey();
  if (key) localStorage.removeItem(key);
  const modal = document.getElementById('region-transfer-modal');
  const id = notificationId || Number(modal?.dataset.notificationId || 0);
  modal?.remove();
  if (id) {
    try { await api('admin_dismiss_region_transfer_notice', { notification_id: id }, 'POST'); } catch (e) {}
  }
}

function showRegionTransferPopup(previousRegion, newRegion, options = {}) {
  const existing = document.getElementById('region-transfer-modal');
  if (existing && !options.replace) return;
  existing?.remove();
  const modal = document.createElement('div');
  modal.id = 'region-transfer-modal';
  modal.dataset.notificationId = options.notificationId ? String(options.notificationId) : '';
  modal.className = 'admin-modal-backdrop';
  modal.innerHTML = `
    <div class="admin-modal-card">
      <div class="text-xs font-black uppercase tracking-[0.24em] text-rose-300 mb-3">Region Updated</div>
      <h2 class="text-2xl font-black mb-3">You were moved to ${esc(newRegion)}</h2>
      <p class="text-sm text-gray-300 leading-relaxed">
        The manager admin changed your regional assignment${previousRegion ? ` from <span class="font-bold text-white">${esc(previousRegion)}</span>` : ''} to
        <span class="font-bold text-white">${esc(newRegion)}</span>. The admin dashboard has refreshed and will now show payments,
        refund requests, support tickets, reservations, and payment operations for the new region.
      </p>
      <p class="text-xs text-amber-200 mt-4">This message will stay visible until you close it.</p>
      <button class="btn mt-6 w-full" type="button" onclick="clearPendingRegionTransfer()">I Understand</button>
    </div>`;
  document.body.appendChild(modal);
}

async function syncAdminSession(options = {}) {
  const previousUser = adminState.user;
  const session = await api('session');

  const role = session.user?.role;
  const expectedManager = adminState.loginScope === 'manager';
  const roleAllowed = expectedManager ? role === 'admin' : ['regional_admin', 'staff'].includes(role);
  if (session.status !== 'success' || !roleAllowed) {
    adminState.user = null;
    showAdminLogin(session.status === 'success'
      ? (expectedManager ? 'This is the Manager login. Select Regional / Staff for regional accounts.' : 'This is the Regional / Staff login. Select Manager Admin for manager accounts.')
      : `Your ${adminScopeTitle()} session expired. Please sign in again.`);
    throw new Error('Admin session is no longer valid for this isolated login.');
  }

  const nextUser = session.user;
  const regionChanged = Boolean(options.showRegionChange);
  adminState.user = nextUser;
  showAdminApp();
  updateAdminHeaderScope();
  applyRoleVisibility();

  const activeTab = document.querySelector('.tab.active')?.dataset.tab || 'dashboard';
  if (['manager', 'sync'].includes(activeTab) && !isFullAdmin()) switchTab('dashboard');

  checkStoredRegionChange(previousUser, nextUser, regionChanged);

  return nextUser;
}

function showAdminLogin(message = '') {
  if (adminLiveRefreshTimer) clearInterval(adminLiveRefreshTimer);
  adminLiveRefreshTimer = null;
  adminLiveRefreshRunning = false;
  document.querySelector('nav')?.classList.add('hidden');
  document.querySelector('main')?.classList.add('hidden');
  document.querySelectorAll('.full-admin-only').forEach(el => el.classList.add('hidden'));
  document.getElementById('admin-refresh-btn')?.setAttribute('disabled', 'disabled');
  document.getElementById('admin-logout-btn')?.classList.add('hidden');
  const status = document.getElementById('admin-refresh-status');
  if (status) status.textContent = 'Not signed in';

  const warning = document.getElementById('access-warning');
  // Use a neutral sign-in card (not the red "error" styling) so the admin
  // page shows a normal, self-contained login form. No need to sign in from
  // the main website first.
  warning.className = 'card mb-6 max-w-md';
  warning.classList.remove('hidden');
  const isRegional = adminState.loginScope === 'regional';
  warning.innerHTML = `
    <h2 class="font-black text-xl mb-2">${esc(adminScopeTitle())} Sign In</h2>
    <p class="text-sm text-gray-400 mb-4">
      Choose the correct isolated login. Manager accounts and regional/staff accounts use separate sessions, so one login will not overwrite the other.
    </p>
    <div class="grid grid-cols-2 gap-2 mb-4">
      <button id="manager-login-choice" class="${isRegional ? 'btn-muted' : 'btn'}" type="button">Manager Admin</button>
      <button id="regional-login-choice" class="${isRegional ? 'btn' : 'btn-muted'}" type="button">Regional / Staff</button>
    </div>
    <p class="text-xs text-gray-500 mb-4">
      ${isRegional
        ? 'Use this for regional admin or staff accounts only. Manager accounts are rejected here.'
        : 'Use this for manager/full-admin accounts only. Regional and staff accounts are rejected here.'}
    </p>
    <div id="admin-login-msg" class="${message ? '' : 'hidden'} mb-4 rounded-xl border border-red-500/30 bg-red-500/10 p-3 text-sm text-red-200">${message ? esc(message) : ''}</div>
    <form id="admin-login-form" class="grid grid-cols-1 gap-3">
      <input id="admin-login-email" class="input" type="email" placeholder="${isRegional ? 'Regional/staff email' : 'Manager email'}" autocomplete="username" autofocus />
      <input id="admin-login-password" class="input" type="password" placeholder="Password" autocomplete="current-password" />
      <button id="admin-login-submit" class="btn" type="submit">Sign In as ${esc(adminScopeTitle())}</button>
    </form>`;
  // Bind the handler in JS (more reliable than an inline onsubmit attribute).
  document.getElementById('manager-login-choice')?.addEventListener('click', () => setAdminLoginScope('manager'));
  document.getElementById('regional-login-choice')?.addEventListener('click', () => setAdminLoginScope('regional'));
  document.getElementById('admin-login-form')?.addEventListener('submit', submitAdminLogin);
  // Some browsers ignore the autofocus attribute on innerHTML-injected nodes.
  document.getElementById('admin-login-email')?.focus();
}

function setAdminLoginMessage(text) {
  const msg = document.getElementById('admin-login-msg');
  if (!msg) { showAdminLogin(text); return; }
  msg.textContent = text;
  msg.classList.remove('hidden');
}

function showAdminApp() {
  document.getElementById('access-warning')?.classList.add('hidden');
  document.querySelector('nav')?.classList.remove('hidden');
  document.querySelector('main')?.classList.remove('hidden');
  applyRoleVisibility();
  document.getElementById('admin-refresh-btn')?.removeAttribute('disabled');
  document.getElementById('admin-logout-btn')?.classList.remove('hidden');
}

async function submitAdminLogin(event) {
  if (event) event.preventDefault();
  const email = document.getElementById('admin-login-email')?.value.trim() || '';
  const password = document.getElementById('admin-login-password')?.value || '';
  const btn = document.getElementById('admin-login-submit');

  if (!email || !password) {
    setAdminLoginMessage('Email and password are required.');
    return;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    setAdminLoginMessage('Please enter a valid email address.');
    return;
  }

  if (btn) { btn.disabled = true; btn.textContent = 'Signing in…'; }
  const resetBtn = () => { if (btn) { btn.disabled = false; btn.textContent = 'Sign In'; } };

  try {
    const res = await api('login', { email, password, admin_login_scope: adminState.loginScope }, 'POST');
    if (!res || res.status !== 'success') {
      setAdminLoginMessage((res && res.message) || 'Login failed.');
      resetBtn();
      return;
    }
    const expectedManager = adminState.loginScope === 'manager';
    const role = res.user.role;
    const allowed = expectedManager ? role === 'admin' : ['regional_admin', 'staff'].includes(role);
    if (!allowed) {
      await api('logout', {}, 'POST').catch(() => {});
      setAdminLoginMessage(expectedManager
        ? 'This login is for manager accounts only. Select Regional / Staff for regional admin accounts.'
        : 'This login is for regional/staff accounts only. Select Manager Admin for manager accounts.');
      resetBtn();
      return;
    }
    const csrf = await api('csrf_token');
    if (csrf.status === 'success') adminState.csrfToken = csrf.csrf_token;
    writeAdminScope(adminState.loginScope);
    location.reload();
  } catch (err) {
    console.error('Admin login error:', err);
    setAdminLoginMessage('Could not reach the server. Open the admin page through your PHP server (e.g. http://localhost/.../admin.html), not as a local file, and make sure Apache/MySQL are running.');
    resetBtn();
  }
}

async function adminLogout() {
  if (adminLiveRefreshTimer) clearInterval(adminLiveRefreshTimer);
  adminLiveRefreshTimer = null;
  try {
    const csrf = await api('csrf_token');
    if (csrf.status === 'success') adminState.csrfToken = csrf.csrf_token;
  } catch (e) {}
  await api('logout', {}, 'POST').catch(() => {});
  adminState.user = null;
  adminState.csrfToken = null;
  location.reload();
}

async function initAdmin() {
  const csrf = await api('csrf_token');
  if (csrf.status === 'success') adminState.csrfToken = csrf.csrf_token;

  try {
    await syncAdminSession();
  } catch (error) {
    return;
  }

  if (!isFullAdmin()) {
    document.querySelectorAll('.full-admin-only').forEach(el => el.classList.add('hidden'));
  }

  document.getElementById('admin-refresh-btn')?.addEventListener('click', () => refreshAdminData());
  document.getElementById('admin-logout-btn')?.addEventListener('click', adminLogout);
  document.querySelectorAll('.tab').forEach(btn => btn.addEventListener('click', () => switchTab(btn.dataset.tab)));

  await loadAdminDataForRole();
  updateRefreshStatus('Loaded');
  // Refresh is now MANUAL (the Refresh Data button). A lightweight watcher only
  // wakes up to refresh when the manager pushes an update (region/role/status).
  rememberAdminSignature();
  startAdminUpdateWatcher();
}

/** Load only the data the current role actually uses. */
async function loadAdminDataForRole() {
  if (isFullAdmin()) {
    // Manager: full fallback access across all regions.
    await Promise.all([
      loadDashboard(), loadSync(), loadRegionalAdmins(), loadForgotPasswordRequests(),
      loadCinemaReservations(), loadPayments(), loadRefundRequests(), loadSupport(),
    ]);
  } else {
    await Promise.all([
      loadDashboard(), loadCinemaReservations(),
      loadPayments(), loadRefundRequests(), loadSupport(),
    ]);
  }
}

/** A short fingerprint of "what the manager controls" for this account. */
function adminSignature(user) {
  if (!user) return '';
  const regions = (user.governorate_ids || []).slice().sort((a, b) => a - b).join(',');
  return `${user.role}|${user.is_blocked ? 1 : 0}|${regions}`;
}

function regionSignature(user) {
  if (!user) return '';
  return (user.governorate_ids || []).slice().sort((a, b) => a - b).join(',');
}

function regionNamesText(user) {
  return (user?.governorate_names || []).join(', ') || user?.governorate_name || 'assigned region';
}

function regionStorageKey(user) {
  return user?.id ? `cinemaxRegionalRegionSignature:${user.id}` : '';
}

function checkStoredRegionChange(previousUser, nextUser, forcePopup = false) {
  if (!nextUser || !['regional_admin', 'staff'].includes(nextUser.role)) return;

  const currentSig = regionSignature(nextUser);
  const key = regionStorageKey(nextUser);
  const storedSig = key ? localStorage.getItem(key) : '';

  const previousSig = previousUser && previousUser.id === nextUser.id
    ? regionSignature(previousUser)
    : storedSig;

  const hasChanged = Boolean(previousSig && currentSig && previousSig !== currentSig);

  if (key) localStorage.setItem(key, currentSig);

  if (hasChanged && forcePopup) {
    const previousRegion = previousUser && previousUser.id === nextUser.id
      ? regionNamesText(previousUser)
      : 'your previous region assignment';
    const nextRegion = regionNamesText(nextUser);
    savePendingRegionTransfer(previousRegion, nextRegion, currentSig);
    showRegionTransferPopup(previousRegion, nextRegion, { replace: true });
    return;
  }

  // If the regional admin did not close the transfer message yet, keep it visible
  // even after data refreshes or page reloads in this browser.
  const pending = getPendingRegionTransfer(nextUser);
  if (pending && (!pending.signature || pending.signature === currentSig)) {
    showRegionTransferPopup(pending.previousRegion, pending.newRegion);
  }
}

async function checkServerRegionTransferNotice() {
  if (!adminState.user || !['regional_admin', 'staff'].includes(adminState.user.role)) return;
  try {
    const res = await api('admin_region_transfer_notice');
    if (res.status !== 'success' || !res.notice) return;
    const n = res.notice;
    const newRegion = n.new_region || regionNamesText(adminState.user);
    const previousRegion = n.previous_region || 'your previous region assignment';
    showRegionTransferPopup(previousRegion, newRegion, { replace: true, notificationId: n.id });
  } catch (e) {
    // Notification polling must not break the dashboard.
  }
}

function rememberAdminSignature() {
  adminState.lastSignature = adminSignature(adminState.user);
  checkStoredRegionChange(null, adminState.user, false);
  checkServerRegionTransferNotice();
}

function switchTab(name) {
  const requestedTab = document.querySelector(`.tab[data-tab="${name}"]`);
  if (!requestedTab || requestedTab.classList.contains('hidden')) name = 'dashboard';
  document.querySelectorAll('.tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
  document.getElementById(`tab-${name}`)?.classList.remove('hidden');
}

function updateRefreshStatus(prefix = 'Updated') {
  const status = document.getElementById('admin-refresh-status');
  if (!status) return;
  const time = new Date().toLocaleTimeString('en-EG', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });
  status.textContent = `${prefix} ${time}`;
}

/**
 * No automatic data refresh. This only checks the session periodically and
 * refreshes when the manager has changed this account (region set, role, or
 * active/blocked status) — i.e. "an update from the admin".
 */
function startAdminUpdateWatcher() {
  if (adminLiveRefreshTimer) clearInterval(adminLiveRefreshTimer);
  adminLiveRefreshTimer = setInterval(async () => {
    if (!adminState.user || adminLiveRefreshRunning) return;
    adminLiveRefreshRunning = true;
    try {
      const session = await api('session');
      if (session.status !== 'success' || !session.user) return;
      const newSig = adminSignature(session.user);
      if (newSig !== adminState.lastSignature) {
        // The manager pushed an update -> refresh now and notify.
        await refreshAdminData({ showRegionChange: true });
        rememberAdminSignature();
      } else {
        await checkServerRegionTransferNotice();
      }
    } catch (e) {
      // Watcher failures are non-fatal; manual Refresh still works.
    } finally {
      adminLiveRefreshRunning = false;
    }
  }, 15000);
}

async function refreshAdminData(options = {}) {
  if (!adminState.user) return;
  const showRegionChange = options?.showRegionChange === true;

  const btn = document.getElementById('admin-refresh-btn');
  const status = document.getElementById('admin-refresh-status');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Refreshing...';
  }
  if (status) status.textContent = 'Refreshing...';

  try {
    await syncAdminSession({ showRegionChange });
    await loadAdminDataForRole();

    const activeTab = document.querySelector('.tab.active')?.dataset.tab || 'dashboard';
    if (activeTab === 'manager' && isFullAdmin()) await loadRegionalAdmins();
    if (activeTab === 'forgot' && isFullAdmin()) await loadForgotPasswordRequests();

    rememberAdminSignature();
    updateRefreshStatus('Updated');
  } catch (error) {
    console.error('Admin refresh failed:', error);
    if (status) status.textContent = 'Refresh failed';
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Refresh Data';
    }
  }
}

async function loadDashboard() {
  const box = document.getElementById('tab-dashboard');
  box.innerHTML = '<div class="card text-gray-400">Loading dashboard…</div>';
  const res = await api('admin_dashboard_stats');
  if (res.status !== 'success') { box.innerHTML = `<div class="card text-red-300">${esc(res.message)}</div>`; return; }
  const s = res.stats;
  const net = Number(s.net_result ?? (Number(s.total_revenue || 0) - Number(s.total_losses || 0)));
  const netColor = net >= 0 ? 'text-green-400' : 'text-red-400';
  box.innerHTML = `
    <div class="card mb-4"><div class="text-gray-400 text-xs uppercase font-black tracking-widest">Dashboard Scope</div><div class="text-xl font-black mt-1">${esc(s.scope_name || 'All regions')}</div></div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-4">
      <div class="card"><div class="text-gray-400 text-xs uppercase font-black tracking-widest">Revenue</div><div class="text-3xl font-black mt-2 text-green-400">${esc(money(s.total_revenue))}</div></div>
      <div class="card"><div class="text-gray-400 text-xs uppercase font-black tracking-widest">Losses (refunds &amp; cancellations)</div><div class="text-3xl font-black mt-2 text-red-400">${esc(money(s.total_losses))}</div><div class="text-xs text-gray-500 mt-1">${Number(s.refunded_seats || 0)} refunded seat(s)</div></div>
      <div class="card"><div class="text-gray-400 text-xs uppercase font-black tracking-widest">Net Result</div><div class="text-3xl font-black mt-2 ${netColor}">${esc(money(net))}</div></div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      ${stat('Users', s.total_users)}
      ${stat('Bookings', s.total_bookings)}
      ${stat('Today', s.today_bookings)}
      ${stat('Pending Payments', s.pending_payments)}
      ${stat('Paid Payments', s.paid_payments)}
      ${stat('Cancelled', s.cancelled_bookings)}
      ${stat('Last Sync', s.last_sync ? (s.last_sync.synced_at || s.last_sync.created_at) : 'Not synced yet')}
    </div>`;
}
function stat(label, value) { return `<div class="card"><div class="text-gray-400 text-xs uppercase font-black tracking-widest">${label}</div><div class="text-3xl font-black mt-2">${esc(value)}</div></div>`; }

async function loadCinemaReservations() {
  const box = document.getElementById('tab-cinemas');
  if (!box) return;
  box.innerHTML = '<div class="card text-gray-400">Loading cinema reservations...</div>';

  const res = await api('admin_cinema_reservations');
  if (res.status !== 'success') {
    box.innerHTML = `<div class="card text-red-300">${esc(res.message)}</div>`;
    return;
  }

  const cinemas = res.cinemas || [];
  box.innerHTML = `
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-black">Cinema Reservations</h2>
        <p class="text-sm text-gray-400">Grouped by cinema with showtimes, users, seats, and awaiting approval indicators.</p>
      </div>
      <button class="btn-muted" onclick="loadCinemaReservations()">Refresh</button>
    </div>
    <div class="cinema-monitor-grid">
      ${cinemas.map(renderCinemaReservationCard).join('') || '<div class="card text-gray-400">No cinemas found.</div>'}
    </div>`;
}

function renderCinemaReservationCard(cinema) {
  const reservations = cinema.reservations || [];
  const pendingPayments = Number(cinema.pending_payment_count || 0);
  const pendingRefunds = Number(cinema.pending_refund_count || 0);
  const hasWaiting = pendingPayments + pendingRefunds > 0;

  return `
    <article class="card cinema-monitor-card ${hasWaiting ? 'cinema-waiting' : ''}">
      <div class="flex items-start justify-between gap-3 mb-4">
        <div>
          <div class="flex items-center gap-2">
            ${hasWaiting ? '<span class="live-dot" title="Awaiting admin approval"></span>' : ''}
            <h3 class="font-black text-lg">${esc(cinema.name)}</h3>
          </div>
          <p class="text-xs text-gray-400 mt-1">${esc(cinema.governorate_name || '')}${cinema.location ? ` - ${esc(cinema.location)}` : ''}</p>
        </div>
        <div class="text-right text-xs text-gray-400">
          <div><b class="text-white">${Number(cinema.reservation_count || 0)}</b> reservations</div>
          <div><b class="text-yellow-300">${pendingPayments}</b> payments</div>
          <div><b class="text-red-300">${pendingRefunds}</b> refunds</div>
        </div>
      </div>
      <div class="cinema-reservation-list">
        ${reservations.map(r => `
          <div class="cinema-reservation-row">
            <div>
              <div class="font-black">${esc(r.movie_title)}</div>
              <div class="text-xs text-gray-400">${esc(r.show_datetime)} - ${esc(r.hall_name || '')}</div>
              <div class="text-xs text-gray-500">${esc(r.username || 'User')} - ${esc(r.seats || 'No active seats')}</div>
            </div>
            <div class="text-right">
              ${badge(r.payment_status)}
              <div class="text-xs text-gray-500 mt-1">#${esc(r.id)}</div>
            </div>
          </div>
        `).join('') || '<div class="text-sm text-gray-500">No active reservations for this cinema.</div>'}
      </div>
    </article>`;
}

async function loadSync() {
  const box = document.getElementById('tab-sync');
  const res = await api('admin_sync_logs');
  const logs = res.logs || [];
  box.innerHTML = `
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-black">TMDB Sync Verification</h2>
        <p class="text-sm text-gray-400">Run and review movie/showtime update logs.</p>
        <p id="sync-run-status" class="text-xs text-gray-500 mt-2">Click the button to start the sync. The table will update after the process finishes.</p>
      </div>
      <button class="btn" type="button" id="run-sync-btn" data-run-sync>Run TMDB Sync Now</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>Synced At</th><th>Added</th><th>Updated</th><th>Errors</th><th>Note</th></tr></thead>
      <tbody>${logs.map(l => `<tr><td>${l.id}</td><td>${esc(l.synced_at || l.created_at)}</td><td>${l.added ?? '-'}</td><td>${l.updated ?? '-'}</td><td>${l.errors ?? '-'}</td><td>${esc(l.note || l.message || '')}</td></tr>`).join('') || '<tr><td colspan="6">No sync logs yet.</td></tr>'}</tbody>
    </table></div>`;
}

async function runSync() {
  const btn = document.getElementById('run-sync-btn');
  const status = document.getElementById('sync-run-status');
  if (btn) {
    btn.disabled = true;
    btn.textContent = 'Starting sync...';
  }
  if (status) status.textContent = 'Starting TMDB sync. Please wait...';
  try {
    const res = await api('admin_trigger_sync', {}, 'POST');
    if (res.status !== 'success') throw new Error(res.message || 'Sync failed to start.');
    if (status) status.textContent = res.message || 'Sync started. Refreshing logs shortly...';
    // The sync runs in a separate PHP process, so give it time to write sync_log.
    setTimeout(loadSync, 3000);
    setTimeout(loadSync, 12000);
    setTimeout(loadSync, 30000);
  } catch (err) {
    if (status) status.textContent = err.message || 'Could not start TMDB sync.';
    alert(err.message || 'Could not start TMDB sync.');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Run TMDB Sync Now';
    }
  }
}

// Keep the function available for old cached HTML/buttons, and also use delegated events
// so the button always works even after the tab is rebuilt dynamically.
window.runSync = runSync;
document.addEventListener('click', (event) => {
  const btn = event.target.closest('[data-run-sync]');
  if (!btn) return;
  event.preventDefault();
  runSync();
});

async function loadPayments() {
  const box = document.getElementById('tab-payments');
  box.innerHTML = '<div class="card text-gray-400">Loading payments...</div>';
  const res = await api('admin_payments');
  if (res.status !== 'success') { box.innerHTML = `<div class="card text-red-300">${esc(res.message)}</div>`; return; }
  const rows = res.payments || [];
  box.innerHTML = `
    <div class="card mb-4"><h2 class="text-xl font-black">Payment Verification</h2><p class="text-sm text-gray-400">Verify pending cash payments, refund paid online payments, or cancel reservations for cinema maintenance.</p></div>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>User</th><th>Movie</th><th>Cinema</th><th>Seats</th><th>Total</th><th>Payment</th><th>Ticket</th><th>Actions</th></tr></thead>
      <tbody>${rows.map(r => {
        const isPending = r.payment_status === 'pending';
        const isPaid = r.payment_status === 'paid';
        const hasActiveSeats = (r.seats || []).length > 0;
        const canModify = r.status === 'confirmed' && r.ticket_status !== 'cancelled' && hasActiveSeats;
        let actions = '<span class="text-gray-500 text-sm">No action needed</span>';
        if (canModify) {
          const maintenanceButton = `<button class="btn-muted" onclick="adminCancelReservationForMaintenance(${r.id})">Cancel Reservation</button>`;
          if (isPending) {
            actions = `
              <button class="btn-muted" onclick="paymentAction('admin_verify_payment', ${r.id})">Verify</button>
              <button class="btn-muted" onclick="paymentAction('admin_reject_payment', ${r.id})">Reject</button>
              ${maintenanceButton}
            `;
          } else if (isPaid) {
            const refundTools = r.payment_method === 'cash'
              ? '<span class="text-yellow-300 text-xs font-bold">Cash refund handled manually</span>'
              : `
                <button class="btn-muted" onclick="selectAllAdminRefundSeats(${r.id})">Select All Seats</button>
                <button class="btn-muted" onclick="refundSelectedAdminSeats(${r.id})">Refund Selected Seats</button>
                <button class="btn-muted" onclick="paymentAction('admin_refund_payment', ${r.id})">Refund Full</button>
              `;
            actions = `${refundTools}${maintenanceButton}`;
          }
        } else if (r.status === 'cancelled' || r.ticket_status === 'cancelled') {
          actions = '<span class="text-gray-500 text-sm">Cancelled</span>';
        } else if (r.payment_status && !['pending', 'paid'].includes(r.payment_status)) {
          actions = `<span class="text-gray-500 text-sm">${esc(r.payment_status)} processed</span>`;
        }
        return `
        <tr>
          <td>#${r.id}<br><span class="text-gray-500">${esc(r.created_at)}</span></td>
          <td>${esc(r.username)}<br><span class="text-gray-500">${esc(r.email)}</span></td>
          <td>${esc(r.movie_title)}<br><span class="text-gray-500">${esc(r.show_datetime)}</span></td>
          <td>${esc(r.cinema_name)}<br><span class="text-gray-500">${esc(r.governorate_name || '')} - ${esc(r.hall_name || '')}</span></td>
          <td>${renderPaymentSeats(r)}</td>
          <td>${money(r.total_price)}</td>
          <td>${badge(r.payment_status)}<br><span class="text-gray-500">${esc(r.payment_method || '')}</span></td>
          <td>${badge(r.ticket_status)}<br><span class="text-gray-500 break-all">${esc(r.ticket_code || '')}</span></td>
          <td class="space-y-2">${actions}</td>
        </tr>`;
      }).join('') || '<tr><td colspan="9">No payments found.</td></tr>'}</tbody>
    </table></div>`;
}

async function loadRefundRequests() {
  const box = document.getElementById('tab-refunds');
  if (!box) return;
  box.innerHTML = '<div class="card text-gray-400">Loading refund requests...</div>';
  const res = await api('admin_refund_requests');
  if (res.status !== 'success') {
    box.innerHTML = `<div class="card text-red-300">${esc(res.message)}</div>`;
    return;
  }

  const rows = res.requests || [];
  box.innerHTML = `
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-black">Refund Requests</h2>
        <p class="text-sm text-gray-400">Approve or reject customer refund requests for selected seats.</p>
      </div>
      <button class="btn-muted" onclick="loadRefundRequests()">Refresh</button>
    </div>
    <div class="table-wrap"><table>
      <thead><tr><th>ID</th><th>User</th><th>Movie</th><th>Cinema</th><th>Seats</th><th>Amount</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>${rows.map(r => {
        const seats = parseRefundSeats(r.seats_json).map(paymentSeatLabel).join(', ');
        return `
          <tr>
            <td>#${esc(r.id)}<br><span class="text-gray-500">${esc(r.requested_at || '')}</span></td>
            <td>${esc(r.username)}<br><span class="text-gray-500">${esc(r.email)}</span></td>
            <td>${esc(r.movie_title)}<br><span class="text-gray-500">${esc(r.show_datetime)}</span></td>
            <td>${esc(r.cinema_name)}<br><span class="text-gray-500">${esc(r.governorate_name || '')} - ${esc(r.hall_name || '')}</span></td>
            <td>${esc(seats || '-')}</td>
            <td>${money(r.refund_amount)}</td>
            <td>${badge(r.status)}</td>
            <td class="space-y-2">
              ${r.status === 'pending' ? `
                <button class="btn-muted" onclick="processRefundRequest(${Number(r.id)}, 'approved')">Approve Refund</button>
                <button class="btn-muted" onclick="processRefundRequest(${Number(r.id)}, 'rejected')">Reject</button>
              ` : `<span class="text-gray-500">Processed ${esc(r.processed_at || '')}</span>`}
            </td>
          </tr>`;
      }).join('') || '<tr><td colspan="8">No refund requests yet.</td></tr>'}</tbody>
    </table></div>`;
}

function parseRefundSeats(json) {
  try {
    const seats = JSON.parse(json || '[]');
    return Array.isArray(seats) ? seats : [];
  } catch (e) {
    return [];
  }
}

async function processRefundRequest(requestId, decision) {
  const label = decision === 'approved' ? 'approve this refund' : 'reject this refund';
  if (!confirm(`Are you sure you want to ${label}?`)) return;
  const res = await api('admin_process_refund_request', { request_id: requestId, decision }, 'POST');
  alert(res.message || 'Done.');
  await Promise.all([loadDashboard(), loadPayments(), loadRefundRequests()]);
}

function paymentSeatLabel(seat) {
  const row = Number(seat.seat_row ?? seat.row ?? 0);
  const number = Number(seat.seat_number ?? seat.number ?? 0);
  const rowName = row > 0 && row <= 26 ? String.fromCharCode(64 + row) : `R${row}`;
  return `${rowName}${number}`;
}

function renderPaymentSeats(booking) {
  const seats = booking.seats || [];
  if (!seats.length) return '<span class="text-gray-500">No active seats</span>';
  return `
    <div class="seat-check-list">
      ${seats.map(seat => `
        <label class="seat-check">
          <input
            type="checkbox"
            class="admin-refund-seat-${booking.id}"
            data-row="${Number(seat.seat_row)}"
            data-number="${Number(seat.seat_number)}"
          />
          <span>${esc(paymentSeatLabel(seat))}</span>
        </label>`).join('')}
    </div>`;
}

function selectAllAdminRefundSeats(bookingId) {
  document.querySelectorAll(`.admin-refund-seat-${bookingId}`).forEach(input => {
    input.checked = true;
  });
}

async function refundSelectedAdminSeats(bookingId) {
  const selected = Array.from(document.querySelectorAll(`.admin-refund-seat-${bookingId}:checked`)).map(input => ({
    row: Number(input.dataset.row),
    number: Number(input.dataset.number),
  }));

  if (!selected.length) {
    alert('Choose at least one seat first.');
    return;
  }

  if (!confirm('Apply this change to the selected seat(s)?')) return;
  const res = await api('admin_refund_booking_seats', { booking_id: bookingId, seats: selected }, 'POST');
  alert(res.message || 'Done.');
  await Promise.all([loadDashboard(), loadPayments()]);
}

async function paymentAction(action, bookingId) {
  if (!confirm('Apply this action?')) return;
  const res = await api(action, { booking_id: bookingId }, 'POST');
  alert(res.message || 'Done.');


  await Promise.all([loadDashboard(), loadPayments()]);
}

async function adminCancelReservationForMaintenance(bookingId) {
  const reason = prompt('Reason for cancelling this reservation:', 'Cinema maintenance');
  if (reason === null) return;
  const cleanReason = reason.trim() || 'Cinema maintenance';
  if (!confirm(`Cancel this reservation because of "${cleanReason}"? The user will be notified.`)) return;

  const res = await api('admin_cancel_booking_maintenance', {
    booking_id: bookingId,
    reason: cleanReason,
  }, 'POST');
  alert(res.message || 'Done.');
  await Promise.all([loadDashboard(), loadCinemaReservations(), loadPayments(), loadRefundRequests()]);
}

async function loadRegionalAdmins() {
  const box = document.getElementById('tab-manager');
  if (!box) return;
  box.innerHTML = '<div class="card text-gray-400">Loading manager tools...</div>';

  const [govRes, adminsRes] = await Promise.all([api('governorates'), api('admin_region_admins')]);
  if (govRes.status !== 'success' || adminsRes.status !== 'success') {
    box.innerHTML = `<div class="card text-red-300">${esc(govRes.message || adminsRes.message || 'Could not load manager tools.')}</div>`;
    return;
  }

  const govs = govRes.governorates || [];
  const admins = adminsRes.admins || [];
  adminState.govs = govs;

  const createRegionChecks = govs.map(g =>
    `<label class="region-check"><input type="checkbox" name="create-region" value="${g.id}"> ${esc(g.name_en)}</label>`
  ).join('');

  box.innerHTML = `
    <div class="grid grid-cols-1 lg:grid-cols-[360px_1fr] gap-4">
      <form class="card" onsubmit="createRegionalAdmin(event)">
        <h2 class="text-xl font-black mb-2">Create Regional Admin</h2>
        <p class="text-sm text-gray-400 mb-4">Assign one or more regions. The account manages payments, tickets, support and refunds for every selected region.</p>

        <label class="text-xs font-black uppercase tracking-widest text-gray-500">Full Name</label>
        <input id="regional-admin-username" class="input mt-2 mb-3" placeholder="Alex Regional Admin" />
        <label class="text-xs font-black uppercase tracking-widest text-gray-500">Email</label>
        <input id="regional-admin-email" type="email" class="input mt-2 mb-3" placeholder="alex.admin@gmail.com" />
        <label class="text-xs font-black uppercase tracking-widest text-gray-500">Password</label>
        <input id="regional-admin-password" type="password" class="input mt-2 mb-4" placeholder="At least 6 characters with letters and numbers" />

        <label class="text-xs font-black uppercase tracking-widest text-gray-500">Regions (one or more)</label>
        <div class="region-check-grid mt-2 mb-4">${createRegionChecks}</div>

        <button class="btn w-full" type="submit">Create Account</button>
      </form>

      <div class="card">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
          <div>
            <h2 class="text-xl font-black">Regional Admin Accounts</h2>
            <p class="text-sm text-gray-400">Change regions (one or many), update role, or deactivate. Active admins are notified on their next update.</p>
          </div>
          <button class="btn-muted" onclick="loadRegionalAdmins()">Refresh</button>
        </div>
        <div class="space-y-3">
          ${admins.map(a => renderRegionalAdminRow(a, govs)).join('') || '<div class="text-sm text-gray-500">No regional admin or staff accounts yet.</div>'}
        </div>
      </div>
    </div>`;
}

function renderRegionalAdminRow(a, govs) {
  const assigned = (a.region_id_list || []).map(String);
  const regionOptions = govs.map(g =>
    `<option value="${g.id}" ${assigned.includes(String(g.id)) ? 'selected' : ''}>${esc(g.name_en)}</option>`
  ).join('');
  const roles = ['regional_admin', 'staff', 'user'];
  const roleOptions = roles.map(r => `<option value="${r}" ${a.role === r ? 'selected' : ''}>${r}</option>`).join('');
  const blocked = String(a.is_blocked) === '1';
  return `
    <div class="card admin-account-row ${blocked ? 'opacity-60' : ''}">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div class="font-black">${esc(a.username)} ${blocked ? badge('blocked') : badge('active')}</div>
          <div class="text-xs text-gray-500">${esc(a.email)} - created ${esc(a.created_at || '')}</div>
          <div class="text-xs text-gray-400 mt-1">Regions: ${esc(a.region_names || '-')}</div>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-xs text-gray-500">Role</label>
          <select class="input" onchange="updateRegionalAdminRole(${a.id}, this.value)">${roleOptions}</select>
          <button class="btn-muted" onclick="setRegionalAdminStatus(${a.id}, ${blocked ? 0 : 1})">${blocked ? 'Activate' : 'Deactivate'}</button>
        </div>
      </div>
      <div class="mt-3">
        <label class="text-xs font-black uppercase tracking-widest text-gray-500">Assigned Regions (Ctrl/Cmd-click to pick more than one)</label>
        <div class="flex flex-col md:flex-row gap-2 mt-2">
          <select id="regions-${a.id}" class="input" multiple size="4" style="min-height:96px">${regionOptions}</select>
          <button class="btn-muted self-start" onclick="saveRegionalAdminRegions(${a.id})">Save Regions</button>
        </div>
      </div>
    </div>`;
}


const ALLOWED_PUBLIC_EMAIL_DOMAINS = new Set([
  'gmail.com', 'googlemail.com',
  'yahoo.com', 'yahoo.co.uk', 'yahoo.com.eg', 'ymail.com', 'rocketmail.com',
  'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
  'icloud.com', 'me.com', 'mac.com',
  'proton.me', 'protonmail.com',
  'aol.com', 'mail.com', 'zoho.com', 'yandex.com', 'gmx.com', 'gmx.net'
]);
function isAllowedPublicEmail(email) {
  const value = String(email || '').trim().toLowerCase();
  const match = value.match(/^[^\s@]+@([^\s@]+\.[^\s@]+)$/);
  return !!match && ALLOWED_PUBLIC_EMAIL_DOMAINS.has(match[1]);
}
function realEmailMessage() {
  return 'Please use a real email from Gmail, Yahoo, Outlook, Hotmail, iCloud, Proton, Zoho, Mail.com, Yandex, AOL, or GMX.';
}

function passwordPolicyMessage(label = 'Password') {
  return `${label} must be at least 6 characters and contain both letters and numbers.`;
}

function isStrongPassword(password) {
  const value = String(password || '');
  return value.length >= 6 && /[A-Za-z]/.test(value) && /\d/.test(value);
}


async function createRegionalAdmin(event) {
  event.preventDefault();
  const regionIds = Array.from(document.querySelectorAll('input[name="create-region"]:checked')).map(c => c.value);
  if (!regionIds.length) { alert('Select at least one region.'); return; }
  const payload = {
    username: document.getElementById('regional-admin-username')?.value.trim() || '',
    email: document.getElementById('regional-admin-email')?.value.trim() || '',
    password: document.getElementById('regional-admin-password')?.value || '',
    governorate_ids: regionIds,
  };
  if (!isAllowedPublicEmail(payload.email)) {
    alert(realEmailMessage());
    return;
  }
  if (!isStrongPassword(payload.password)) {
    alert(passwordPolicyMessage('Password'));
    return;
  }
  const res = await api('admin_create_region_admin', payload, 'POST');
  alert(res.message || (res.status === 'success' ? 'Regional admin created.' : 'Could not create account.'));
  if (res.status === 'success') await loadRegionalAdmins();
}

async function saveRegionalAdminRegions(userId) {
  const sel = document.getElementById(`regions-${userId}`);
  const ids = sel ? Array.from(sel.selectedOptions).map(o => o.value) : [];
  if (!ids.length) { alert('Select at least one region.'); return; }
  const res = await api('admin_update_region_admin_region', { user_id: userId, governorate_ids: ids }, 'POST');
  alert(res.message || 'Regions updated. If this regional admin is currently logged in, a transfer popup will appear automatically in their tab.');
  await loadRegionalAdmins();
}

async function updateRegionalAdminRole(userId, role) {
  const res = await api('admin_update_region_admin_role', { user_id: userId, role }, 'POST');
  alert(res.message || 'Role updated.');
  await loadRegionalAdmins();
}

async function setRegionalAdminStatus(userId, isBlocked) {
  const res = await api('admin_set_region_admin_status', { user_id: userId, is_blocked: isBlocked }, 'POST');
  alert(res.message || 'Updated.');
  await loadRegionalAdmins();
}


async function loadForgotPasswordRequests() {
  const box = document.getElementById('tab-forgot');
  if (!box) return;
  box.innerHTML = '<div class="card text-gray-400">Loading forgot password requests...</div>';
  const res = await api('admin_forgot_password_requests');
  if (res.status !== 'success') {
    box.innerHTML = `<div class="card text-red-300">${esc(res.message || 'Could not load requests.')}</div>`;
    return;
  }
  const requests = res.requests || [];
  box.innerHTML = `
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-black">Forgot Password Requests</h2>
        <p class="text-sm text-gray-400">Users can change password by email code. Old passwords are not displayed because they are stored as secure hashes.</p>
      </div>
      <button class="btn-muted" onclick="loadForgotPasswordRequests()">Refresh</button>
    </div>
    <div class="grid grid-cols-1 gap-4">
      ${requests.map(renderForgotPasswordRequest).join('') || '<div class="card text-gray-400">No forgot password requests yet.</div>'}
    </div>`;
}


async function copyForgotCode(code, email, channel = 'email', phone = '') {
  const target = channel === 'phone' && phone ? `${phone} (WhatsApp/SMS)` : `${email} (Gmail/Outlook/Yahoo)`;
  const text = `CINEMAX password reset code for ${email}: ${code}. This code expires in 10 minutes. Send to: ${target}.`;
  try {
    await navigator.clipboard.writeText(text);
    alert(channel === 'phone'
      ? 'Verification code message copied. Send it to the verified phone number by WhatsApp or SMS.'
      : 'Verification code message copied. Send it to the user by Gmail, Outlook, Yahoo, or another verified email channel.');
  } catch (e) {
    prompt('Copy this verification code message:', text);
  }
}

function renderForgotPasswordRequest(r) {
  const open = ['pending','code_sent'].includes(r.status);
  return `
    <article class="card">
      <div class="flex flex-wrap justify-between gap-3 mb-3">
        <div>
          <div class="text-xs text-gray-500 uppercase font-black tracking-widest">Request #${esc(r.id)} - ${esc(r.request_type)}</div>
          <h3 class="text-lg font-black mt-1">${esc(r.email)}</h3>
          <p class="text-sm text-gray-400">User: ${esc(r.username || 'Unknown')} - Created: ${esc(r.created_at || '')}</p>
          <p class="text-sm text-gray-300 mt-2"><b>Preferred delivery:</b> ${esc(r.preferred_channel === 'phone' ? 'WhatsApp / SMS' : 'Gmail / Outlook / Yahoo')}</p>
          ${r.preferred_channel === 'phone' && r.contact_phone ? `<p class="text-sm text-yellow-200"><b>Phone:</b> ${esc(r.contact_phone)}</p>` : ''}
          ${r.request_type === 'change' && r.admin_visible_code ? `
            <div class="mt-3 rounded-xl border border-yellow-500/40 bg-yellow-500/10 p-3">
              <p class="text-xs text-yellow-200 font-black uppercase tracking-widest">Manual verification code</p>
              <p class="text-2xl font-black text-white tracking-widest mt-1">${esc(r.admin_visible_code)}</p>
              <p class="text-xs text-gray-300 mt-1">Expires: ${esc(r.code_expires_at || '10 minutes after request')}</p>
              <button class="btn-muted mt-3" onclick="copyForgotCode('${esc(r.admin_visible_code)}', '${esc(r.email)}', '${esc(r.preferred_channel || 'email')}', '${esc(r.contact_phone || '')}')">Copy code message</button>
            </div>` : ''}
          ${r.request_type === 'remember' ? `<p class="text-xs text-yellow-300 mt-2">For security, the old password cannot be shown. Ask the user to use the verification-code reset option.</p>` : ''}
          ${r.admin_note ? `<p class="text-xs text-gray-400 mt-2">Note: ${esc(r.admin_note)}</p>` : ''}
        </div>
        <div>${badge(r.status || 'pending')}</div>
      </div>
      ${open ? `
      <div class="flex flex-col gap-2 mt-4">
        <input id="forgot-note-${esc(r.id)}" class="input" placeholder="Admin note" />
        <div class="flex flex-wrap gap-2">
          <button class="btn" onclick="handleForgotPasswordRequest(${Number(r.id)}, 'resolved')">Mark Resolved</button>
          <button class="btn-muted" onclick="handleForgotPasswordRequest(${Number(r.id)}, 'rejected')">Reject</button>
        </div>
      </div>` : ''}
    </article>`;
}

async function handleForgotPasswordRequest(id, decision) {
  const note = document.getElementById(`forgot-note-${id}`)?.value.trim() || '';
  const res = await api('admin_handle_forgot_password_request', { request_id: id, decision, note }, 'POST');
  alert(res.message || 'Request updated.');
  await loadForgotPasswordRequests();
}

async function loadSupport() {
  const box = document.getElementById('tab-support');
  box.innerHTML = '<div class="card text-gray-400">Loading support tickets...</div>';
  const res = await api('admin_support_tickets');
  if (res.status !== 'success') {
    box.innerHTML = `<div class="card text-red-300">${esc(res.message)}</div>`;
    return;
  }

  const tickets = res.tickets || [];
  box.innerHTML = `
    <div class="card mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-xl font-black">Customer Support</h2>
        <p class="text-sm text-gray-400">Review customer issues, reply to them, and close solved tickets.</p>
      </div>
      <button class="btn-muted" onclick="loadSupport()">Refresh</button>
    </div>
    <div class="grid grid-cols-1 gap-4">
      ${tickets.map(renderSupportTicket).join('') || '<div class="card text-gray-400">No support tickets received yet.</div>'}
    </div>`;
}

function renderSupportTicket(ticket) {
  const replies = ticket.replies || [];
  const replyHtml = replies.length ? `
    <div class="support-thread">
      <div class="text-gray-400 text-xs uppercase font-black tracking-widest mb-2">Admin Replies</div>
      ${replies.map(r => `
        <div class="support-reply">
          <div class="text-xs text-gray-400 mb-1">${esc(r.admin_name || 'Admin')} - ${esc(r.created_at || '')}</div>
          <div>${esc(r.message)}</div>
        </div>`).join('')}
    </div>` : '';

  return `
    <article class="card support-ticket">
      <div class="flex flex-wrap justify-between gap-3 mb-4">
        <div>
          <div class="text-gray-400 text-xs uppercase font-black tracking-widest">Ticket #${esc(ticket.id)}</div>
          <h3 class="text-lg font-black mt-1">${esc(ticket.subject || 'No subject')}</h3>
          <p class="text-sm text-gray-400">
            ${esc(ticket.name || ticket.username || 'Customer')}
            ${ticket.email ? ` - ${esc(ticket.email)}` : ''}
            ${ticket.created_at ? ` - ${esc(ticket.created_at)}` : ''}
          </p>
          <p class="text-xs text-gray-500 mt-1">Region: ${esc(ticket.governorate_name || 'No region selected')}</p>
        </div>
        <div>${badge(ticket.status || 'open')}</div>
      </div>
      <div class="support-message">${esc(ticket.message || '')}</div>
      ${replyHtml}
      ${ticket.is_legacy ? '<p class="text-sm text-yellow-300 mt-4">This is an older support request. It is visible here, but replies are available for new support tickets only.</p>' : ''}
      ${ticket.status === 'closed' || ticket.is_legacy ? '' : `
        <div class="support-actions mt-4">
          <textarea id="support-reply-${esc(ticket.id)}" class="textarea" placeholder="Write an admin reply to this customer"></textarea>
          <div class="flex flex-wrap gap-2 mt-3">
            <button class="btn" onclick="replySupportTicket(${Number(ticket.id)})">Send Reply</button>
            <button class="btn-muted" onclick="closeSupportTicket(${Number(ticket.id)})">Close Ticket</button>
          </div>
        </div>`}
    </article>`;
}

async function replySupportTicket(ticketId) {
  const input = document.getElementById(`support-reply-${ticketId}`);
  const message = input ? input.value.trim() : '';
  if (!message) {
    alert('Write a reply first.');
    return;
  }

  const res = await api('admin_reply_ticket', { ticket_id: ticketId, message }, 'POST');
  alert(res.message || 'Reply sent.');
  await loadSupport();
}

async function closeSupportTicket(ticketId) {
  if (!confirm('Close this support ticket?')) return;
  const res = await api('admin_close_ticket', { ticket_id: ticketId }, 'POST');
  alert(res.message || 'Ticket closed.');
  await loadSupport();
}

// Access QR was removed from the admin dashboard. Use standalone access_qr.html for demo links.

function renderTicketVerifier() {
  document.getElementById('tab-tickets').innerHTML = `
    <div class="card max-w-2xl">
      <h2 class="text-xl font-black mb-2">Ticket QR Verification</h2>
      <p class="text-sm text-gray-400 mb-4">Paste or type the ticket code from the QR ticket.</p>
      <div class="flex gap-2"><input id="ticket-code-input" class="input" placeholder="CMX-123-ABCD1234" /><button class="btn" onclick="verifyTicket()">Verify</button></div>
      <div id="ticket-result" class="mt-5"></div>
    </div>`;
}

async function verifyTicket() {
  const code = document.getElementById('ticket-code-input').value.trim();
  const box = document.getElementById('ticket-result');
  if (!code) return;
  box.innerHTML = '<p class="text-gray-400">Checking…</p>';
  const res = await api('verify_ticket', { ticket_code: code });
  if (res.status !== 'success') { box.innerHTML = `<p class="text-red-300">${esc(res.message)}</p>`; return; }
  const t = res.ticket;
  const seats = (t.seats || []).map(s => String.fromCharCode(64 + parseInt(s.seat_row)) + s.seat_number).join(', ');
  box.innerHTML = `
    <div class="card ${t.is_valid ? 'border-green-500/30' : 'border-red-500/30'}">
      <div class="text-2xl font-black mb-2">${t.is_valid ? '✅ Valid Ticket' : '⚠️ Invalid / Not Usable'}</div>
      <div class="text-sm space-y-2">
        <div><b>Movie:</b> ${esc(t.movie_title)}</div>
        <div><b>User:</b> ${esc(t.username)} — ${esc(t.email)}</div>
        <div><b>Cinema:</b> ${esc(t.cinema_name)} · ${esc(t.hall_name)}</div>
        <div><b>Showtime:</b> ${esc(t.show_datetime)}</div>
        <div><b>Seats:</b> ${esc(seats)}</div>
        <div><b>Payment:</b> ${badge(t.payment_status)} <b class="ml-3">Ticket:</b> ${badge(t.ticket_status)}</div>
      </div>
      ${t.is_valid ? `<button class="btn mt-4" data-mark-ticket-used data-ticket-code="${esc(t.ticket_code)}">Mark as Used</button>` : ''}
    </div>`;
  box.querySelector('[data-mark-ticket-used]')?.addEventListener('click', e => {
    markTicketUsed(e.currentTarget.dataset.ticketCode || '');
  });
  appendVerifiedTicketQr(box, t);
}

function appendVerifiedTicketQr(box, ticket) {
  const resultCard = box.querySelector('.card');
  if (!resultCard || !ticket.ticket_code) return;

  const qrWrap = document.createElement('div');
  qrWrap.className = 'mt-4 inline-block rounded-2xl bg-white p-3 text-center text-black';
  qrWrap.innerHTML = `
    <canvas id="verify-ticket-qr"></canvas>
    <div class="mt-2 text-[10px] font-black break-all">${esc(ticket.ticket_code)}</div>
  `;
  resultCard.appendChild(qrWrap);

  const canvas = document.getElementById('verify-ticket-qr');
  if (canvas && window.QRCode) {
    QRCode.toCanvas(canvas, `CINEMAX|BOOKING=${ticket.id}|CODE=${ticket.ticket_code}`, { width: 126, margin: 1 });
  }
}

async function markTicketUsed(code) {
  const res = await api('mark_ticket_used', { ticket_code: code }, 'POST');
  if (res.status !== 'success') {
    alert(res.message || 'Could not mark ticket as used.');
    return;
  }
  verifyTicket();
}

document.addEventListener('DOMContentLoaded', initAdmin);
