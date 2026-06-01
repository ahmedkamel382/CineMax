/* ============================================================
   CINEMAX EGYPT — script.js  (Complete Frontend)
   ============================================================
   SECTIONS:
     1.  TMDB CONFIG & MOVIE FETCHING  ← live from TMDB API
     2.  APP STATE
     3.  PHP API HELPER               ← talks to api.php
     4.  INIT
     5.  VIEW SWITCHER
     6.  HOME — hero carousel + movie grids
     7.  MOVIE DETAILS PAGE
     8.  BOOKING — step 1: cinema accordion
     9.  BOOKING — step 2: time slots
    10.  BOOKING — step 3: seat map
    11.  BOOKING — confirm & success
    12.  TRAILER MODAL
    13.  CINEMAS MODAL
    14.  MY BOOKINGS MODAL
    15.  AUTH (login / register)
    16.  COMMENTS & REVIEWS
    17.  SUPPORT FORM
    18.  SEARCH & NAVIGATION
    19.  UTILITIES
   ============================================================ */


/* ============================================================
   1. MOVIE FETCHING  (now via api.php, NOT TMDB directly)

   The Bearer token used to live in this file, which meant
   anyone could open DevTools and steal it. All TMDB calls
   are now proxied through tmdb_service.php on the server,
   so the token never leaves the backend.

   The shape of what these functions return is unchanged, so
   the rest of script.js keeps working as before.
   ============================================================ */

const IMG_W500 = "https://image.tmdb.org/t/p/w500";
const IMG_ORIG = "https://image.tmdb.org/t/p/original";

/**
 * fetchMovies(type)
 * type = 'now_playing' or 'upcoming'
 * Curation (date filter, streaming filter, 4 AR + 8 EN mix)
 * now happens in PHP — we just unwrap the response here.
 */
async function fetchMovies(type) {
  const action = type === 'now_playing' ? 'movies_now_showing' : 'movies_upcoming';
  const res    = await api(action);
  if (res.status !== 'success') {
    console.error('fetchMovies failed:', res.message);
    return [];
  }
  return res.movies || [];
}

/**
 * fetchTrailer(tmdbId)
 * Returns a YouTube embed URL or '' if no good trailer.
 */
async function fetchTrailer(tmdbId) {
  const res = await api('movie_trailer', { tmdb_id: tmdbId });
  return res.status === 'success' ? (res.trailer_url || '') : '';
}

/**
 * fetchCast(tmdbId)
 * Returns top 8 cast + director (director first).
 */
async function fetchCast(tmdbId) {
  const res = await api('movie_cast', { tmdb_id: tmdbId });
  return res.status === 'success' ? (res.cast || []) : [];
}


/* ============================================================
   2. APP STATE
   All mutable data in one object — easy to track and debug.
   ============================================================ */
const state = {
  nowShowing:    [],      // from TMDB now_playing
  comingSoon:    [],      // from TMDB upcoming (only future dates!)
  selectedMovie: null,    // the movie currently being viewed / booked
  heroIndex:     0,       // which movie is in the hero
  heroInterval:  null,    // setInterval handle for carousel
  history: [],
  bookingCache: {},

  // Booking flow
  currentShowtime: null,  // the showtime the user picked
  selectedSeats:   [],    // [{row:1, number:3}, ...]
  bookedSeats:     new Set(), // "row-col" strings already booked
  lockedSeats:     new Set(), // "row-col" strings already reserved/unavailable by another user
  selectedCinema:  null,  // {id, name, location} for back navigation
  cinemaNav:       [],    // back-stack for the Cinemas browse flow
  lockExpiresAt:   null,

  // Auth
  isLoggedIn: false,
  username:   null,
  userEmail:  null,
  userId:     null,
  userRole:   'user',   // 'user' | 'staff' | 'admin' | 'regional_admin' - set from /session
  csrfToken:  null,     // populated once by loadCsrfToken() on startup
  loginTarget: 'user',   // login modal intent: user stays here, admin goes to admin.html

  // Comments pagination
  commentOffset:  0,
  commentTotal:   0,
  selectedRating: 0,      // star picker value (1–5)
};

let userLiveSyncTimer = null;
let userLiveSyncRunning = false;
let seatLockCountdownTimer = null;
let seatMapRefreshTimer = null;


/* ============================================================
   3. PHP API HELPER
   All calls to api.php go through this function.
   GET:  api('showtimes', {tmdb_id:123}) → api.php?action=showtimes&tmdb_id=123
   POST: api('book', {...}, 'POST')      → POST body as JSON
   For POSTs the CSRF token (loaded once at startup via
   api('csrf_token')) is automatically attached.
   ============================================================ */
// Short-lived client cache for read-only GET actions, plus de-duplication of
// identical in-flight requests. This makes navigating around feel instant and
// avoids hammering the server when several parts of the UI want the same data.
const API_GET_CACHE = new Map();      // key -> { data, expires }
const API_INFLIGHT  = new Map();      // key -> Promise
const API_CACHE_TTL = 60 * 1000;      // 60s
// Actions whose results are safe to cache briefly (no per-request side effects).
const CACHEABLE_GET = new Set([
  'governorates', 'cinemas', 'movie_cinemas', 'cinema_movies', 'showtimes',
  'movies_now_showing', 'movies_upcoming', 'movie_trailer', 'movie_cast',
  'movie_details', 'streaming_providers', 'person_details', 'get_comments',
]);

function apiCacheKey(action, params) {
  return action + '?' + Object.entries(params).map(([k, v]) => `${k}=${v}`).sort().join('&');
}

/** Clear cached GETs (call after a mutation that changes their results). */
function invalidateApiCache(prefix = '') {
  for (const key of API_GET_CACHE.keys()) {
    if (!prefix || key.startsWith(prefix)) API_GET_CACHE.delete(key);
  }
}

async function api(action, params = {}, method = 'GET', retryOnCsrf = true) {
  // Serve/share cacheable GETs.
  const canCache = method === 'GET' && CACHEABLE_GET.has(action);
  const key = canCache ? apiCacheKey(action, params) : null;
  if (canCache) {
    const hit = API_GET_CACHE.get(key);
    if (hit && hit.expires > Date.now()) return hit.data;
    if (API_INFLIGHT.has(key)) return API_INFLIGHT.get(key);
  }

  const doFetch = async () => {
    try {
      let url  = `api.php?action=${action}`;
      let opts = {};

      if (method === 'GET') {
        const qs = Object.entries(params)
          .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
          .join('&');
        if (qs) url += '&' + qs;
      } else {
        const body = { action, ...params };

        if (state.csrfToken && body.csrf_token === undefined) {
          body.csrf_token = state.csrfToken;
        }

        opts = {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(body),
        };
      }

      const res  = await fetch(url, opts);
      const data = await res.json();

      // If the token expired or changed, fetch a new one and retry the POST once.
      if (
        method === 'POST' &&
        retryOnCsrf &&
        data.status === 'error' &&
        String(data.message || '').toLowerCase().includes('csrf')
      ) {
        await loadCsrfToken();
        return api(action, params, method, false);
      }

      if (canCache && data.status === 'success') {
        API_GET_CACHE.set(key, { data, expires: Date.now() + API_CACHE_TTL });
      }
      return data;
    } catch (e) {
      console.error('API error:', e);
      return { status: 'error', message: 'Network error. Check your connection.' };
    }
  };

  if (canCache) {
    const p = doFetch().finally(() => API_INFLIGHT.delete(key));
    API_INFLIGHT.set(key, p);
    return p;
  }
  return doFetch();
}

/**
 * loadCsrfToken()
 * Fetches a CSRF token from the server (creates one if needed) and
 * stores it on state.csrfToken so the api() helper can attach it
 * to every POST. Call this once on startup.
 */
async function loadCsrfToken() {
  const res = await api('csrf_token');
  if (res.status === 'success') state.csrfToken = res.csrf_token;
}

function normalizeAudienceHost(value) {
  return String(value || '').trim().replace(/^https?:\/\//i, '').replace(/\/.*$/, '');
}

function isLocalAudienceHost(host) {
  const cleanHost = normalizeAudienceHost(host).split(':')[0].toLowerCase();
  return cleanHost === 'localhost' || cleanHost === '127.0.0.1' || cleanHost === '';
}

function isPrivateAudienceHost(host) {
  const cleanHost = normalizeAudienceHost(host).split(':')[0].toLowerCase();
  return /^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/.test(cleanHost);
}

function isTryCloudflareHost(host) {
  return normalizeAudienceHost(host).split(':')[0].toLowerCase().endsWith('.trycloudflare.com');
}

function shouldReplaceAudienceHost(host) {
  return isLocalAudienceHost(host) || isPrivateAudienceHost(host);
}

function audienceBasePath() {
  return location.pathname.replace(/index\.html.*$/i, '');
}

function buildAudienceUrl(host) {
  const cleanHost = normalizeAudienceHost(host);
  const protocol = isTryCloudflareHost(cleanHost) ? 'https:' : (location.protocol === 'https:' ? 'https:' : 'http:');
  return `${protocol}//${cleanHost}${audienceBasePath()}index.html`;
}

function shouldShowAudienceQr() {
  const params = new URLSearchParams(location.search);
  const openedLocally = ['localhost', '127.0.0.1'].includes(location.hostname);
  return params.has('qr') || openedLocally;
}

function updateAudienceQr() {
  const input = document.getElementById('audience-host-input');
  const urlBox = document.getElementById('audience-url-text');
  const canvas = document.getElementById('audience-qr-canvas');
  const host = normalizeAudienceHost(input?.value);
  const isBadPhoneHost = isLocalAudienceHost(host);
  const url = isBadPhoneHost ? 'Enter your laptop Wi-Fi IPv4 address first. Example: 192.168.1.37' : buildAudienceUrl(host);

  if (input && !isBadPhoneHost) input.value = host;
  if (urlBox) urlBox.textContent = url;
  if (!isBadPhoneHost && !isTryCloudflareHost(host)) localStorage.setItem('cinemax_public_host', host);

  if (window.QRCode && canvas && !isBadPhoneHost) {
    QRCode.toCanvas(canvas, url, {
      width: 300,
      margin: 2,
      color: { dark: '#09090b', light: '#ffffff' }
    });
  } else if (canvas) {
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#09090b';
    ctx.font = 'bold 18px Arial';
    ctx.textAlign = 'center';
    ctx.fillText('Enter laptop IP', canvas.width / 2, canvas.height / 2 - 10);
    ctx.font = '14px Arial';
    ctx.fillText('not localhost', canvas.width / 2, canvas.height / 2 + 18);
  }
}

async function loadDetectedAudienceHost() {
  const input = document.getElementById('audience-host-input');
  if (!input || !['localhost', '127.0.0.1'].includes(location.hostname)) return;

  try {
    const res = await fetch('network_info.php', { cache: 'no-store' });
    const data = await res.json();
    if (data.status !== 'success') return;

    const currentHost = normalizeAudienceHost(input.value);
    const freshTunnelHost = (data.tunnel_is_fresh && data.tunnel_is_alive && data.tunnel_host)
      ? normalizeAudienceHost(data.tunnel_host)
      : '';
    const detectedIp = normalizeAudienceHost(data.detected_ip || '');

    if (freshTunnelHost) {
      if (freshTunnelHost !== currentHost) {
        input.value = freshTunnelHost;
        localStorage.removeItem('cinemax_public_host');
        updateAudienceQr();
      }
      return;
    }

    if (isTryCloudflareHost(currentHost)) {
      localStorage.removeItem('cinemax_public_host');
      input.value = detectedIp || '';
      updateAudienceQr();
      return;
    }

    if (detectedIp && shouldReplaceAudienceHost(currentHost)) {
      input.value = detectedIp;
      updateAudienceQr();
    }
  } catch (e) {
    console.warn('Could not detect local network IP.', e);
  }
}

async function copyAudienceUrl() {
  const url = document.getElementById('audience-url-text')?.textContent || '';
  if (!url) return;

  try {
    await navigator.clipboard.writeText(url);
    alert('Audience link copied.');
  } catch (e) {
    prompt('Copy this link:', url);
  }
}

function setupAudienceAccessQr() {
  const modal = document.getElementById('audience-qr-modal');
  const input = document.getElementById('audience-host-input');
  if (!modal || !input) return;

  const savedHost = localStorage.getItem('cinemax_public_host') || '';
  const currentIsPublic = !isLocalAudienceHost(location.hostname) && !isPrivateAudienceHost(location.hostname);
  input.value = currentIsPublic
    ? location.host
    : (savedHost && !shouldReplaceAudienceHost(savedHost) && !isTryCloudflareHost(savedHost) ? savedHost : '');

  document.getElementById('audience-generate-btn')?.addEventListener('click', updateAudienceQr);
  document.getElementById('audience-copy-btn')?.addEventListener('click', copyAudienceUrl);
  document.getElementById('audience-close-btn')?.addEventListener('click', () => {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    showEntryChoiceModal();
  });

  if (shouldShowAudienceQr()) {
    modal.classList.remove('hidden');
    modal.classList.add('flex');
  }

  updateAudienceQr();
  loadDetectedAudienceHost();
  setInterval(loadDetectedAudienceHost, 30000);
}


/* ============================================================
   4. INIT
   Runs once when the page loads. Fetches movies, checks session,
   then shows the home view.
   ============================================================ */
async function init() {
  setupAudienceAccessQr();
  populateNavGovernorates();
  setupNavigation();
  setupBookingModal();
  setupTrailerModal();
  setupCinemasModal();
  setupMyBookingsModal();
  setupAuthModals();

  // Show the Guest / Register / Login choice up front so it is usable while
  // movies load in the background. The loading screen sits behind it, and the
  // auth modals open above both (higher z-index), so login works immediately.
  if (!shouldShowAudienceQr()) showEntryChoiceModal();

  try {
    // Grab a CSRF token before anything else so all later POSTs work.
    await loadCsrfToken();

    // Check existing session (restores login state on page refresh)
    const sessRes = await api('session');
    if (sessRes.status === 'success') {
      state.isLoggedIn = true;
      state.username   = sessRes.user.username;
      state.userEmail  = sessRes.user.email || '';
      state.userId     = sessRes.user.id;
      state.userRole   = sessRes.user.role || 'user';
      updateNavForAuth();
      hideEntryChoiceModal();         // already signed in → no need to choose
      checkAndShowNotificationsPopup();
      startUserLiveSync();
    }

    // Load both movie lists at the same time (parallel = faster)
    const [nowShowing, comingSoon] = await Promise.all([
      fetchMovies('now_playing'),
      fetchMovies('upcoming'),
    ]);

    state.nowShowing = nowShowing;
    state.comingSoon = comingSoon;

    renderMovieGrids();
    showView('home');
    startHeroCarousel();

  } catch (err) {
    console.error('Init error:', err);
    document.getElementById('loading-screen').innerHTML =
      '<span class="text-red-500 text-lg font-bold">Error loading movies. Check console.</span>';
  }
}

document.addEventListener('DOMContentLoaded', init);


/* ============================================================
   5. VIEW SWITCHER
   The app is a Single Page Application — all "pages" are divs.
   showView() hides all then shows the right one.
   ============================================================ */
const VIEWS = {
  loading: document.getElementById('loading-screen'),
  home:    document.getElementById('home-view'),
  details: document.getElementById('details-view'),
  support: document.getElementById('support-view'),
  requests: document.getElementById('requests-view'),
  person:  document.getElementById('person-view'),   // actor / director profile
};

function showView(name) {
  Object.values(VIEWS).forEach(v => v.classList.add('hidden'));
  if (VIEWS[name]) VIEWS[name].classList.remove('hidden');

  state.currentView = name;
}


/* ============================================================
   6. HOME — HERO CAROUSEL + MOVIE GRIDS
   ============================================================ */

/**
 * renderMovieGrids(filter)
 * Populates the Now Showing and Coming Soon grids.
 * filter: optional text to search by title.
 */
function renderMovieGrids(filter = '') {
  const q = filter.toLowerCase();
  const filterMovie = m => !q || m.title.toLowerCase().includes(q) ||
                           (m.genres || []).some(g => g.toLowerCase().includes(q));

  const nowGrid  = document.getElementById('now-showing-grid');
  const soonGrid = document.getElementById('coming-soon-grid');

  const nowFiltered  = state.nowShowing.filter(filterMovie);
  const soonFiltered = state.comingSoon.filter(filterMovie);

  nowGrid.innerHTML  = nowFiltered.length
    ? nowFiltered.map(movieCardHTML).join('')
    : '<p class="col-span-full text-gray-600 text-sm">No results.</p>';
  soonGrid.innerHTML = soonFiltered.length
    ? soonFiltered.map(movieCardHTML).join('')
    : '<p class="col-span-full text-gray-600 text-sm">No results.</p>';

  // Attach click → open details
  document.querySelectorAll('.movie-card[data-id]').forEach(card => {
    card.addEventListener('click', () => {
      const all   = [...state.nowShowing, ...state.comingSoon];
      const movie = all.find(m => m.id === card.dataset.id);
      if (movie) openDetails(movie);
    });
  });
}

/** Returns the HTML string for a single movie card. */
function movieCardHTML(movie) {
  return `
    <div class="movie-card group cursor-pointer" data-id="${movie.id}">
      <div class="poster-wrap relative aspect-[2/3] rounded-xl overflow-hidden border border-white/5 bg-white/5 group-hover:border-brand transition-colors duration-300">
        <img src="${esc(movie.posterUrl)}" alt="${esc(movie.title)}"
             loading="lazy" class="w-full h-full object-cover" />
        <div class="overlay absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex flex-col justify-end p-3">
          <h3 class="text-xs font-bold text-white leading-tight">${esc(movie.title)}</h3>
          ${movie.isComingSoon ? `<p class="text-[10px] text-brand mt-1">${esc(movie.releaseDate)}</p>` : ''}
        </div>
        <div class="rating-top absolute top-2 right-2">
          <div class="flex items-center gap-1 bg-black/70 px-2 py-1 rounded-lg border border-white/10">
            <span class="text-yellow-400 text-[10px]">★</span>
            <span class="text-[10px] font-bold text-white">${esc(movie.imdbRating)}</span>
          </div>
        </div>
        ${movie.isComingSoon ? '<div class="absolute top-2 left-2 bg-brand/90 text-white text-[9px] font-black px-2 py-0.5 rounded uppercase tracking-wider">Soon</div>' : ''}
      </div>
    </div>`;
}

/**
 * startHeroCarousel()
 * Rotates through the first 5 now-showing movies every 10 seconds.
 */
function startHeroCarousel() {
  if (state.heroInterval) clearInterval(state.heroInterval);
  const movies = state.nowShowing.slice(0, 5);
  if (!movies.length) return;

  setHeroMovie(movies[state.heroIndex]);

  state.heroInterval = setInterval(() => {
    if (!VIEWS.home.classList.contains('hidden')) {
      state.heroIndex = (state.heroIndex + 1) % movies.length;
      // Fade out → change → fade in
      const content = document.getElementById('featured-content');
      content.style.opacity   = '0';
      content.style.transform = 'translateY(10px)';
      setTimeout(() => {
        setHeroMovie(movies[state.heroIndex]);
        content.style.opacity   = '1';
        content.style.transform = 'translateY(0)';
      }, 300);
    }
  }, 10000);
}

/** Fills the left-side hero panel with a movie's data. */
function setHeroMovie(movie) {
  if (!movie) return;
  document.getElementById('featured-backdrop').style.backgroundImage = `url('${movie.backdropUrl}')`;
  document.getElementById('featured-title').textContent = movie.title;
  document.getElementById('featured-desc').textContent  = movie.description;
  document.getElementById('featured-imdb').textContent  = movie.imdbRating;
  document.getElementById('featured-rt').textContent    = movie.rtRating;

  const badge = document.getElementById('featured-type-badge');
  badge.textContent = movie.isComingSoon ? '🗓 Coming Soon' : '🎬 Now Showing';

  const reserveBtn = document.getElementById('featured-reserve-btn');
  // Hide "Reserve Tickets" for coming-soon movies
  if (movie.isComingSoon) {
    reserveBtn.classList.add('hidden');
  } else {
    reserveBtn.classList.remove('hidden');
    reserveBtn.onclick = () => { state.selectedMovie = movie; openBookingModal(); };
  }
  document.getElementById('featured-trailer-btn').onclick = () => openTrailerModal(movie);
}


/* ============================================================
   7. MOVIE DETAILS PAGE
   Two-stage load: fast (from cached list) then enriched (cast + trailer).
   ============================================================ */
async function openDetails(movie, isBack = false) {
  // Drop a breadcrumb if we are moving forward
  if (!isBack) state.history.push({ type: 'details', payload: movie });

  state.selectedMovie = movie;
  showView('details');

  // Stage 1: render immediately with what we already know
  document.getElementById('details-backdrop').style.backgroundImage = `url('${movie.backdropUrl}')`;
  document.getElementById('details-poster').src = movie.posterUrl;
  document.getElementById('details-title').textContent    = movie.title;
  document.getElementById('details-imdb').textContent     = movie.imdbRating;
  document.getElementById('details-rt').textContent       = movie.rtRating;
  document.getElementById('details-genres').textContent   = (movie.genres || []).join(' • ');
  document.getElementById('details-desc').textContent     = movie.description;

  // --- UI Routing: Tickets vs Streaming ---
  const typeEl = document.getElementById('details-type-badge');
  const bookBtn = document.getElementById('details-book-btn');
  const streamingSection = document.getElementById('streaming-section');

  // Reset buttons
  bookBtn.classList.add('hidden');
  if (streamingSection) streamingSection.classList.add('hidden');

  if (movie.isComingSoon) {
    typeEl.innerHTML = '<span class="type-badge event">🗓 Coming Soon</span>';
  } else if (movie.isCurrentlyPlaying) {
    typeEl.innerHTML = '<span class="type-badge film">🎬 Now Showing</span>';
    bookBtn.classList.remove('hidden');
    bookBtn.onclick = () => openBookingModal();
  } else if (movie.isCatalog) {
    typeEl.innerHTML = '<span class="type-badge film" style="background:rgba(255,255,255,0.1); color:white; border-color:rgba(255,255,255,0.2)">🎞️ Classic Release</span>';
    // It's an old movie! Fetch streaming providers instead of showing tickets
    loadStreamingProviders(movie.tmdbId);
  }
  // -----------------------------------------

  // Stage 2: load trailer + cast in parallel
  const trailerContainer = document.getElementById('details-trailer-container');
  const trailerSection   = document.getElementById('trailer-section');
  trailerContainer.innerHTML = '<span class="text-gray-500 text-sm animate-pulse">Loading trailer…</span>';

  const [trailerUrl, cast] = await Promise.all([
    movie.trailerUrl || fetchTrailer(movie.tmdbId),
    fetchCast(movie.tmdbId),
  ]);

  movie.trailerUrl = trailerUrl; // cache it

  if (trailerUrl) {
    trailerSection.classList.remove('hidden');
    trailerContainer.innerHTML = `<iframe class="w-full h-full" src="${trailerUrl}" frameborder="0" allowfullscreen></iframe>`;
  } else {
    trailerSection.classList.add('hidden');
  }

  renderCast(cast);
  loadComments();
  setupCommentForm();
}

async function loadStreamingProviders(tmdbId) {
  const streamingSection = document.getElementById('streaming-section');
  const streamingBadges = document.getElementById('streaming-badges');

  streamingSection.classList.remove('hidden');
  streamingBadges.innerHTML = '<span class="text-xs text-gray-500 animate-pulse">Checking providers...</span>';

  try {
    const res = await api('streaming_providers', { tmdb_id: tmdbId });
    const providers = (res.status === 'success' ? res.providers : []) || [];

    if (providers.length > 0) {
      streamingBadges.innerHTML = providers.map(p => `
        <div class="flex flex-col items-center justify-center bg-white/5 rounded-lg p-2 border border-white/10 gap-1 text-xs text-center">
          ${p.logo_url ? `<img src="${esc(p.logo_url)}" class="w-8 h-8 rounded shadow-sm" alt="${esc(p.provider_name)}" />` : ''}
          <span class="text-[10px] text-gray-300 truncate w-full">${esc(p.provider_name)}</span>
        </div>
      `).join('');
    } else {
      streamingBadges.innerHTML = '<span class="text-xs text-gray-500">Not currently streaming on major platforms.</span>';
    }
  } catch(e) {
    streamingSection.classList.add('hidden');
  }
}
/** Renders clickable cast chips below the synopsis. Clicking opens person profile. */
function renderCast(cast) {
  const section = document.getElementById('cast-section');
  const list    = document.getElementById('cast-list');
  if (!cast.length) { section.classList.add('hidden'); return; }
  section.classList.remove('hidden');
  list.innerHTML = cast.map(a => `
    <div class="cast-chip flex items-center gap-2 glass px-3 py-2 rounded-xl text-xs cursor-pointer hover:border-brand border border-white/5 transition-colors"
         onclick="openPersonView(${a.id})">
      <img src="${a.photo || 'https://via.placeholder.com/40?text=?'}" alt="${esc(a.name)}"
           class="w-8 h-8 rounded-full object-cover border border-white/10" />
      <div>
        <div class="font-bold text-white flex items-center gap-1">
          ${esc(a.name)}
          ${a.isDirector ? '<span class="text-[8px] bg-brand/20 text-brand px-1.5 py-0.5 rounded font-black uppercase tracking-wider">DIR</span>' : ''}
        </div>
        <div class="text-gray-500">${esc(a.character)}</div>
      </div>
    </div>`).join('');
}


/* ============================================================
   8. BOOKING — STEP 1: CINEMA ACCORDION
   Fetches which governorates/cinemas have showtimes for
   the selected movie, then shows them as a collapsible list.
   ============================================================ */
function setupBookingModal() {
  document.getElementById('close-modal-btn').addEventListener('click', closeBookingModal);
  document.getElementById('booking-modal').addEventListener('click', e => {
    if (e.target.id === 'booking-modal') closeBookingModal();
  });
}

function openBookingModal() {
  if (!state.selectedMovie) return;
  document.getElementById('booking-title').textContent = state.selectedMovie.title;
  const navRegion = document.getElementById('gov-filter')?.value || '';
  const bookingRegion = document.getElementById('booking-gov-filter');
  if (bookingRegion) bookingRegion.value = navRegion;
  document.getElementById('booking-modal').classList.remove('hidden');
  loadCinemaAccordion();
}

function releaseReservationLocks(showtimeId, seats) {
  if (!state.isLoggedIn || !showtimeId || !Array.isArray(seats) || !seats.length) return;
  api('release_seat_locks', { showtime_id: showtimeId, seats }, 'POST');
}

function releaseReservationLocksBeacon() {
  if (!state.isLoggedIn || !state.currentShowtime?.id || !state.selectedSeats.length || !navigator.sendBeacon) return;
  const payload = JSON.stringify({
    action: 'release_seat_locks',
    showtime_id: state.currentShowtime.id,
    seats: state.selectedSeats.map(seat => ({ row: seat.row, number: seat.number })),
    csrf_token: state.csrfToken,
  });
  navigator.sendBeacon('api.php?action=release_seat_locks', new Blob([payload], { type: 'application/json' }));
}

function closeBookingModal() {
  const showtimeId = state.currentShowtime?.id || null;
  const seatsToRelease = state.selectedSeats.map(seat => ({ row: seat.row, number: seat.number }));

  if (seatLockCountdownTimer) clearInterval(seatLockCountdownTimer);
  if (seatMapRefreshTimer) clearInterval(seatMapRefreshTimer);
  seatLockCountdownTimer = null;
  seatMapRefreshTimer = null;
  document.getElementById('booking-modal').classList.add('hidden');
  document.getElementById('booking-body').innerHTML   = '';
  state.currentShowtime = null;
  state.selectedSeats   = [];
  state.bookedSeats     = new Set();
  state.lockedSeats     = new Set();
  state.selectedCinema  = null;
  state.lockExpiresAt   = null;

  releaseReservationLocks(showtimeId, seatsToRelease);
}

window.addEventListener('pagehide', releaseReservationLocksBeacon);

/**
 * loadCinemaAccordion()
 * Calls api('movie_cinemas') → shows governorates as collapsible
 * headers, each containing its cinemas as clickable rows.
 * Effect: user clicks a cinema → moves to step 2 (time slots).
 */
async function loadCinemaAccordion() {
  const body = document.getElementById('booking-body');
  body.innerHTML = '<div class="text-center py-12 animate-pulse text-gray-500">Finding cinemas…</div>';

  const res = await api('movie_cinemas', { tmdb_id: state.selectedMovie.tmdbId });

  if (res.status !== 'success') {
    body.innerHTML = '<p class="text-brand text-sm text-center py-8">Could not load cinemas.</p>';
    return;
  }

  const govs = res.governorates || [];
  if (!govs.length) {
    body.innerHTML = `
      <div class="text-center py-16">
        <div class="text-6xl mb-4">🎬</div>
        <p class="text-gray-400 mb-2">No showtimes yet for this movie.</p>
        <p class="text-xs text-gray-600">Run tmdb_sync.php to generate showtimes.</p>
      </div>`;
    return;
  }

  const totalCinemas = govs.reduce((t, g) => t + g.cinemas.length, 0);

  let html = `
    <h3 class="section-label mb-2">Choose a Cinema</h3>
    <p class="text-xs text-gray-500 mb-6">${govs.length} governorate${govs.length !== 1 ? 's' : ''} · ${totalCinemas} cinemas available</p>
    <div class="space-y-2">`;

  govs.forEach((gov, gi) => {
    const open = gi === 0; // First governorate auto-expanded
    html += `
      <div class="gov-block rounded-2xl overflow-hidden border border-white/10" data-gov-id="${gov.id}">
        <button class="gov-accordion-header w-full flex items-center justify-between px-5 py-4 text-left hover:bg-white/5 transition-colors"
                onclick="toggleAccordion(this)" aria-expanded="${open}">
          <div class="flex items-center gap-3">
            <span class="text-xl">🗺️</span>
            <div>
              <div class="font-black text-sm">${esc(gov.name_en)}</div>
              <div class="text-xs text-gray-500">${gov.cinemas.length} cinema${gov.cinemas.length !== 1 ? 's' : ''}</div>
            </div>
          </div>
          <span class="gov-chevron text-gray-400 text-xl transition-transform duration-300 ${open ? 'rotate-180' : ''}">▾</span>
        </button>
        <div class="gov-accordion-body border-t border-white/10 ${open ? '' : 'hidden'}">
          ${gov.cinemas.map(c => `
            <button class="w-full flex items-center justify-between px-5 py-4 text-left hover:bg-brand/10 transition-colors border-b border-white/5 last:border-0"
                    data-booking-cinema
                    data-cinema-id="${c.id}"
                    data-cinema-name="${esc(c.name)}"
                    data-cinema-location="${esc(c.location || '')}">
              <div class="flex items-center gap-3">
                <span>🎬</span>
                <div>
                  <div class="font-bold text-sm">${esc(c.name)}</div>
                  <div class="text-xs text-gray-500">${esc(c.location || '')}</div>
                </div>
              </div>
              <div class="text-right ml-4 flex-shrink-0">
                <div class="text-xs text-brand font-black">from ${parseFloat(c.min_price || 150).toFixed(0)} EGP</div>
                <div class="text-[10px] text-gray-600 mt-0.5">Select →</div>
              </div>
            </button>`).join('')}
        </div>
      </div>`;
  });

  html += '</div>';
  body.innerHTML = html;
  attachBookingCinemaHandlers(body);
  applyBookingRegionFilter(document.getElementById('booking-gov-filter')?.value || '');
}

function attachBookingCinemaHandlers(root = document) {
  root.querySelectorAll('[data-booking-cinema]').forEach(btn => {
    btn.addEventListener('click', () => {
      selectCinema(
        Number(btn.dataset.cinemaId),
        btn.dataset.cinemaName || '',
        btn.dataset.cinemaLocation || ''
      );
    });
  });
}

/** Toggles a governorate section open or closed. */
function toggleAccordion(btn) {
  const body    = btn.nextElementSibling;
  const chevron = btn.querySelector('.gov-chevron');
  const isOpen  = !body.classList.contains('hidden');
  body.classList.toggle('hidden', isOpen);
  chevron.classList.toggle('rotate-180', !isOpen);
  btn.setAttribute('aria-expanded', String(!isOpen));
}


/* ============================================================
   9. BOOKING — STEP 2: TIME SLOTS FOR SELECTED CINEMA
   ============================================================ */

/**
 * selectCinema(id, name, location)
 * Stores the chosen cinema, then loads its showtimes
 * for the selected movie, grouped by date.
 */
async function selectCinema(cinemaId, cinemaName, cinemaLocation) {
  state.selectedCinema = { id: cinemaId, name: cinemaName, location: cinemaLocation };
  const body = document.getElementById('booking-body');
  body.innerHTML = '<div class="text-center py-12 animate-pulse text-gray-500">Loading showtimes…</div>';

  const res = await api('showtimes', {
    tmdb_id:   state.selectedMovie.tmdbId,
    cinema_id: cinemaId,
  });

  if (res.status !== 'success') {
    body.innerHTML = '<p class="text-brand text-sm text-center py-8">Could not load showtimes.</p>';
    return;
  }

  const showtimes = res.showtimes || [];
  if (!showtimes.length) {
    body.innerHTML = `
      <div class="text-center py-16">
        <div class="text-4xl mb-4">⏰</div>
        <p class="text-gray-400 mb-4">No upcoming showtimes at ${esc(cinemaName)}.</p>
        <button class="btn-secondary px-6 py-3 text-xs" onclick="loadCinemaAccordion()">← Back to cinemas</button>
      </div>`;
    return;
  }

  // Group showtimes by date
  const byDate = {};
  showtimes.forEach(s => {
    const date = s.show_datetime.split(' ')[0];
    if (!byDate[date]) byDate[date] = [];
    byDate[date].push(s);
  });

  let html = `
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
      <div>
        <button class="text-xs text-gray-500 hover:text-white flex items-center gap-1 mb-2 transition-colors" onclick="loadCinemaAccordion()">
          ← Change cinema
        </button>
        <div class="font-black text-base">${esc(cinemaName)}</div>
        <div class="text-xs text-gray-500">${esc(cinemaLocation)}</div>
      </div>
    </div>
    <h3 class="section-label mb-4">Pick a Date & Time</h3>`;

  for (const date in byDate) {
    html += `
      <div class="mb-6">
        <div class="text-xs font-black uppercase tracking-widest text-gray-400 mb-3 pb-2 border-b border-white/10">
          ${fmtDate(date)}
        </div>
        <div class="flex flex-wrap gap-2">
          ${byDate[date].map(s => `
            <button class="showtime-btn" onclick="selectShowtime(${s.id})">
              <span class="time">${s.show_datetime.split(' ')[1].slice(0, 5)}</span>
              <span class="hall">${esc(s.hall_name)}</span>
              <span class="meta">${esc(s.hall_type.toUpperCase())}</span>
              <span class="price">${parseFloat(s.price).toFixed(0)} EGP</span>
            </button>`).join('')}
        </div>
      </div>`;
  }

  body.innerHTML = html;
}


/* ============================================================
   10. BOOKING — STEP 3: SEAT MAP
   ============================================================ */

/**
 * selectShowtime(showtimeId)
 * Fetches showtime details + already-booked seats,
 * then renders the interactive seat grid.
 */
async function selectShowtime(showtimeId) {
  // NEW AUTH CHECK: Stop them before loading the seats!
  if (!state.isLoggedIn) {
    closeBookingModal();
    showAuthModal('login');
    return;
  }

  const body = document.getElementById('booking-body');
  body.innerHTML = '<div class="text-center py-12 animate-pulse text-gray-500">Loading seats…</div>';

  const res = await api('showtime_details', { showtime_id: showtimeId });
  if (res.status !== 'success') {
    body.innerHTML = `<p class="text-brand text-sm text-center py-8">${esc(res.message)}</p>`;
    return;
  }

  state.currentShowtime = res.showtime;
  state.selectedSeats   = [];
  // Store booked seats as "row-number" strings for O(1) lookup
  state.bookedSeats = new Set(
    (res.booked_seats || []).map(s => `${s.seat_row}-${s.seat_number}`)
  );
  state.lockedSeats = new Set(
    (res.locked_seats || [])
      .filter(s => Number(s.is_mine || 0) !== 1)
      .map(s => `${s.seat_row}-${s.seat_number}`)
  );

  renderSeatMap();
  startSeatMapRefresh(showtimeId);
}

/**
 * renderSeatMap()
 * Draws the seat grid using rows A–H and numbered columns.
 * Green/available, Red/selected, Dark/booked.
 * Also draws the summary card at the bottom.
 */
function renderSeatMap() {
  const st   = state.currentShowtime;
  const rows = parseInt(st.total_rows)    || 6;
  const cols = parseInt(st.seats_per_row) || 12;
  const dt   = new Date(st.show_datetime.replace(' ', 'T'));

  let html = `
    <!-- Back button + current showtime info -->
    <div class="flex items-start justify-between mb-6 flex-wrap gap-3">
      <div>
        <button class="text-xs text-gray-500 hover:text-white flex items-center gap-1 mb-2 transition-colors"
                onclick="selectCinema(state.selectedCinema.id, state.selectedCinema.name, state.selectedCinema.location)">
          ← Change time
        </button>
        <div class="font-black text-base">${dt.toLocaleDateString('en-EG',{weekday:'short',month:'short',day:'numeric'})} — ${dt.toLocaleTimeString('en-EG',{hour:'2-digit',minute:'2-digit'})}</div>
        <div class="text-xs text-gray-400">${esc(st.cinema_name)} · ${esc(st.hall_name)} · <span class="uppercase">${esc(st.hall_type)}</span></div>
      </div>
    </div>

    <!-- Cinema screen indicator -->
    <div class="text-center mb-6">
      <div class="screen-bar w-3/4 mx-auto"></div>
      <div class="text-[9px] uppercase tracking-[0.4em] text-gray-600 mt-1 font-black">Screen</div>
    </div>

    <!-- Seat grid -->
    <div class="space-y-1.5 mb-6" id="seat-grid">`;

  for (let r = 1; r <= rows; r++) {
    html += `<div class="flex justify-center items-center gap-1">
      <span class="w-4 text-[9px] font-bold text-gray-500 text-center">${String.fromCharCode(64 + r)}</span>`;
    for (let c = 1; c <= cols; c++) {
      const key      = `${r}-${c}`;
      const isBooked = state.bookedSeats.has(key);
      const isLocked = state.lockedSeats && state.lockedSeats.has(key);
      const isSelected = state.selectedSeats.some(s => s.row === r && s.number === c);
      const unavailable = isBooked || isLocked;
      const unavailableClass = 'bg-[#1f2937] border-white/5 text-gray-700 cursor-not-allowed';
      const selectedClass = 'bg-brand border-brand text-white shadow-[0_0_8px_rgba(225,29,72,0.5)]';
      const availableClass = 'bg-white/5 border-white/10 text-white/30 hover:border-brand hover:text-brand';
      html += `<button
        class="seat-btn w-7 h-7 rounded-t-lg rounded-b-sm text-[8px] font-bold border transition-all ${unavailable ? unavailableClass : (isSelected ? selectedClass : availableClass)}"
        data-row="${r}" data-col="${c}"
        ${unavailable ? `disabled title="Already reserved"` : ''}>
        ${c}
      </button>`;
    }
    html += `<span class="w-4 text-[9px] font-bold text-gray-500 text-center">${String.fromCharCode(64 + r)}</span>
    </div>`;
  }

  html += `</div>

    <!-- Legend -->
    <div class="flex justify-center gap-6 text-[10px] text-gray-500 mb-8">
      <span class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-white/5 border border-white/10 inline-block"></span>Available</span>
      <span class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-brand inline-block"></span>Selected</span>
      <span class="flex items-center gap-2"><span class="w-4 h-4 rounded bg-[#1f2937] inline-block"></span>Reserved / Booked</span>
    </div>

    <!-- Booking summary + confirm -->
    <div class="booking-summary-card">
      <div class="flex justify-between text-sm mb-3">
        <span class="text-gray-400">Selected Seats</span>
        <span class="font-bold text-brand" id="summary-seats">–</span>
      </div>
      <div class="flex justify-between text-sm mb-3">
        <span class="text-gray-400">Tickets</span>
        <span class="font-bold" id="ticket-count">0</span>
      </div>
      <div class="flex justify-between items-end border-t border-white/10 pt-4">
        <span class="text-xs font-black uppercase tracking-widest text-gray-500">Total</span>
        <span class="text-3xl font-black" id="total-price">0 EGP</span>
      </div>
      <button class="btn-primary w-full mt-6" id="confirm-booking-btn" disabled onclick="confirmBooking()">
        Confirm Reservation
      </button>
    </div>`;

  document.getElementById('booking-body').innerHTML = html;

  // Attach click handlers to available seats
  document.querySelectorAll('.seat-btn:not(:disabled)').forEach(btn => {
    btn.addEventListener('click', () => toggleSeat(btn));
  });
  updateSeatSummary();
}

function startSeatMapRefresh(showtimeId) {
  if (seatMapRefreshTimer) clearInterval(seatMapRefreshTimer);
  seatMapRefreshTimer = setInterval(async () => {
    const modalOpen = !document.getElementById('booking-modal')?.classList.contains('hidden');
    const seatGridVisible = !!document.getElementById('seat-grid');
    if (!modalOpen || !seatGridVisible || !state.currentShowtime || Number(state.currentShowtime.id) !== Number(showtimeId)) {
      clearInterval(seatMapRefreshTimer);
      seatMapRefreshTimer = null;
      return;
    }

    const res = await api('showtime_details', { showtime_id: showtimeId });
    if (res.status !== 'success') return;

    state.bookedSeats = new Set((res.booked_seats || []).map(s => `${s.seat_row}-${s.seat_number}`));
    state.lockedSeats = new Set(
      (res.locked_seats || [])
        .filter(s => Number(s.is_mine || 0) !== 1)
        .map(s => `${s.seat_row}-${s.seat_number}`)
    );

    state.selectedSeats = state.selectedSeats.filter(seat => {
      const key = `${seat.row}-${seat.number}`;
      return !state.bookedSeats.has(key) && !state.lockedSeats.has(key);
    });
    renderSeatMap();
  }, 15000);
}

/** Toggles a seat between selected (red) and unselected (default). Other users see selected seats as reserved/booked. */
async function toggleSeat(btn) {
  const row = parseInt(btn.dataset.row);
  const col = parseInt(btn.dataset.col);
  const key = `${row}-${col}`;
  const idx = state.selectedSeats.findIndex(s => s.row === row && s.number === col);

  btn.disabled = true;
  if (idx >= 0) {
    await api('release_seat_locks', {
      showtime_id: state.currentShowtime.id,
      seats: [{ row, number: col }],
    }, 'POST');
    state.selectedSeats.splice(idx, 1);
    btn.classList.remove('bg-brand', 'border-brand', 'text-white', 'shadow-[0_0_8px_rgba(225,29,72,0.5)]');
    btn.classList.add('bg-white/5', 'border-white/10', 'text-white/30');
    btn.disabled = false;
  } else {
    const lock = await api('lock_seats', {
      showtime_id: state.currentShowtime.id,
      seats: [{ row, number: col }],
    }, 'POST');
    if (lock.status !== 'success') {
      alert(lock.message || 'That seat is already reserved.');
      state.lockedSeats.add(key);
      btn.classList.remove('bg-white/5', 'border-white/10', 'text-white/30', 'hover:border-brand', 'hover:text-brand');
      btn.classList.add('bg-[#1f2937]', 'border-white/5', 'text-gray-700', 'cursor-not-allowed');
      btn.title = 'Already reserved';
      updateSeatSummary();
      return;
    }
    state.selectedSeats.push({ row, number: col });
    btn.classList.remove('bg-white/5', 'border-white/10', 'text-white/30');
    btn.classList.add('bg-brand', 'border-brand', 'text-white', 'shadow-[0_0_8px_rgba(225,29,72,0.5)]');
    btn.disabled = false;
  }
  updateSeatSummary();
}

/** Updates the seat labels, count, and price in the summary card. */
function updateSeatSummary() {
  const seats  = state.selectedSeats;
  const price  = parseFloat(state.currentShowtime?.price) || 150;
  const labels = seats
    .sort((a, b) => a.row - b.row || a.number - b.number)
    .map(s => String.fromCharCode(64 + s.row) + s.number)
    .join(', ');

  const ticketEl = document.getElementById('ticket-count');
  const seatsEl  = document.getElementById('summary-seats');
  const priceEl  = document.getElementById('total-price');
  const btnEl    = document.getElementById('confirm-booking-btn');

  if (ticketEl) ticketEl.textContent = seats.length;
  if (seatsEl)  seatsEl.textContent  = labels || '–';
  if (priceEl)  priceEl.textContent  = (seats.length * price).toFixed(0) + ' EGP';
  if (btnEl)    btnEl.disabled       = seats.length === 0;
}


/* ============================================================
   11. BOOKING — CONFIRM & SUCCESS SCREEN
   ============================================================ */
async function confirmBooking() {
  const sess = await api('session');
  if (sess.status !== 'success') {
    closeBookingModal();
    showAuthModal('login');
    return;
  }
  if (!state.selectedSeats.length) return;

  // Reserve seats for checkout before payment confirmation.
  const lockRes = await api('lock_seats', {
    showtime_id: state.currentShowtime.id,
    seats: state.selectedSeats,
  }, 'POST');
  if (lockRes.status !== 'success') {
    alert(lockRes.message || 'Could not reserve those seats. Please choose again.');
    await selectShowtime(state.currentShowtime.id);
    return;
  }
  state.lockExpiresAt = Date.now() + (10 * 60 * 1000);

  const seatLabels = state.selectedSeats
    .slice()
    .sort((a, b) => a.row - b.row || a.number - b.number)
    .map(s => String.fromCharCode(64 + s.row) + s.number)
    .join(', ');
  const total = state.selectedSeats.length * parseFloat(state.currentShowtime.price || 0);
  const showTimeMs = new Date(state.currentShowtime.show_datetime.replace(' ', 'T')).getTime();
  const cashTooEarly = showTimeMs - Date.now() > 24 * 60 * 60 * 1000;

  document.getElementById('booking-body').innerHTML = `
    <div class="py-6">
      <button class="text-xs text-gray-500 hover:text-white mb-4" onclick="renderSeatMap()">← Back to seats</button>
      <h3 class="text-2xl font-black mb-2">Checkout</h3>
      <p class="text-xs text-gray-500 mb-2">Your selected seats are reserved while you finish checkout.</p>
      <div class="text-xs text-yellow-300 font-bold mb-6">Reservation hold expires in <span id="seat-lock-countdown">10:00</span></div>

      <div class="glass rounded-2xl p-5 mb-5 text-sm space-y-3">
        <div class="flex justify-between gap-4"><span class="text-gray-400">Movie</span><span class="font-bold text-right">${esc(state.selectedMovie.title)}</span></div>
        <div class="flex justify-between gap-4"><span class="text-gray-400">Cinema</span><span class="text-right">${esc(state.currentShowtime.cinema_name)}</span></div>
        <div class="flex justify-between gap-4"><span class="text-gray-400">Hall</span><span>${esc(state.currentShowtime.hall_name)}</span></div>
        <div class="flex justify-between gap-4"><span class="text-gray-400">Seats</span><span class="text-brand font-bold">${seatLabels}</span></div>
        <div class="flex justify-between border-t border-white/10 pt-3"><span class="font-black uppercase tracking-widest text-xs text-gray-500">Total</span><span class="text-3xl font-black">${total.toFixed(0)} EGP</span></div>
      </div>

      <h4 class="section-label mb-3">Payment Method</h4>
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-5">
        <button class="payment-option glass rounded-xl p-4 text-left border border-white/10 hover:border-brand" data-method="simulated_card">
          <div class="text-2xl mb-2">💳</div><div class="font-black text-sm">Simulated Card</div><div class="text-[10px] text-gray-500">Auto-paid test payment</div>
        </button>
        <button class="payment-option glass rounded-xl p-4 text-left border border-white/10 hover:border-brand" data-method="simulated_wallet">
          <div class="text-2xl mb-2">📱</div><div class="font-black text-sm">Simulated Wallet</div><div class="text-[10px] text-gray-500">Auto-paid test wallet</div>
        </button>
        <button class="payment-option glass rounded-xl p-4 text-left border border-white/10 hover:border-brand disabled:opacity-40 disabled:cursor-not-allowed" data-method="cash" ${cashTooEarly ? 'disabled title="Cash at Cinema opens only within 24 hours before screening"' : ''}>
          <div class="text-2xl mb-2">💵</div><div class="font-black text-sm">Cash at Cinema</div><div class="text-[10px] text-gray-500">Available in final 24h only</div>
        </button>
      </div>
      <div id="payment-details" class="mb-5"></div>
      <button id="pay-now-btn" class="btn-primary w-full" disabled>Choose a Payment Method</button>
    </div>`;

  let method = '';
  document.querySelectorAll('.payment-option:not(:disabled)').forEach(btn => {
    btn.addEventListener('click', () => {
      method = btn.dataset.method;
      document.querySelectorAll('.payment-option').forEach(b => b.classList.remove('border-brand', 'bg-brand/10'));
      btn.classList.add('border-brand', 'bg-brand/10');
      renderPaymentDetails(method);
      const payBtn = document.getElementById('pay-now-btn');
      payBtn.disabled = false;
      payBtn.textContent = method === 'cash' ? 'Confirm Cash Booking' : 'Pay Now (Simulation)';
    });
  });
  document.getElementById('pay-now-btn').onclick = () => createPayment(method);
  startSeatLockCountdown();
}

function startSeatLockCountdown() {
  if (seatLockCountdownTimer) clearInterval(seatLockCountdownTimer);
  const tick = async () => {
    const el = document.getElementById('seat-lock-countdown');
    if (!el || !state.lockExpiresAt) return;
    const remaining = Math.max(0, state.lockExpiresAt - Date.now());
    const seconds = Math.ceil(remaining / 1000);
    el.textContent = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
    if (remaining <= 0) {
      clearInterval(seatLockCountdownTimer);
      seatLockCountdownTimer = null;
      const showtimeId = state.currentShowtime?.id;
      state.selectedSeats = [];
      alert('Your reservation hold expired. Please reselect your seats.');
      if (showtimeId) await selectShowtime(showtimeId);
    }
  };
  tick();
  seatLockCountdownTimer = setInterval(tick, 1000);
}

function renderPaymentDetails(method) {
  const box = document.getElementById('payment-details');
  if (!box) return;

  if (method === 'simulated_card') {
    box.innerHTML = `
      <div class="glass rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Cardholder Name</label>
          <input id="pay-card-name" class="input-field mt-1 rounded-xl py-3" placeholder="Name on card" autocomplete="cc-name" />
        </div>
        <div class="sm:col-span-2">
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Card Number</label>
          <input id="pay-card-number" class="input-field mt-1 rounded-xl py-3" inputmode="numeric" maxlength="19" placeholder="4242 4242 4242 4242" autocomplete="cc-number" />
        </div>
        <div>
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Expiry</label>
          <input id="pay-card-expiry" class="input-field mt-1 rounded-xl py-3" inputmode="numeric" maxlength="5" placeholder="MM/YY" autocomplete="cc-exp" />
        </div>
        <div>
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">CVV</label>
          <input id="pay-card-cvv" class="input-field mt-1 rounded-xl py-3" inputmode="numeric" maxlength="4" placeholder="123" autocomplete="cc-csc" />
        </div>
      </div>`;
    attachPaymentInputMasks('simulated_card');
    return;
  }

  if (method === 'simulated_wallet') {
    box.innerHTML = `
      <div class="glass rounded-2xl p-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div>
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Wallet Phone</label>
          <input id="pay-wallet-number" class="input-field mt-1 rounded-xl py-3" inputmode="tel" placeholder="01XXXXXXXXX" />
        </div>
        <div>
          <label class="text-[10px] uppercase tracking-widest text-gray-500 font-bold">Wallet PIN</label>
          <input id="pay-wallet-pin" class="input-field mt-1 rounded-xl py-3" type="password" inputmode="numeric" maxlength="6" placeholder="4-6 digits" />
        </div>
      </div>`;
    attachPaymentInputMasks('simulated_wallet');
    return;
  }

  box.innerHTML = `
    <div class="rounded-2xl p-4 bg-yellow-500/10 border border-yellow-500/20 text-yellow-200 text-xs leading-relaxed">
      Cash reservation is available only during the final 24 hours before the screening. Pay at the cinema, then staff/admin must verify the payment before the QR ticket can be used.
    </div>`;
}

function attachPaymentInputMasks(method) {
  if (method === 'simulated_card') {
    const number = document.getElementById('pay-card-number');
    const expiry = document.getElementById('pay-card-expiry');
    const cvv = document.getElementById('pay-card-cvv');

    number?.addEventListener('input', () => {
      const digits = number.value.replace(/\D/g, '').slice(0, 16);
      number.value = digits.replace(/(.{4})/g, '$1 ').trim();
    });

    expiry?.addEventListener('input', () => {
      const digits = expiry.value.replace(/\D/g, '').slice(0, 4);
      expiry.value = digits.length > 2 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : digits;
    });

    expiry?.addEventListener('blur', () => {
      const digits = expiry.value.replace(/\D/g, '').slice(0, 4);
      if (digits.length === 4) expiry.value = `${digits.slice(0, 2)}/${digits.slice(2)}`;
    });

    cvv?.addEventListener('input', () => {
      cvv.value = cvv.value.replace(/\D/g, '').slice(0, 4);
    });
  }

  if (method === 'simulated_wallet') {
    const wallet = document.getElementById('pay-wallet-number');
    const pin = document.getElementById('pay-wallet-pin');

    wallet?.addEventListener('input', () => {
      wallet.value = wallet.value.replace(/\D/g, '').slice(0, 11);
    });

    pin?.addEventListener('input', () => {
      pin.value = pin.value.replace(/\D/g, '').slice(0, 6);
    });
  }
}

function collectPaymentDetails(method) {
  if (method === 'simulated_card') {
    const normalizeExpiry = value => {
      const raw = String(value || '').trim();
      const digits = raw.replace(/\D/g, '').slice(0, 4);
      if (/^\d{2}\/\d{2}$/.test(raw)) return raw;
      return digits.length === 4 ? `${digits.slice(0, 2)}/${digits.slice(2)}` : raw;
    };
    const details = {
      card_name: document.getElementById('pay-card-name')?.value.trim() || '',
      card_number: document.getElementById('pay-card-number')?.value.trim() || '',
      card_expiry: normalizeExpiry(document.getElementById('pay-card-expiry')?.value || ''),
      card_cvv: document.getElementById('pay-card-cvv')?.value.trim() || '',
    };
    const cardDigits = details.card_number.replace(/\D/g, '');
    const cvvDigits = details.card_cvv.replace(/\D/g, '');
    const expiryMonth = Number(details.card_expiry.slice(0, 2));
    const expiryYear = 2000 + Number(details.card_expiry.slice(3, 5));
    const now = new Date();
    const expiresAt = new Date(expiryYear, expiryMonth, 0, 23, 59, 59);
    if (!details.card_name || cardDigits.length < 12 || !/^\d{2}\/\d{2}$/.test(details.card_expiry) || expiryMonth < 1 || expiryMonth > 12 || expiresAt < now || cvvDigits.length < 3) {
      alert('Please fill valid card details before paying.');
      return null;
    }
    const expiryInput = document.getElementById('pay-card-expiry');
    if (expiryInput) expiryInput.value = details.card_expiry;
    return details;
  }

  if (method === 'simulated_wallet') {
    const details = {
      wallet_number: document.getElementById('pay-wallet-number')?.value.trim() || '',
      wallet_pin: document.getElementById('pay-wallet-pin')?.value.trim() || '',
    };
    if (details.wallet_number.replace(/\D/g, '').length < 10 || details.wallet_pin.replace(/\D/g, '').length < 4) {
      alert('Please fill valid wallet details before paying.');
      return null;
    }
    return details;
  }

  return {};
}

async function createPayment(paymentMethod) {
  const paymentDetails = collectPaymentDetails(paymentMethod);
  if (paymentDetails === null) return;

  const btn = document.getElementById('pay-now-btn');
  btn.disabled = true;
  btn.textContent = 'Processing…';

  const res = await api('create_payment', {
    showtime_id: state.currentShowtime.id,
    tmdb_id: state.selectedMovie.tmdbId,
    movie_title: state.selectedMovie.title,
    movie_poster: state.selectedMovie.posterUrl,
    seats: state.selectedSeats,
    payment_method: paymentMethod,
    payment_details: paymentDetails,
  }, 'POST');

  if (res.status !== 'success') {
    alert(res.message || 'Payment failed. Please try again.');
    if (String(res.message || '').toLowerCase().includes('session')) {
      closeBookingModal();
      showAuthModal('login');
      return;
    }
    selectShowtime(state.currentShowtime.id);
    return;
  }
  showTicket(res.booking);
}

async function ensureQRCodeLibrary() {
  if (window.QRCode) return true;
  return new Promise(resolve => {
    const s = document.createElement('script');
    s.src = 'https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js';
    s.onload = () => resolve(true);
    s.onerror = () => resolve(false);
    document.head.appendChild(s);
  });
}

function buildTicketQrText(booking) {
  // The QR payload is unique for every reservation because it includes the booking id
  // and its unique ticket code. This prevents two reserved tickets from producing
  // the same QR even if they are for the same movie, cinema, showtime, or seats.
  if (!booking) return '';
  const bookingId = booking.booking_id || booking.id || '';
  const ticketCode = booking.ticket_code || '';
  const paymentRef = booking.payment_reference || '';
  return `CINEMAX|BOOKING=${bookingId}|CODE=${ticketCode}|REF=${paymentRef}`;
}

function seatLabelFromParts(row, number) {
  return String.fromCharCode(64 + parseInt(row || 0)) + String(number || '');
}

function buildSeatQrText(booking, seat) {
  const bookingId = booking?.booking_id || booking?.id || '';
  const label = seat?.seat || seatLabelFromParts(seat?.row || seat?.seat_row, seat?.number || seat?.seat_number);
  const code = seat?.ticket_code || seat?.seat_ticket_code || booking?.ticket_code || '';
  const paymentRef = booking?.payment_reference || '';
  return seat?.qr_text || `CINEMAX|BOOKING=${bookingId}|SEAT=${label}|CODE=${code}|REF=${paymentRef}`;
}

function getSeatTicketsForBooking(booking) {
  const source = (booking?.seat_tickets && booking.seat_tickets.length)
    ? booking.seat_tickets
    : (booking?.seats || []);
  return source.map((seat, index) => {
    const row = seat.row || seat.seat_row;
    const number = seat.number || seat.seat_number;
    const label = seat.seat || seatLabelFromParts(row, number);
    const code = seat.ticket_code || seat.seat_ticket_code || booking?.ticket_code || '';
    return {
      row,
      number,
      seat: label,
      ticket_code: code,
      qr_text: buildSeatQrText(booking, { ...seat, seat: label, ticket_code: code }),
      index
    };
  });
}

async function showTicket(booking) {
  if (seatLockCountdownTimer) clearInterval(seatLockCountdownTimer);
  if (seatMapRefreshTimer) clearInterval(seatMapRefreshTimer);
  seatLockCountdownTimer = null;
  seatMapRefreshTimer = null;
  state.lockExpiresAt = null;

  const seatLabels = state.selectedSeats
    .slice()
    .sort((a, b) => a.row - b.row || a.number - b.number)
    .map(s => String.fromCharCode(64 + s.row) + s.number)
    .join(', ');
  const isPaid = booking.payment_status === 'paid';
  const paidText = isPaid ? 'Paid' : 'Pending cinema payment';
  let seatTickets = getSeatTicketsForBooking(booking);
  if (!seatTickets.length && state.selectedSeats.length) {
    seatTickets = state.selectedSeats.map((s, index) => {
      const label = seatLabelFromParts(s.row, s.number);
      return { row: s.row, number: s.number, seat: label, ticket_code: booking.ticket_code || '', qr_text: `${buildTicketQrText(booking)}|SEAT=${label}`, index };
    });
  }
  const deadlineText = booking.cash_payment_deadline
    ? new Date(booking.cash_payment_deadline.replace(' ', 'T')).toLocaleString('en-EG', { weekday:'short', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' })
    : 'before the screening starts';

  document.getElementById('booking-body').innerHTML = `
    <div class="text-center py-8">
      <div class="text-6xl mb-4">🎟️</div>
      <h3 class="text-2xl font-black mb-2">${isPaid ? 'Ticket Created!' : 'Reservation Pending Payment'}</h3>
      <p class="text-gray-400 mb-6">${isPaid ? 'Show this QR code at the cinema entrance.' : `Pay at the cinema before ${deadlineText}. The QR ticket becomes usable after admin verifies payment.`}</p>
      <div class="glass rounded-2xl p-6 max-w-sm mx-auto text-left space-y-3 text-sm">
        ${isPaid ? `
          <div class="space-y-4">
            ${seatTickets.map((seat, idx) => `
              <div class="rounded-2xl border border-white/10 bg-black/20 p-3 text-center">
                <div class="text-xs font-black text-brand mb-2">Seat ${esc(seat.seat)}</div>
                <div class="bg-white rounded-xl p-3 mx-auto w-[190px] h-[190px] flex items-center justify-center"><canvas id="ticket-seat-qr-${idx}" width="170" height="170"></canvas></div>
                <div class="text-[10px] text-gray-500 break-all mt-2">${esc(seat.ticket_code || '')}</div>
              </div>`).join('')}
          </div>` : '<div class="rounded-xl p-4 bg-yellow-500/10 border border-yellow-500/20 text-yellow-200 text-center text-xs font-bold">Payment pending - no usable QR yet</div>'}
        <div class="flex justify-between"><span class="text-gray-400">Movie</span><span class="font-bold text-right">${esc(state.selectedMovie.title)}</span></div>
        <div class="flex justify-between"><span class="text-gray-400">Cinema</span><span class="text-right">${esc(state.currentShowtime.cinema_name)}</span></div>
        <div class="flex justify-between"><span class="text-gray-400">Seats</span><span class="text-brand font-bold">${seatLabels}</span></div>
        <div class="flex justify-between"><span class="text-gray-400">Payment</span><span class="font-bold">${paidText}</span></div>
        <div class="flex justify-between border-t border-white/10 pt-3">
          <span class="font-black text-xs uppercase tracking-widest text-gray-500">Total</span>
          <span class="text-2xl font-black">${parseFloat(booking.total_price).toFixed(0)} EGP</span>
        </div>
        <div class="text-center text-[10px] text-gray-500 break-all">${esc(booking.ticket_code)}</div>
      </div>
      <button class="btn-primary mt-8 px-10" onclick="closeBookingModal()">Done</button>
    </div>`;

  if (isPaid) {
    const ok = await ensureQRCodeLibrary();
    if (ok && window.QRCode) {
      seatTickets.forEach((seat, idx) => {
        const canvas = document.getElementById(`ticket-seat-qr-${idx}`);
        if (canvas) QRCode.toCanvas(canvas, seat.qr_text, { width: 170 });
      });
    }
  }
}

/* ============================================================
   12. TRAILER MODAL
   ============================================================ */
function setupTrailerModal() {
  document.getElementById('close-trailer-modal-btn').addEventListener('click', closeTrailerModal);
  document.getElementById('trailer-modal').addEventListener('click', e => {
    if (e.target.id === 'trailer-modal') closeTrailerModal();
  });
}

async function openTrailerModal(movie) {
  document.getElementById('trailer-title').textContent = movie.title;
  const container = document.getElementById('modal-trailer-container');
  container.innerHTML = '<span class="text-gray-500 text-sm animate-pulse">Loading trailer…</span>';
  document.getElementById('trailer-modal').classList.remove('hidden');

  if (!movie.trailerUrl) {
    movie.trailerUrl = await fetchTrailer(movie.tmdbId);
  }
  container.innerHTML = movie.trailerUrl
    ? `<iframe class="w-full h-full" src="${movie.trailerUrl}?autoplay=1" frameborder="0" allowfullscreen></iframe>`
    : '<span class="text-gray-500 text-sm">Trailer not available.</span>';
}

function closeTrailerModal() {
  document.getElementById('trailer-modal').classList.add('hidden');
  document.getElementById('modal-trailer-container').innerHTML = ''; // stop video
}


/* ============================================================
   13. CINEMAS MODAL — accordion by governorate
   ============================================================ */
function setupCinemasModal() {
  document.getElementById('close-cinemas-modal-btn').addEventListener('click', closeCinemasModal);
  document.getElementById('cinemas-back-btn')?.addEventListener('click', cinemasBack);
}

function closeCinemasModal() {
  document.getElementById('cinemas-modal').classList.add('hidden');
  state.cinemaNav = [];
}

/** Persistent back button: pop one step; if none remain, close (→ home). */
function cinemasBack() {
  state.cinemaNav = state.cinemaNav || [];
  state.cinemaNav.pop();                       // drop current step
  const prev = state.cinemaNav[state.cinemaNav.length - 1];
  if (!prev) { closeCinemasModal(); return; }  // nothing left → home
  if (prev.step === 'list') {
    renderCinemaList();
  } else if (prev.step === 'cinema') {
    openCinemaScreen(prev.id, prev.name, prev.location, true);
  }
}

function updateCinemasChrome() {
  const stack = state.cinemaNav || [];
  const backBtn = document.getElementById('cinemas-back-btn');
  const title = document.getElementById('cinemas-modal-title');
  const pills = document.getElementById('cinemas-gov-pills');
  const deeper = stack.length > 1;                 // past the initial list step
  if (backBtn) backBtn.classList.toggle('hidden', !deeper);
  if (pills) pills.classList.toggle('hidden', deeper);
  const top = stack[stack.length - 1];
  if (title) title.textContent = (top && top.step === 'cinema') ? (top.name || 'Cinema') : 'Cinemas';
}

async function openCinemasModal() {
  document.getElementById('cinemas-modal').classList.remove('hidden');
  state.cinemaNav = [{ step: 'list' }];
  await renderCinemaList();
}

async function renderCinemaList() {
  // Ensure the list step is on top of the stack.
  state.cinemaNav = state.cinemaNav && state.cinemaNav.length ? state.cinemaNav : [{ step: 'list' }];
  if (state.cinemaNav[state.cinemaNav.length - 1].step !== 'list') {
    state.cinemaNav.push({ step: 'list' });
  }
  updateCinemasChrome();

  const list  = document.getElementById('cinemas-list');
  list.className = 'grid grid-cols-1 md:grid-cols-2 gap-4';
  list.innerHTML  = '<div class="col-span-full text-center py-12 animate-pulse text-gray-500">Loading cinemas…</div>';

  const [govRes, cinRes] = await Promise.all([api('governorates'), api('cinemas')]);
  if (govRes.status !== 'success' || cinRes.status !== 'success') {
    list.innerHTML = '<p class="text-brand text-sm col-span-full text-center py-8">Could not load cinemas.</p>';
    return;
  }

  const byGov = {};
  cinRes.cinemas.forEach(c => {
    if (!byGov[c.governorate_id]) byGov[c.governorate_id] = [];
    byGov[c.governorate_id].push(c);
  });
  const govs = govRes.governorates.filter(g => byGov[g.id]);

  list.innerHTML = govs.map((gov, gi) => {
    const cinemas = byGov[gov.id] || [];
    const open    = gi === 0;
    return `
      <div class="col-span-full cinema-gov-block rounded-2xl overflow-hidden border border-white/10 mb-2" data-gov-id="${gov.id}">
        <button class="gov-accordion-header w-full flex items-center justify-between px-5 py-4 text-left hover:bg-white/5 transition-colors"
                onclick="toggleAccordion(this)" aria-expanded="${open}">
          <div class="flex items-center gap-3">
            <span class="text-xl">🗺️</span>
            <div>
              <div class="font-black text-sm">${esc(gov.name_en)}</div>
              <div class="text-xs text-gray-500">${cinemas.length} cinema${cinemas.length !== 1 ? 's' : ''}</div>
            </div>
          </div>
          <span class="gov-chevron text-gray-400 text-xl transition-transform duration-300 ${open ? 'rotate-180' : ''}">▾</span>
        </button>
        <div class="gov-accordion-body border-t border-white/10 ${open ? '' : 'hidden'} grid grid-cols-1 md:grid-cols-2 gap-px bg-white/5">
          ${cinemas.map(c => `
            <button type="button" class="cinema-card text-left rounded-none border-0 bg-bg-main p-4 hover:bg-brand/10 transition-colors"
                    data-cinema-card
                    data-cinema-id="${c.id}"
                    data-cinema-name="${esc(c.name)}"
                    data-cinema-location="${esc(c.location || '')}">
              <div class="font-black text-sm mb-1">${esc(c.name)}</div>
              <div class="text-xs text-gray-400 mb-2">${esc(c.location || '—')}</div>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-white/10 text-gray-400">${c.hall_count} hall${c.hall_count !== 1 ? 's' : ''}</span>
              <span class="text-[10px] text-brand font-bold ml-2">View movies →</span>
            </button>`).join('')}
        </div>
      </div>`;
  }).join('');
  attachCinemaCardHandlers(list);
  applyCinemasRegionFilter(document.getElementById('gov-filter')?.value || '');
}

function attachCinemaCardHandlers(root = document) {
  root.querySelectorAll('[data-cinema-card]').forEach(btn => {
    btn.addEventListener('click', () => {
      openCinemaScreen(
        Number(btn.dataset.cinemaId),
        btn.dataset.cinemaName || '',
        btn.dataset.cinemaLocation || ''
      );
    });
  });
}

/** Show the movies (and their showtimes) playing at one cinema. */
async function openCinemaScreen(cinemaId, cinemaName, cinemaLocation, isBack = false) {
  document.getElementById('cinemas-modal').classList.remove('hidden');
  state.cinemaNav = state.cinemaNav || [{ step: 'list' }];
  if (!isBack) {
    state.cinemaNav.push({ step: 'cinema', id: cinemaId, name: cinemaName, location: cinemaLocation });
  }
  updateCinemasChrome();

  const list = document.getElementById('cinemas-list');
  list.className = 'block';
  list.innerHTML = '<div class="text-center py-12 animate-pulse text-gray-500">Loading movies…</div>';

  const res = await api('cinema_movies', { cinema_id: cinemaId });
  if (res.status !== 'success') {
    list.innerHTML = '<p class="text-brand text-sm text-center py-8">Could not load this cinema.</p>';
    return;
  }

  const movies = res.movies || [];
  const header = `
    <div class="mb-6">
      <div class="font-black text-base">${esc(cinemaName)}</div>
      <div class="text-xs text-gray-500">${esc(cinemaLocation || res.cinema?.location || '')}</div>
    </div>`;

  if (!movies.length) {
    list.innerHTML = header + `
      <div class="text-center py-12">
        <div class="text-5xl mb-4">🎬</div>
        <p class="text-gray-400">No movies are currently showing at this cinema.</p>
      </div>`;
    return;
  }

  list.innerHTML = header + '<div class="flex flex-col gap-4">' + movies.map(m => {
    const byDate = {};
    (m.showtimes || []).forEach(s => {
      const date = s.show_datetime.split(' ')[0];
      (byDate[date] = byDate[date] || []).push(s);
    });
    const times = Object.keys(byDate).map(date => `
      <div class="mt-2">
        <div class="text-[10px] uppercase tracking-widest text-gray-500 font-black mb-1">${fmtDate(date)}</div>
        <div class="flex flex-wrap gap-2">
          ${byDate[date].map(s => `
            <button class="showtime-btn"
                    data-cinema-showtime
                    data-showtime-id="${s.showtime_id}"
                    data-tmdb-id="${m.tmdb_movie_id}"
                    data-movie-title="${esc(m.movie_title || '')}"
                    data-movie-poster="${esc(m.movie_poster || '')}"
                    data-cinema-id="${cinemaId}"
                    data-cinema-name="${esc(cinemaName)}"
                    data-cinema-location="${esc(cinemaLocation || '')}">
              <span class="time">${s.show_datetime.split(' ')[1].slice(0,5)}</span>
              <span class="hall">${esc(s.hall_name)}</span>
              <span class="meta">${esc((s.hall_type||'').toUpperCase())}</span>
              <span class="price">${parseFloat(s.price).toFixed(0)} EGP</span>
            </button>`).join('')}
        </div>
      </div>`).join('');
    return `
      <div class="glass rounded-2xl flex gap-4 p-4">
        <img src="${esc(m.movie_poster || '')}" alt="${esc(m.movie_title)}"
             class="w-16 rounded-xl object-cover shrink-0" style="aspect-ratio:2/3"
             onerror="this.style.display='none'" />
        <div class="flex-1 min-w-0">
          <div class="font-black text-sm mb-1">${esc(m.movie_title)}</div>
          ${times || '<div class="text-xs text-gray-500">No upcoming showtimes.</div>'}
        </div>
      </div>`;
  }).join('') + '</div>';
  attachCinemaShowtimeHandlers(list);
}

function attachCinemaShowtimeHandlers(root = document) {
  root.querySelectorAll('[data-cinema-showtime]').forEach(btn => {
    btn.addEventListener('click', () => {
      bookFromCinema(
        Number(btn.dataset.showtimeId),
        Number(btn.dataset.tmdbId),
        btn.dataset.movieTitle || '',
        btn.dataset.moviePoster || '',
        Number(btn.dataset.cinemaId),
        btn.dataset.cinemaName || '',
        btn.dataset.cinemaLocation || ''
      );
    });
  });
}

/** A showtime was picked from the cinema screen → open the seat map. */
function bookFromCinema(showtimeId, tmdbId, title, poster, cinemaId, cinemaName, cinemaLocation) {
  if (!state.isLoggedIn) { showAuthModal('login'); return; }
  state.selectedMovie  = { tmdbId, title, poster };
  state.selectedCinema = { id: cinemaId, name: cinemaName, location: cinemaLocation };
  document.getElementById('cinemas-modal').classList.add('hidden');
  document.getElementById('booking-title').textContent = title;
  document.getElementById('booking-modal').classList.remove('hidden');
  selectShowtime(showtimeId);
}


/* ============================================================
   14. MY BOOKINGS MODAL
   ============================================================ */
function setupMyBookingsModal() {
  document.getElementById('close-bookings-modal-btn').addEventListener('click', () => {
    document.getElementById('my-bookings-modal').classList.add('hidden');
  });
}

async function openMyBookingsModal() {
  document.getElementById('my-bookings-modal').classList.remove('hidden');
  await loadBookingsInto('my-bookings-list');
}

/** Reload whichever booking lists are currently on screen (modal + account page). */
async function refreshBookingLists() {
  if (!document.getElementById('my-bookings-modal')?.classList.contains('hidden')) {
    await loadBookingsInto('my-bookings-list');
  }
  if (!document.getElementById('account-modal')?.classList.contains('hidden')) {
    await loadBookingsInto('account-bookings-list');
  }
}

async function loadBookingsInto(targetId) {
  const list = document.getElementById(targetId);
  if (!list) return;
  list.innerHTML = '<div class="text-center py-8 animate-pulse text-gray-500">Loading…</div>';

  const res = await api('my_bookings');
  if (res.status !== 'success') {
    list.innerHTML = '<p class="text-brand text-sm text-center py-8">Please sign in to view bookings.</p>';
    return;
  }
  if (!res.bookings.length) {
    list.innerHTML = `<div class="text-center py-12"><div class="text-5xl mb-4">🎟️</div><p class="text-gray-400">No bookings yet.</p></div>`;
    return;
  }
  state.bookingCache = {};
  list.innerHTML = res.bookings.map(renderBookingCard).join('');
}

function renderBookingCard(b) {
    state.bookingCache[b.id] = b;
    const dt     = new Date(b.show_datetime.replace(' ', 'T'));
    const isPast = dt < new Date();
    const activeSeats = b.seats || [];
    const archivedSeats = b.cancelled_seats || [];
    const bookingSeats = activeSeats.length ? activeSeats : archivedSeats;
    const seats  = bookingSeats.map(s => String.fromCharCode(64 + parseInt(s.seat_row)) + s.seat_number).join(', ');
    const payClass = b.payment_status === 'paid' ? 'text-green-400' : (b.payment_status === 'pending' ? 'text-yellow-400' : 'text-red-400');
    const ticketDisplay = b.payment_status === 'paid' ? (b.ticket_status || '—') : 'waiting payment';
    const canViewTicket = b.ticket_code && b.payment_status === 'paid';
    const canCancel = !isPast && b.status === 'confirmed' && b.ticket_status !== 'used';
    const hasPendingRefund = Number(b.pending_refund_count || 0) > 0;
    const canRequestRefund = canCancel && b.payment_status === 'paid' && !hasPendingRefund;
    const canCancelWithoutPayment = canCancel && b.payment_status !== 'paid';
    const cashPaidNotice = '';
    const seatCancelControls = canCancel && bookingSeats.length ? `
      <div class="rounded-xl border border-white/10 bg-black/20 p-3 mb-3">
        <div class="flex items-center justify-between gap-3 mb-2">
          <div class="text-[10px] uppercase tracking-widest text-gray-500 font-black">${b.payment_status === 'paid' ? 'Choose seats to refund' : 'Choose seats to cancel'}</div>
          <button class="text-[10px] text-gray-400 hover:text-white font-bold" onclick="selectAllCancelSeats(${b.id})">Select all</button>
        </div>
        <div class="flex flex-wrap gap-2">
          ${bookingSeats.map(s => {
            const row = parseInt(s.seat_row);
            const num = parseInt(s.seat_number);
            const label = String.fromCharCode(64 + row) + num;
            const used = (s.seat_ticket_status || '') === 'used';
            return `
              <label class="seat-cancel-chip ${used ? 'opacity-50 cursor-not-allowed' : ''}" title="${used ? 'Seat already used' : ''}">
                <input type="checkbox" class="cancel-seat-${b.id}" data-row="${row}" data-number="${num}" ${used ? 'disabled' : ''}>
                <span>${esc(label)}${used ? ' used' : ''}</span>
              </label>`;
          }).join('')}
        </div>
      </div>` : '';
    return `
      <div class="glass rounded-2xl flex gap-5 mb-4 p-4">
        <img src="${esc(b.movie_poster || '')}" alt="${esc(b.movie_title)}"
             class="w-24 sm:w-28 rounded-xl object-cover shrink-0 border border-white/10" style="aspect-ratio:2/3"
             onerror="this.style.display='none'" />
        <div class="flex-1 min-w-0 pl-1">
          <div class="flex justify-between items-start gap-2 mb-1 flex-wrap">
            <span class="font-black text-sm">${esc(b.movie_title)}</span>
            <span class="text-[10px] font-bold px-2 py-1 rounded-full flex-shrink-0 ${isPast ? 'bg-white/10 text-gray-400' : 'bg-brand/20 text-brand'}">${isPast ? 'Past' : 'Upcoming'}</span>
          </div>
          <div class="text-xs text-gray-400 mb-1">${esc(b.cinema_name)} · ${esc(b.hall_name)}</div>
          <div class="text-xs text-gray-500 mb-1">${dt.toLocaleString('en-EG',{weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'})}</div>
          <div class="text-xs text-brand font-bold mb-1">Seats: ${seats || '–'}</div>
          <div class="text-xs mb-1">Payment: <span class="font-bold ${payClass}">${esc(b.payment_status || 'unknown')}</span> · Ticket: ${esc(ticketDisplay)}</div>
          <div class="text-xs font-black mb-3">${parseFloat(b.total_price).toFixed(0)} EGP <span class="text-gray-600 font-normal">#${b.id}</span></div>
          ${seatCancelControls}
          <div class="flex flex-wrap gap-2">
            ${canViewTicket ? `<button class="btn-secondary px-3 py-2 text-[10px]" onclick="viewSavedTicketByBooking(${Number(b.id)})">View Ticket</button>` : (b.payment_status === 'pending' ? '<span class="text-[10px] text-yellow-300 font-bold">QR after payment verification</span>' : '')}
            ${hasPendingRefund ? '<span class="text-[10px] text-yellow-300 font-bold">Refund request awaiting regional admin</span>' : ''}
            ${cashPaidNotice}
            ${canRequestRefund ? `<button class="btn-secondary px-3 py-2 text-[10px] text-red-300" onclick="requestRefundForSelectedSeats(${b.id})">Request Refund</button>` : ''}
            ${canCancelWithoutPayment ? `<button class="btn-secondary px-3 py-2 text-[10px] text-red-300" onclick="cancelSelectedBookingSeats(${b.id})">Cancel Reservation</button>` : ''}
          </div>
        </div>
      </div>`;
}

function viewSavedTicketByBooking(bookingId) {
  const b = state.bookingCache?.[bookingId];
  if (!b || !b.ticket_code) return;
  const bookingSeats = (b.seats && b.seats.length) ? b.seats : (b.cancelled_seats || []);
  const seats = bookingSeats.map(s => String.fromCharCode(64 + parseInt(s.seat_row)) + s.seat_number).join(', ');
  viewSavedTicket(b, seats);
}

function selectAllCancelSeats(bookingId) {
  document.querySelectorAll(`.cancel-seat-${bookingId}:not(:disabled)`).forEach(input => {
    input.checked = true;
  });
}

async function cancelSelectedBookingSeats(bookingId) {
  const checked = Array.from(document.querySelectorAll(`.cancel-seat-${bookingId}:checked:not(:disabled)`));
  if (!checked.length) {
    alert('Select the seat(s) you want to cancel first.');
    return;
  }

  const seats = checked.map(input => ({
    row: parseInt(input.dataset.row),
    number: parseInt(input.dataset.number),
  }));

  const labels = seats.map(seat => String.fromCharCode(64 + seat.row) + seat.number).join(', ');
  const allSeatsCount = document.querySelectorAll(`.cancel-seat-${bookingId}:not(:disabled)`).length;
  const wholeReservation = checked.length === allSeatsCount;
  const confirmText = wholeReservation
    ? `Cancel the whole reservation (${labels})?`
    : `Cancel selected seat(s): ${labels}?`;
  if (!confirm(confirmText)) return;

  const res = await api('cancel_booking_seats', { booking_id: bookingId, seats }, 'POST');
  alert(res.message || (res.status === "success" ? "Reservation updated." : "Could not cancel selected seats."));
  refreshBookingLists();
}

async function requestRefundForSelectedSeats(bookingId) {
  const checked = Array.from(document.querySelectorAll(`.cancel-seat-${bookingId}:checked:not(:disabled)`));
  if (!checked.length) {
    alert('Select the seat(s) you want to refund first.');
    return;
  }

  const seats = checked.map(input => ({
    row: parseInt(input.dataset.row),
    number: parseInt(input.dataset.number),
  }));

  const labels = seats.map(seat => String.fromCharCode(64 + seat.row) + seat.number).join(', ');
  if (!confirm(`Request refund for selected seat(s): ${labels}?`)) return;

  const res = await api('request_refund', { booking_id: bookingId, seats }, 'POST');
  if (res.status === 'success') {
    showRefundAwaitingScreen(res.message || 'Refund request sent. Awaiting regional admin approval.', res.request_id);
  } else {
    alert(res.message || "Could not request refund.");
  }
  refreshBookingLists();
}

let refundStatusPollTimer = null;

function showRefundAwaitingScreen(message, requestId = null) {
  document.querySelector('.refund-awaiting-screen')?.remove();
  if (refundStatusPollTimer) { clearInterval(refundStatusPollTimer); refundStatusPollTimer = null; }

  const box = document.createElement('div');
  box.className = 'refund-awaiting-screen fixed inset-0 z-[130] bg-[#0A0A0B]/90 backdrop-blur-xl flex items-center justify-center p-4';
  box.innerHTML = `
    <div class="glass rounded-3xl p-8 max-w-md w-full text-center">
      <div class="w-14 h-14 border-4 border-brand/30 border-t-brand rounded-full animate-spin mx-auto mb-5"></div>
      <h3 class="text-2xl font-black uppercase tracking-tighter mb-3">Awaiting Admin Review</h3>
      <p class="text-sm text-gray-300">${esc(message)}</p>
      <button class="btn-primary py-3 px-6 mt-6" onclick="acknowledgeRefundPending()">OK</button>
    </div>`;
  document.body.appendChild(box);

  if (requestId) startRefundStatusPolling(requestId, box);
}

function closeRefundAwaitingScreen() {
  if (refundStatusPollTimer) { clearInterval(refundStatusPollTimer); refundStatusPollTimer = null; }
  document.querySelector('.refund-awaiting-screen')?.remove();
}

/** When the user clicks OK while the request is still pending, confirm that
 *  closing won't cancel the request — it stays open and they'll be notified. */
function acknowledgeRefundPending() {
  const box = document.querySelector('.refund-awaiting-screen');
  if (!box) return;
  if (refundStatusPollTimer) { clearInterval(refundStatusPollTimer); refundStatusPollTimer = null; }
  box.innerHTML = `
    <div class="glass rounded-3xl p-8 max-w-md w-full text-center">
      <div class="text-5xl mb-4">⏳</div>
      <h3 class="text-2xl font-black uppercase tracking-tighter mb-3">Request Still Pending</h3>
      <p class="text-sm text-gray-300">Your refund request has <span class="text-yellow-300 font-bold">not been approved yet</span>. It's waiting for the regional admin to review it. You'll get a notification once it's approved or rejected, and you can track it under <span class="font-bold">My Requests</span> and <span class="font-bold">My Bookings</span>.</p>
      <button class="btn-primary py-3 px-6 mt-6" onclick="closeRefundAwaitingScreen()">Got it</button>
    </div>`;
}

/** While the awaiting screen is open, check every few seconds whether the
 *  regional admin has approved or rejected this refund request, then update
 *  the screen live so the user sees the decision without reloading. */
function startRefundStatusPolling(requestId, box) {
  const tick = async () => {
    // Stop if the user already closed the screen.
    if (!document.body.contains(box)) {
      if (refundStatusPollTimer) { clearInterval(refundStatusPollTimer); refundStatusPollTimer = null; }
      return;
    }
    let res;
    try {
      res = await api('refund_request_status', { request_id: requestId });
    } catch (e) {
      return; // transient error, keep waiting
    }
    if (!res || res.status !== 'success' || !res.request) return;

    const decision = res.request.status;
    if (decision === 'approved' || decision === 'rejected') {
      if (refundStatusPollTimer) { clearInterval(refundStatusPollTimer); refundStatusPollTimer = null; }
      renderRefundResultScreen(box, decision, res.request.refund_amount);
      refreshBookingLists(); // refresh the list behind the overlay
    }
  };

  refundStatusPollTimer = setInterval(tick, 4000);
  tick(); // also check right away
}

function renderRefundResultScreen(box, decision, amount) {
  const approved = decision === 'approved';
  const amt = Number(amount || 0);
  box.innerHTML = `
    <div class="glass rounded-3xl p-8 max-w-md w-full text-center">
      <div class="text-5xl mb-4">${approved ? '✅' : '❌'}</div>
      <h3 class="text-2xl font-black uppercase tracking-tighter mb-3">${approved ? 'Refund Approved' : 'Refund Rejected'}</h3>
      <p class="text-sm text-gray-300">${approved
        ? `Your refund has been approved by the regional admin.${amt > 0 ? ` Amount refunded: ${amt.toFixed(0)} EGP.` : ''}`
        : 'Your refund request was rejected by the regional admin.'}</p>
      <button class="btn-primary py-3 px-6 mt-6" onclick="closeRefundAwaitingScreen()">OK</button>
    </div>`;
}

async function viewSavedTicket(booking, seats) {
  const seatTickets = getSeatTicketsForBooking(booking);
  const box = document.createElement('div');
  box.className = 'fixed inset-0 z-[120] bg-black/80 flex items-center justify-center p-4 overflow-y-auto';
  box.innerHTML = `
    <div class="glass rounded-3xl p-6 max-w-sm w-full text-center relative my-8">
      <button class="absolute top-3 right-4 text-gray-400 hover:text-white text-xl" onclick="this.closest('.fixed').remove()">×</button>
      <h3 class="text-xl font-black mb-2">${esc(booking.movie_title || 'CINEMAX Ticket')}</h3>
      <p class="text-xs text-gray-500 mb-1">Booking #${esc(booking.id || booking.booking_id || '')}</p>
      <p class="text-xs text-gray-500 mb-4">Each reserved seat has its own QR code.</p>
      <div class="space-y-4">
        ${seatTickets.map((seat, idx) => `
          <div class="rounded-2xl border border-white/10 bg-black/20 p-3">
            <div class="text-sm font-black text-brand mb-2">Seat ${esc(seat.seat)}</div>
            <div class="bg-white rounded-xl p-3 mx-auto w-[190px] h-[190px] flex items-center justify-center"><canvas id="saved-ticket-seat-qr-${idx}" width="170" height="170"></canvas></div>
            <p class="text-[10px] text-gray-500 mt-3 break-all">${esc(seat.ticket_code || '')}</p>
          </div>`).join('')}
      </div>
    </div>`;
  document.body.appendChild(box);
  const ok = await ensureQRCodeLibrary();
  if (ok && window.QRCode) {
    seatTickets.forEach((seat, idx) => {
      const canvas = document.getElementById(`saved-ticket-seat-qr-${idx}`);
      if (canvas) QRCode.toCanvas(canvas, seat.qr_text, { width: 170 });
    });
  }
}



/* ============================================================
   15. AUTH — LOGIN & REGISTER MODALS
   ============================================================ */
function setupAuthModals() {
  document.getElementById('close-login-modal-btn').addEventListener('click', () =>
    document.getElementById('login-modal').classList.add('hidden'));
  document.getElementById('close-register-modal-btn').addEventListener('click', () =>
    document.getElementById('register-modal').classList.add('hidden'));
  document.getElementById('close-account-modal-btn')?.addEventListener('click', () =>
    document.getElementById('account-modal').classList.add('hidden'));
  document.getElementById('account-logout-start-btn')?.addEventListener('click', openLogoutConfirmModal);
  document.getElementById('cancel-logout-btn')?.addEventListener('click', closeLogoutConfirmModal);
  document.getElementById('confirm-logout-btn')?.addEventListener('click', logout);
  document.getElementById('entry-guest-btn')?.addEventListener('click', hideEntryChoiceModal);
  document.getElementById('entry-register-btn')?.addEventListener('click', () => {
    hideEntryChoiceModal();
    showAuthModal('register');
  });
  document.getElementById('entry-login-btn')?.addEventListener('click', () => {
    hideEntryChoiceModal();
    showAuthModal('login', 'user');
  });

  document.getElementById('switch-to-register-btn').addEventListener('click', () => {
    document.getElementById('login-modal').classList.add('hidden');
    showAuthModal('register');
  });
  document.getElementById('switch-to-login-btn').addEventListener('click', () => {
    document.getElementById('register-modal').classList.add('hidden');
    showAuthModal('login');
  });

  document.getElementById('login-submit-btn').addEventListener('click', submitLogin);
  document.getElementById('forgot-password-open-btn')?.addEventListener('click', openForgotPasswordModal);
  document.getElementById('close-forgot-password-modal-btn')?.addEventListener('click', closeForgotPasswordModal);
  document.getElementById('forgot-send-code-btn')?.addEventListener('click', () => requestForgotPassword('change'));
  document.getElementById('forgot-remember-btn')?.addEventListener('click', () => requestForgotPassword('remember'));
  document.getElementById('forgot-reset-btn')?.addEventListener('click', resetPasswordWithCode);

  const forgotCodeInput = document.getElementById('forgot-code');
  if (forgotCodeInput) {
    forgotCodeInput.addEventListener('input', () => {
      // Mobile-friendly: keep only numbers and stop at 6 digits.
      forgotCodeInput.value = forgotCodeInput.value.replace(/\D/g, '').slice(0, 6);
    });
    forgotCodeInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        document.getElementById('forgot-new-password')?.focus();
      }
    });
  }

  const forgotNewPasswordInput = document.getElementById('forgot-new-password');
  if (forgotNewPasswordInput) {
    forgotNewPasswordInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        resetPasswordWithCode();
      }
    });
  }

  document.querySelectorAll('input[name="forgot-delivery-channel"]').forEach(radio => {
    radio.addEventListener('change', updateForgotDeliveryUI);
  });
  document.getElementById('login-password').addEventListener('keydown', e => {
    if (e.key === 'Enter') submitLogin();
  });
  document.getElementById('register-submit-btn').addEventListener('click', submitRegister);
}

function showEntryChoiceModal() {
  if (state.isLoggedIn) return;
  const modal = document.getElementById('entry-choice-modal');
  if (!modal) return;
  modal.classList.remove('hidden');
  modal.classList.add('flex');
}

function hideEntryChoiceModal() {
  const modal = document.getElementById('entry-choice-modal');
  if (!modal) return;
  modal.classList.add('hidden');
  modal.classList.remove('flex');
}

function setLoginTarget(target) {
  state.loginTarget = target === 'admin' ? 'admin' : 'user';
  document.querySelectorAll('.login-target-btn').forEach(btn => {
    const active = btn.dataset.loginTarget === state.loginTarget;
    btn.classList.toggle('bg-brand', active);
    btn.classList.toggle('border-brand', active);
    btn.classList.toggle('text-white', active);
  });
}

function showAuthModal(type, loginTarget = null) {
  clearAuthForms();
  hideEntryChoiceModal();
  document.getElementById('login-modal').classList.add('hidden');
  document.getElementById('register-modal').classList.add('hidden');
  document.getElementById('account-modal')?.classList.add('hidden');
  document.getElementById('forgot-password-modal')?.classList.add('hidden');
  document.getElementById('logout-confirm-modal')?.classList.add('hidden');
  if (type === 'login') setLoginTarget(loginTarget || 'user');
  document.getElementById(`${type}-modal`).classList.remove('hidden');
}

function clearAuthForms() {
  ['login-email', 'login-password', 'reg-username', 'reg-email', 'reg-password', 'forgot-email', 'forgot-code', 'forgot-new-password'].forEach(id => {
    const input = document.getElementById(id);
    if (input) input.value = '';
  });
  ['login-error', 'register-error', 'forgot-password-msg'].forEach(id => {
    const box = document.getElementById(id);
    if (box) {
      box.textContent = '';
      box.classList.add('hidden');
    }
  });
}

function openAccountModal() {
  if (!state.isLoggedIn) {
    showAuthModal('login');
    return;
  }

  document.getElementById('login-modal')?.classList.add('hidden');
  document.getElementById('register-modal')?.classList.add('hidden');
  document.getElementById('logout-confirm-modal')?.classList.add('hidden');

  const username = document.getElementById('account-username');
  const email = document.getElementById('account-email');
  if (username) username.textContent = state.username || 'User';
  if (email) email.textContent = state.userEmail || 'No email loaded';

  document.getElementById('account-modal')?.classList.remove('hidden');
  loadBookingsInto('account-bookings-list');
}

function openLogoutConfirmModal() {
  document.getElementById('logout-confirm-modal')?.classList.remove('hidden');
}

function closeLogoutConfirmModal() {
  document.getElementById('logout-confirm-modal')?.classList.add('hidden');
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
  if (!match) return false;
  return ALLOWED_PUBLIC_EMAIL_DOMAINS.has(match[1]);
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



function openForgotPasswordModal() {
  document.getElementById('login-modal')?.classList.add('hidden');
  document.getElementById('forgot-password-modal')?.classList.remove('hidden');
  updateForgotDeliveryUI();
}

function closeForgotPasswordModal() {
  document.getElementById('forgot-password-modal')?.classList.add('hidden');
}

function setForgotPasswordMessage(text, ok = false) {
  const box = document.getElementById('forgot-password-msg');
  if (!box) return;
  box.textContent = text;
  box.classList.remove('hidden', 'text-brand', 'text-green-300');
  box.classList.add(ok ? 'text-green-300' : 'text-brand');
}

function updateForgotDeliveryUI() {
  const channel = document.querySelector('input[name="forgot-delivery-channel"]:checked')?.value || 'email';
  const phoneWrap = document.getElementById('forgot-phone-wrap');
  if (phoneWrap) phoneWrap.classList.toggle('hidden', channel !== 'phone');
}

function normalizePhoneInput(phone) {
  return String(phone || '').trim().replace(/[^0-9+]/g, '').replace(/^00/, '+');
}

function getForgotDeliveryData() {
  const deliveryChannel = document.querySelector('input[name="forgot-delivery-channel"]:checked')?.value || 'email';
  const phone = normalizePhoneInput(document.getElementById('forgot-phone')?.value || '');
  return { deliveryChannel, phone };
}

async function requestForgotPassword(mode) {
  const email = document.getElementById('forgot-email')?.value.trim() || '';
  const { deliveryChannel, phone } = getForgotDeliveryData();
  if (!isAllowedPublicEmail(email)) {
    setForgotPasswordMessage(realEmailMessage());
    return;
  }
  if (deliveryChannel === 'phone' && !/^\+?[0-9]{10,15}$/.test(phone)) {
    setForgotPasswordMessage('Enter a valid phone number for WhatsApp/SMS, for example +201001234567.');
    return;
  }
  const btn = mode === 'change' ? document.getElementById('forgot-send-code-btn') : document.getElementById('forgot-remember-btn');
  if (btn) { btn.disabled = true; btn.dataset.oldText = btn.textContent; btn.textContent = 'Processing...'; }
  try {
    const res = await api('forgot_password_request', { email, mode, delivery_channel: deliveryChannel, phone }, 'POST');
    let msg = res.message || 'Request processed.';
    if (res.debug_code) msg += ` Local test code: ${res.debug_code}`;
    setForgotPasswordMessage(msg, res.status === 'success');
    if (res.status === 'success' && mode === 'change') {
      setTimeout(() => {
        const codeInput = document.getElementById('forgot-code');
        const resetArea = document.getElementById('forgot-reset-area');
        resetArea?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        codeInput?.focus({ preventScroll: true });
      }, 250);
    }
  } catch (e) {
    setForgotPasswordMessage('Connection error. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = btn.dataset.oldText || (mode === 'change' ? 'Send Code to Change Password' : 'Ask Admin for Password Help'); delete btn.dataset.oldText; }
  }
}

async function resetPasswordWithCode() {
  const email = document.getElementById('forgot-email')?.value.trim() || '';
  const code = (document.getElementById('forgot-code')?.value || '').replace(/\D/g, '').slice(0, 6);
  const newPassword = document.getElementById('forgot-new-password')?.value || '';
  if (!isAllowedPublicEmail(email)) { setForgotPasswordMessage(realEmailMessage()); return; }
  if (!/^\d{6}$/.test(code)) { setForgotPasswordMessage('Enter the 6-digit verification code.'); return; }
  if (!isStrongPassword(newPassword)) { setForgotPasswordMessage(passwordPolicyMessage('New password')); return; }
  try {
    const res = await api('reset_password_with_code', { email, code, new_password: newPassword }, 'POST');
    setForgotPasswordMessage(res.message || 'Password reset processed.', res.status === 'success');
    if (res.status === 'success') {
      setTimeout(() => { closeForgotPasswordModal(); showAuthModal('login'); }, 1200);
    }
  } catch (e) {
    setForgotPasswordMessage('Connection error. Please try again.');
  }
}

async function submitLogin() {
  const email    = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;
  const errDiv   = document.getElementById('login-error');
  const modal    = document.getElementById('login-modal');
  const btn      = document.getElementById('login-submit-btn');

  errDiv.classList.add('hidden');

  if (!email || !password) {
    errDiv.textContent = 'Please enter your email and password.';
    errDiv.classList.remove('hidden');
    return;
  }

  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errDiv.textContent = 'Please enter a valid email address.';
    errDiv.classList.remove('hidden');
    return;
  }

  // Hide the sign-in window immediately after the user confirms.
  // This removes the visible lag while the backend checks the account.
  modal?.classList.add('hidden');
  if (btn) {
    btn.disabled = true;
    btn.dataset.originalText = btn.textContent || 'Sign In';
    btn.textContent = 'Signing in...';
  }

  try {
    const res = await api('login', { email, password }, 'POST');

    if (res.status === 'success') {
      await loadCsrfToken();

      state.isLoggedIn = true;
      state.username   = res.user.username;
      state.userEmail  = res.user.email || email;
      state.userId     = res.user.id;
      state.userRole   = res.user.role || 'user';

      clearAuthForms();
      updateNavForAuth();
      setupCommentForm();
      checkAndShowNotificationsPopup();
      startUserLiveSync();
    } else {
      // If login fails, reopen the modal and show the error.
      modal?.classList.remove('hidden');
      errDiv.textContent = res.message || 'Login failed. Please check your details.';
      errDiv.classList.remove('hidden');
    }
  } catch (error) {
    modal?.classList.remove('hidden');
    errDiv.textContent = 'Connection error. Please try again.';
    errDiv.classList.remove('hidden');
  } finally {
    if (btn) {
      btn.disabled = false;
      btn.textContent = btn.dataset.originalText || 'Sign In';
      delete btn.dataset.originalText;
    }
  }
}
async function submitRegister() {
  const username = document.getElementById('reg-username').value.trim();
  const email    = document.getElementById('reg-email').value.trim();
  const password = document.getElementById('reg-password').value;
  const errDiv   = document.getElementById('register-error');
  errDiv.classList.add('hidden');

  if (!isAllowedPublicEmail(email)) {
    errDiv.textContent = realEmailMessage();
    errDiv.classList.remove('hidden');
    return;
  }

  if (!isStrongPassword(password)) {
    errDiv.textContent = passwordPolicyMessage('Password');
    errDiv.classList.remove('hidden');
    return;
  }

  const res = await api('register', { username, email, password }, 'POST');

  if (res.status === 'success') {
    await loadCsrfToken();

    state.isLoggedIn = true;
    state.username   = res.user.username;
    state.userEmail  = res.user.email || email;
    state.userId     = res.user.id;
    state.userRole   = res.user.role || 'user';

    document.getElementById('register-modal').classList.add('hidden');
    clearAuthForms();
    updateNavForAuth();
    setupCommentForm();
    checkAndShowNotificationsPopup();
    startUserLiveSync();
  } else {
    errDiv.textContent = res.message;
    errDiv.classList.remove('hidden');
  }
}
async function logout() {
  await api('logout', {}, 'POST');

  state.isLoggedIn = false;
  state.username   = null;
  state.userEmail  = null;
  state.userId     = null;
  state.userRole   = 'user';
  state.csrfToken  = null;

  await loadCsrfToken();

  clearAuthForms();
  document.getElementById('account-modal')?.classList.add('hidden');
  document.getElementById('forgot-password-modal')?.classList.add('hidden');
  document.getElementById('logout-confirm-modal')?.classList.add('hidden');
  document.getElementById('notif-popup-modal')?.classList.add('hidden');
  document.querySelector('.refund-awaiting-screen')?.remove();
  stopUserLiveSync();
  updateNavForAuth();
}
/** Updates the Sign In button to show username when logged in. */
function updateNavForAuth() {
  const btn        = document.getElementById('login-btn');
  const bookingsBtn = document.getElementById('nav-payments-btn'); // labelled "My Bookings"
  const requestsBtn = document.getElementById('nav-requests-btn');
  if (state.isLoggedIn) {
    const accountName = state.username || 'User';
    btn.textContent = `Account: ${accountName}`;
    btn.title = `Account: ${accountName}`;
    btn.classList.add('is-account-btn');
    btn.onclick     = openAccountModal;
    if (bookingsBtn) bookingsBtn.classList.remove('hidden');
    if (requestsBtn) requestsBtn.classList.remove('hidden');
  } else {
    btn.textContent = 'Sign In';
    btn.title = 'Sign In';
    btn.classList.remove('is-account-btn');
    btn.onclick     = () => showAuthModal('login');
    if (bookingsBtn) bookingsBtn.classList.add('hidden');
    if (requestsBtn) requestsBtn.classList.add('hidden');
    stopUserLiveSync();
  }
}


/* ============================================================
   16. COMMENTS & REVIEWS
   ============================================================ */
function setupCommentForm() {
  const formBlock   = document.getElementById('comment-form-block');
  const loginPrompt = document.getElementById('comment-login-prompt');
  if (!formBlock) return;

  if (state.isLoggedIn) {
    formBlock.classList.remove('hidden');
    loginPrompt?.classList.add('hidden');
  } else {
    formBlock.classList.add('hidden');
    loginPrompt?.classList.remove('hidden');
  }

  // Star picker
  document.querySelectorAll('.star-btn').forEach(star => {
    star.addEventListener('click', () => {
      const val = parseInt(star.dataset.v);
      state.selectedRating = val;
      document.querySelectorAll('.star-btn').forEach((s, i) => {
        s.style.color = i < val ? '#facc15' : '#4b5563';
      });
    });
  });

  const submitBtn = document.getElementById('submit-comment-btn');
  if (submitBtn) {
    submitBtn.onclick = submitComment;
  }
}

async function loadComments() {
  if (!state.selectedMovie) return;
  state.commentOffset = 0;
  state.commentTotal = 0;
  state.selectedRating = 0;
  document.querySelectorAll('.star-btn').forEach(s => s.style.color = '#4b5563');

  const avgEl = document.getElementById('community-avg');
  const starsEl = document.getElementById('community-stars');
  const countEl = document.getElementById('community-count');
  if (avgEl) avgEl.textContent = '–';
  if (starsEl) starsEl.textContent = '';
  if (countEl) countEl.textContent = 'No reviews yet';

  const res = await api('get_comments', {
    tmdb_id: state.selectedMovie.tmdbId,
    offset:  0,
    limit:   5,
  });
  if (res.status !== 'success') return;

  state.commentTotal = res.total;
  const list = document.getElementById('comments-list');
  if (!list) return;

  if (!res.comments.length) {
    list.innerHTML = '<p class="text-gray-600 text-sm">No reviews yet. Be the first!</p>';
    document.getElementById('load-more-comments-btn')?.classList.add('hidden');
    return;
  }

  list.innerHTML = res.comments.map(commentHTML).join('');
  state.commentOffset = res.comments.length;

  const loadMoreBtn = document.getElementById('load-more-comments-btn');
  if (loadMoreBtn) {
    loadMoreBtn.classList.toggle('hidden', state.commentOffset >= state.commentTotal);
  }

  // Update community score
  if (res.avg_rating) {
    if (avgEl) avgEl.textContent = res.avg_rating;
    if (starsEl) starsEl.textContent = starsHTML(res.avg_rating);
    if (countEl) countEl.textContent = `${state.commentTotal} review${state.commentTotal !== 1 ? 's' : ''}`;
  }
}

async function loadMoreComments() {
  if (!state.selectedMovie) return;
  const res = await api('get_comments', {
    tmdb_id: state.selectedMovie.tmdbId,
    offset:  state.commentOffset,
    limit:   5,
  });
  if (res.status !== 'success') return;

  const list = document.getElementById('comments-list');
  list.insertAdjacentHTML('beforeend', res.comments.map(commentHTML).join(''));
  state.commentOffset += res.comments.length;
  document.getElementById('load-more-comments-btn')?.classList.toggle('hidden', state.commentOffset >= state.commentTotal);
}

async function submitComment() {
  if (!state.selectedMovie || !state.isLoggedIn) return;
  const body   = document.getElementById('comment-body').value.trim();
  const rating = state.selectedRating || null;
  if (!body) return;

  const res = await api('add_comment', {
    tmdb_id: state.selectedMovie.tmdbId,
    governorate_id: document.getElementById('comment-governorate')?.value || document.getElementById('gov-filter')?.value || '',
    cinema_id: document.getElementById('comment-cinema')?.value || '',
    body,
    rating,
  }, 'POST');

  if (res.status === 'success') {
    document.getElementById('comment-body').value = '';
    state.selectedRating = 0;
    document.querySelectorAll('.star-btn').forEach(s => s.style.color = '#4b5563');
    loadComments(); // reload from top
  }
}

function commentHTML(c) {
  const stars = c.rating ? starsHTML(c.rating) : '';
  const place = [c.governorate_name, c.cinema_name].filter(Boolean).join(' - ');
  return `
    <div class="glass rounded-2xl p-5">
      <div class="flex items-start justify-between gap-3 mb-3">
        <div class="flex items-center gap-2">
          <div class="w-8 h-8 rounded-full bg-brand/20 flex items-center justify-center text-brand font-black text-sm">
            ${esc(c.username[0].toUpperCase())}
          </div>
          <span class="font-bold text-sm">${esc(c.username)}</span>
        </div>
        <div class="flex items-center gap-2">
          ${stars ? `<span class="text-yellow-400 text-sm">${stars}</span>` : ''}
          <span class="text-xs text-gray-600">${fmtDate(c.created_at.split(' ')[0])}</span>
        </div>
      </div>
      ${place ? `<div class="text-[10px] text-gray-500 uppercase tracking-widest font-bold mb-2">${esc(place)}</div>` : ''}
      <p class="text-sm text-gray-300 leading-relaxed">${esc(c.body)}</p>
    </div>`;
}


/* ============================================================
   17. SUPPORT FORM
   ============================================================ */
document.getElementById('support-form')?.addEventListener('submit', async e => {
  e.preventDefault();
  const msgEl = document.getElementById('support-msg');
  const supportEmail = document.getElementById('s-email').value.trim();
  if (!isAllowedPublicEmail(supportEmail)) {
    msgEl.classList.remove('hidden');
    msgEl.textContent = '✗ ' + realEmailMessage();
    msgEl.className   = 'text-sm font-bold px-4 py-3 rounded-xl bg-brand/20 text-brand';
    return;
  }
  // Logged-in users create a tracked ticket (so they can read admin replies);
  // guests fall back to the simple support action.
  const action = state.isLoggedIn ? 'create_support_ticket' : 'support';
  const res   = await api(action, {
    name:    document.getElementById('s-name').value.trim(),
    email:   supportEmail,
    governorate_id: document.getElementById('s-governorate')?.value || '',
    subject: document.getElementById('s-subject').value.trim(),
    message: document.getElementById('s-message').value.trim(),
  }, 'POST');

  msgEl.classList.remove('hidden');
  if (res.status === 'success') {
    msgEl.textContent  = '✓ ' + (res.message || 'Request submitted.');
    msgEl.className    = 'text-sm font-bold px-4 py-3 rounded-xl bg-green-500/20 text-green-400';
    document.getElementById('support-form').reset();
    prefillSupportContact();      // restore name/email after reset
  } else {
    msgEl.textContent = '✗ ' + (res.message || 'Error submitting request.');
    msgEl.className   = 'text-sm font-bold px-4 py-3 rounded-xl bg-brand/20 text-brand';
  }
});

/** Open the Support view (new request form). */
function openSupportView() {
  showView('support');
  prefillSupportContact();
}

/** Open the separate My Requests view (existing tickets + replies). */
function openRequestsView() {
  if (!state.isLoggedIn) { showAuthModal('login'); return; }
  showView('requests');
  loadSupportRequests();
}

/** Default the Name/Email fields to the signed-in user's details. */
function prefillSupportContact() {
  if (!state.isLoggedIn) return;
  const nameEl  = document.getElementById('s-name');
  const emailEl = document.getElementById('s-email');
  if (nameEl  && !nameEl.value)  nameEl.value  = state.username || '';
  if (emailEl && !emailEl.value) emailEl.value = state.userEmail || '';
}

/** Show the user's submitted tickets with any admin replies. */
async function loadSupportRequests() {
  const list  = document.getElementById('support-requests-list');
  if (!list) return;
  if (!state.isLoggedIn) {
    list.innerHTML = '<p class="text-gray-500 text-sm">Sign in to see your requests.</p>';
    return;
  }
  list.innerHTML = '<div class="text-center py-6 animate-pulse text-gray-500 text-sm">Loading your requests…</div>';

  const res = await api('my_support_tickets');
  if (res.status !== 'success') {
    list.innerHTML = '<p class="text-gray-500 text-sm">Could not load your requests.</p>';
    return;
  }
  const tickets = res.tickets || [];
  if (!tickets.length) {
    list.innerHTML = '<p class="text-gray-500 text-sm">You have not submitted any requests yet.</p>';
    return;
  }

  list.innerHTML = tickets.map(t => {
    const replies = t.replies || [];
    const statusColor = t.status === 'closed' ? 'bg-white/10 text-gray-400'
                      : (t.status === 'pending' ? 'bg-green-500/20 text-green-300' : 'bg-brand/20 text-brand');
    const replyHtml = replies.length ? `
      <div class="mt-3 pt-3 border-t border-white/10 flex flex-col gap-3">
        <div class="text-[10px] uppercase tracking-widest text-gray-500 font-black">Admin Replies</div>
        ${replies.map(r => `
          <div class="rounded-xl bg-black/30 border border-white/10 p-3">
            <div class="text-[10px] text-gray-500 mb-1">${esc(r.admin_name || 'Admin')} · ${esc(r.created_at || '')}</div>
            <div class="text-sm text-gray-200">${esc(r.message)}</div>
          </div>`).join('')}
      </div>` : '<div class="mt-3 text-xs text-gray-500">No replies yet — we\'ll get back to you.</div>';
    return `
      <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
        <div class="flex justify-between items-start gap-3 flex-wrap mb-1">
          <div class="font-black text-sm">${esc(t.subject || 'No subject')}</div>
          <span class="text-[10px] font-bold px-2 py-1 rounded-full ${statusColor}">${esc(t.status || 'open')}</span>
        </div>
        <div class="text-[10px] text-gray-500 mb-2">#${esc(t.id)} · ${esc(t.created_at || '')}</div>
        <div class="text-[10px] text-gray-500 mb-2">Region: ${esc(t.governorate_name || 'No region')}</div>
        <div class="text-sm text-gray-300">${esc(t.message || '')}</div>
        ${replyHtml}
      </div>`;
  }).join('');
}

/* ============================================================
   NOTIFICATIONS POPUP (on login)
   Shows only unread notifications and marks them read after the
   user closes the popup. It is tied to the account that is actually
   logged in right now.
   ============================================================ */
async function checkAndShowNotificationsPopup() {
  if (!state.isLoggedIn) return false;
  const forUserId = state.userId;   // remember who we're showing for

  const res = await api('my_notifications');
  // Bail out if auth state changed while we were fetching, or the response
  // isn't for the still-current user (prevents stale/cross-account popups).
  if (res.status !== 'success') return false;
  if (!state.isLoggedIn || state.userId !== forUserId) return false;

  const unread = (res.notifications || [])
    .filter(n => Number(n.is_read) === 0)
    .slice(0, 5);   // only the latest few
  if (!unread.length) return false;

  const list = document.getElementById('notif-popup-list');
  const modal = document.getElementById('notif-popup-modal');
  if (!list || !modal) return false;

  list.innerHTML = unread.map(n => `
    <div class="rounded-xl border border-white/10 bg-black/30 p-3">
      <div class="font-bold text-sm mb-1">${esc(n.title || 'Notification')}</div>
      <div class="text-xs text-gray-300">${esc(n.message || '')}</div>
      <div class="text-[10px] text-gray-500 mt-1">${esc(n.created_at || '')}</div>
    </div>`).join('');

  modal.classList.remove('hidden');

  const dismiss = () => {
    modal.classList.add('hidden');
    unread.forEach(n => {
      api('mark_notification_read', { notification_id: n.id }, 'POST').catch(() => {});
    });
  };
  document.getElementById('notif-popup-dismiss-btn').onclick = dismiss;
  document.getElementById('close-notif-popup-btn').onclick = dismiss;
  return true;
}

function startUserLiveSync() {
  if (userLiveSyncTimer) clearInterval(userLiveSyncTimer);
  if (!state.isLoggedIn) return;

  userLiveSyncTimer = setInterval(async () => {
    if (!state.isLoggedIn || userLiveSyncRunning) return;
    userLiveSyncRunning = true;
    try {
      const hasAdminUpdate = await checkAndShowNotificationsPopup();
      if (hasAdminUpdate) {
        await refreshVisibleLiveData({ skipNotifications: true });
      }
    } finally {
      userLiveSyncRunning = false;
    }
  }, 10000);
}

function stopUserLiveSync() {
  if (userLiveSyncTimer) clearInterval(userLiveSyncTimer);
  userLiveSyncTimer = null;
  userLiveSyncRunning = false;
}

async function refreshVisibleLiveData(options = {}) {
  const bookingsOpen = !document.getElementById('my-bookings-modal')?.classList.contains('hidden');
  const accountOpen = !document.getElementById('account-modal')?.classList.contains('hidden');
  const cinemasOpen = !document.getElementById('cinemas-modal')?.classList.contains('hidden');
  const bookingOpen = !document.getElementById('booking-modal')?.classList.contains('hidden');
  const skipNotifications = options?.skipNotifications === true;

  const tasks = [];

  if (state.isLoggedIn) {
    if (!skipNotifications) tasks.push(checkAndShowNotificationsPopup());
    if (bookingsOpen || accountOpen) tasks.push(refreshBookingLists());
    if (state.currentView === 'requests') tasks.push(loadSupportRequests());
  }

  if (state.currentView === 'details' && state.selectedMovie) {
    tasks.push(loadComments());
  }

  if (cinemasOpen) {
    const currentCinema = (state.cinemaNav || []).slice(-1)[0];
    if (currentCinema?.step === 'cinema') {
      tasks.push(openCinemaScreen(currentCinema.id, currentCinema.name, currentCinema.location, true));
    } else {
      tasks.push(renderCinemaList());
    }
  }

  if (bookingOpen && state.selectedMovie) {
    if (state.currentShowtime?.id && state.selectedSeats.length === 0) {
      tasks.push(selectShowtime(state.currentShowtime.id));
    } else if (!state.currentShowtime && state.selectedCinema?.id) {
      tasks.push(selectCinema(state.selectedCinema.id, state.selectedCinema.name, state.selectedCinema.location));
    } else if (!state.currentShowtime) {
      tasks.push(loadCinemaAccordion());
    }
  }

  await Promise.allSettled(tasks);
}


/* ============================================================
   18. SEARCH & NAVIGATION
   ============================================================ */
function setupNavigation() {
  const goHome = () => {
    state.selectedMovie = null;
    state.history = []; // <--- Clear the breadcrumbs when we go home
    const s = document.getElementById('search-input');
    if (s) s.value = '';
    renderMovieGrids();
    showView('home');
    if (state.nowShowing.length) setHeroMovie(state.nowShowing[state.heroIndex] || state.nowShowing[0]);
  };

  document.getElementById('logo-btn').addEventListener('click', goHome);
  document.getElementById('nav-movies-btn').addEventListener('click', goHome);
  document.getElementById('back-btn').addEventListener('click', () => {
    if (state.history.length > 1) {
      state.history.pop(); // Remove the page we are currently on
      const prev = state.history[state.history.length - 1]; // Look at the previous page

      // Re-open it! (Passing 'true' so it doesn't drop a new breadcrumb)
      if (prev.type === 'details') {
        openDetails(prev.payload, true);
      } else if (prev.type === 'actor') {
        openPersonView(prev.payload, true);
      }
    } else {
      // If there is no history left, just go home safely
      goHome();
    }
  });
  document.getElementById('support-back-btn')?.addEventListener('click', goHome);
  // Person back → return to the movie details page they came from
  document.getElementById('person-back-btn')?.addEventListener('click', () => {
    if (state.selectedMovie) showView('details');
    else goHome();
  });
  document.getElementById('nav-support-btn')?.addEventListener('click', openSupportView);
  document.getElementById('nav-requests-btn')?.addEventListener('click', openRequestsView);
  document.getElementById('requests-back-btn')?.addEventListener('click', goHome);
  document.getElementById('nav-cinemas-btn')?.addEventListener('click', openCinemasModal);
  document.getElementById('nav-payments-btn')?.addEventListener('click', openMyBookingsModal);
  document.getElementById('nav-virtual-cinema-top-btn')?.addEventListener('click', openVirtualCinemaDemo);
  document.getElementById('login-btn')?.addEventListener('click', () => {
    if (!state.isLoggedIn) showAuthModal('login');
    else openAccountModal();
  });

  // Search with 300ms debounce so we don't search on every single keypress
  let debounce;
  document.getElementById('search-input')?.addEventListener('input', e => {
    clearTimeout(debounce);
    const q = e.target.value.trim();
    debounce = setTimeout(() => {
      showView('home');
      renderMovieGrids(q);
      if (!q && state.nowShowing.length) {
        setHeroMovie(state.nowShowing[state.heroIndex] || state.nowShowing[0]);
      }
    }, 300);
  });
}

async function populateNavGovernorates() {
  const res = await api('governorates');
  if (res.status === 'success') {
    const navSelect = document.getElementById('gov-filter');
    const bookSelect = document.getElementById('booking-gov-filter');
    const supportSelect = document.getElementById('s-governorate');
    const commentSelect = document.getElementById('comment-governorate');

    res.governorates.forEach(g => {
      const optionHTML = `<option value="${g.id}">${g.name_en}</option>`;
      if (navSelect) navSelect.insertAdjacentHTML('beforeend', optionHTML);
      if (bookSelect) bookSelect.insertAdjacentHTML('beforeend', optionHTML);
      if (supportSelect) supportSelect.insertAdjacentHTML('beforeend', optionHTML);
      if (commentSelect) commentSelect.insertAdjacentHTML('beforeend', optionHTML);
    });
  }
}

async function loadCommentCinemasForRegion(governorateId = '') {
  const cinemaSelect = document.getElementById('comment-cinema');
  if (!cinemaSelect) return;

  cinemaSelect.innerHTML = governorateId
    ? '<option value="">Any cinema in this region</option>'
    : '<option value="">Choose region first</option>';
  cinemaSelect.disabled = !governorateId;
  if (!governorateId) return;

  const res = await api('cinemas', { gov_id: governorateId });
  if (res.status !== 'success') return;
  (res.cinemas || []).forEach(c => {
    cinemaSelect.insertAdjacentHTML('beforeend', `<option value="${c.id}">${esc(c.name)}</option>`);
  });
}

function setAccordionOpen(block, open) {
  const btn = block.querySelector('.gov-accordion-header');
  const body = block.querySelector('.gov-accordion-body');
  const chevron = block.querySelector('.gov-chevron');

  if (body) body.classList.toggle('hidden', !open);
  if (chevron) chevron.classList.toggle('rotate-180', open);
  if (btn) btn.setAttribute('aria-expanded', String(open));
}

function applyBookingRegionFilter(selectedId = '') {
  const blocks = Array.from(document.querySelectorAll('#booking-body .gov-block'));
  if (!blocks.length) return;

  let firstVisible = null;
  blocks.forEach(block => {
    const isVisible = !selectedId || block.dataset.govId === selectedId;
    block.classList.toggle('hidden', !isVisible);
    if (isVisible && !firstVisible) firstVisible = block;
    if (selectedId && isVisible) setAccordionOpen(block, true);
  });

  if (!selectedId && firstVisible && !blocks.some(block => !block.classList.contains('hidden') && block.querySelector('.gov-accordion-body:not(.hidden)'))) {
    setAccordionOpen(firstVisible, true);
  }
}

function applyCinemasRegionFilter(selectedId = '') {
  const blocks = Array.from(document.querySelectorAll('#cinemas-list .cinema-gov-block'));
  if (!blocks.length) return;

  let firstVisible = null;
  blocks.forEach(block => {
    const isVisible = !selectedId || block.dataset.govId === selectedId;
    block.classList.toggle('hidden', !isVisible);
    if (isVisible && !firstVisible) firstVisible = block;
    if (selectedId && isVisible) setAccordionOpen(block, true);
  });

  if (!selectedId && firstVisible && !blocks.some(block => !block.classList.contains('hidden') && block.querySelector('.gov-accordion-body:not(.hidden)'))) {
    setAccordionOpen(firstVisible, true);
  }
}

// Wire up the Booking Modal Filter
document.getElementById('booking-gov-filter')?.addEventListener('change', (e) => {
  applyBookingRegionFilter(e.target.value);
});

document.getElementById('comment-governorate')?.addEventListener('change', (e) => {
  loadCommentCinemasForRegion(e.target.value);
});

// Wire up the Nav Bar Filter (Global Region Selector)
document.getElementById('gov-filter')?.addEventListener('change', (e) => {
  const selectedId = e.target.value;

  applyCinemasRegionFilter(selectedId);

  const bookingFilter = document.getElementById('booking-gov-filter');
  if (bookingFilter) {
    bookingFilter.value = selectedId;
    applyBookingRegionFilter(selectedId);
  }

  const commentRegion = document.getElementById('comment-governorate');
  if (commentRegion && !commentRegion.value) {
    commentRegion.value = selectedId;
    loadCommentCinemasForRegion(selectedId);
  }
});

function openVirtualCinemaDemo() {
  window.location.href = 'virtual_cinema.html';
}

/* ============================================================
   PERSON VIEW — Actor / Director Profile
   Opens when the user clicks any cast chip on the details page.
   Shows: photo, bio, birth info, average rating across career,
   and a filmography grid of their movies with individual ratings.
   ============================================================ */

/**
 * openPersonView(personId)
 * personId: TMDB person ID (from the cast chip's onclick attribute)
 *
 * Step 1 — Switch to person view immediately (shows skeleton UI)
 * Step 2 — Fetch person bio + movie credits in parallel from TMDB
 * Step 3 — Calculate career average rating across all their films
 * Step 4 — Render the profile and filmography grid
 */
async function openPersonView(personId, isBack = false) {
  // Drop a breadcrumb if we are moving forward
  if (!isBack) state.history.push({ type: 'actor', payload: personId });

  showView('person');

  // Skeleton loading state so the user sees something immediately
  document.getElementById('person-name').textContent = 'Loading…';
  document.getElementById('person-photo').src        = 'https://via.placeholder.com/300x450?text=…';
  document.getElementById('person-bio').textContent  = '';
  document.getElementById('person-meta').innerHTML   = '';
  document.getElementById('person-films-grid').innerHTML =
    '<div class="col-span-full text-gray-600 text-sm animate-pulse py-8 text-center">Loading filmography…</div>';

  // One backend call returns bio + deduplicated, sorted filmography
  const res = await api('person_details', { person_id: personId });
  if (res.status !== 'success' || !res.person) {
    document.getElementById('person-name').textContent = 'Not found';
    document.getElementById('person-films-grid').innerHTML =
      '<p class="col-span-full text-gray-600 text-sm">Could not load this person.</p>';
    return;
  }
  const p = res.person;

  // ── Render person header ─────────────────────────────────────
  document.getElementById('person-photo').src =
    p.photo || 'https://via.placeholder.com/300x450?text=No+Photo';

  document.getElementById('person-name').textContent = p.name || 'Unknown';

  document.getElementById('person-role-badge').innerHTML =
    `<span class="type-badge film">${esc(p.known_for_department || 'Acting')}</span>`;

  document.getElementById('person-bio').textContent =
    p.biography || 'No biography available.';

  // Meta row: film count, avg rating, birthday, birthplace
  document.getElementById('person-meta').innerHTML = `
    <span>🎬 ${p.film_count} films</span>
    <span class="font-bold text-brand">⭐ Career avg: ${esc(p.avg_rating)} / 10</span>
    ${p.birthday        ? `<span>📅 Born: ${esc(p.birthday)}</span>`          : ''}
    ${p.place_of_birth  ? `<span>📍 ${esc(p.place_of_birth)}</span>`          : ''}
  `;

  // ── Render filmography grid ──────────────────────────────────
  // Show up to 20 films. Each card shows:
  //   - Movie poster
  //   - Rating badge (top-right)
  //   - Role label on hover (Actor / Director / character name)
  // Clicking a film card calls openPersonMovieDetails() to view it.
  const top20 = p.films || [];

  if (!top20.length) {
    document.getElementById('person-films-grid').innerHTML =
      '<p class="col-span-full text-gray-600 text-sm">No filmography available.</p>';
    return;
  }

  document.getElementById('person-films-grid').innerHTML = top20.map(m => `
    <div class="cursor-pointer group" onclick="openPersonMovieDetails(${m.id})">
      <div class="relative aspect-[2/3] rounded-xl overflow-hidden border border-white/5 bg-white/5 hover:border-brand transition-colors duration-200">
        <img src="${esc(m.poster_url)}"
             alt="${esc(m.title)}"
             loading="lazy"
             class="w-full h-full object-cover" />

        <!-- Rating badge — always visible top-right -->
        <div class="absolute top-2 right-2 bg-black/80 px-2 py-1 rounded-lg flex items-center gap-1">
          <span class="text-yellow-400 text-[10px]">★</span>
          <span class="text-[10px] font-black text-white">${m.vote_average.toFixed(1)}</span>
        </div>

        <!-- Title + role — visible on hover -->
        <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/20 to-transparent
                    opacity-0 group-hover:opacity-100 transition-opacity duration-200
                    flex flex-col justify-end p-3">
          <div class="text-[11px] font-black text-white leading-tight">${esc(m.title)}</div>
          <div class="text-[10px] text-brand font-bold mt-0.5">${esc(m.roleLabel)}</div>
          ${m.release_date ? `<div class="text-[9px] text-gray-400">${m.release_date.slice(0,4)}</div>` : ''}
        </div>
      </div>
    </div>`).join('');
}

/**
 * openPersonMovieDetails(tmdbId)
 * Opens a movie from the person's filmography.
 * First checks if the movie is already in our Now Showing / Coming Soon lists.
 * If not, fetches it fresh from TMDB and opens the details view.
 */
async function openPersonMovieDetails(tmdbId) {

  // Check local cache first — avoids an extra API call for current movies
  const cached = [...state.nowShowing, ...state.comingSoon]
      .find(m => m.tmdbId === tmdbId || m.id === String(tmdbId));
  if (cached) { openDetails(cached); return; }

  // Not cached → ask the backend, which talks to TMDB on our behalf
  const res = await api('movie_details', { tmdb_id: tmdbId });
  if (res.status !== 'success' || !res.movie) return;
  const movie = { ...res.movie, isCatalog: true };  // flag enables streaming providers UI
  openDetails(movie);
}

/* ============================================================
   19. UTILITY FUNCTIONS
   ============================================================ */

/** Escapes HTML special characters to prevent XSS. Always use on user data. */
function esc(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/** Formats "2026-05-20" → "Tuesday, 20 May" */
function fmtDate(dateStr) {
  if (!dateStr) return '';
  try {
    return new Date(dateStr + 'T00:00:00').toLocaleDateString('en-EG', {
      weekday: 'long', day: 'numeric', month: 'long'
    });
  } catch { return dateStr; }
}

/** Converts a number rating (e.g. 4.2) to filled/empty star string. */
function starsHTML(rating) {
  const filled = Math.round(parseFloat(rating));
  return '★'.repeat(filled) + '☆'.repeat(5 - filled);
}
