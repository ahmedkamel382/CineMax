<?php
/**
 * CINEMAX EGYPT — BACKEND API  (api.php)
 * ============================================================
 * Single entry point for all requests from the frontend.
 *
 * GET  requests:  api.php?action=X&param=Y
 * POST requests:  api.php   with JSON body { "action": "X", ... }
 *
 * All responses are JSON:
 *   Success: { "status": "success", ... }
 *   Failure: { "status": "error", "message": "..." }
 *
 * ACTIONS:
 *   Cinemas:  governorates, cinemas, movie_cinemas
 *   Shows:    showtimes, showtime_details
 *   Booking:  book, my_bookings
 *   Reviews:  get_comments, add_comment
 *   Auth:     login, register, logout, session, csrf_token
 *   TMDB:     movies_now_showing, movies_upcoming, movie_trailer,
 *             movie_cast, movie_details, streaming_providers,
 *             person_details
 *   Other:    support, sync_status, trigger_sync
 * ============================================================
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

const PENDING_CASH_EXPIRY_MINUTES = 30;

// ── Session isolation ─────────────────────────────────────────
// The admin dashboard and the public website must keep completely
// separate logins. They run in the same PHP app, so by default they
// would share one session cookie — signing into the admin panel would
// also sign you in on the main site, and vice versa. To prevent that,
// the admin pages send ctx=manager_admin or ctx=regional_admin (see admin.js)
// and each context receives its own named session cookie.
$clientCtx = $_GET['ctx'] ?? '';
// Three separated login areas:
// - public website users use CINEMAXUSER
// - manager admin uses CINEMAXMANAGER
// - regional/staff admins use CINEMAXREGIONAL
// This prevents a manager login from replacing a regional admin login,
// and prevents both admin logins from replacing the normal website login.
if ($clientCtx === 'manager_admin') {
    session_name('CINEMAXMANAGER');
} elseif ($clientCtx === 'regional_admin') {
    session_name('CINEMAXREGIONAL');
} elseif ($clientCtx === 'admin') {
    // Backward compatibility for older admin.js files.
    session_name('CINEMAXADMIN');
} else {
    session_name('CINEMAXUSER');
}

// Detect HTTPS, including when behind the Cloudflare tunnel (which terminates
// TLS and forwards over plain HTTP with an X-Forwarded-Proto header). Without
// this, the session cookie can be dropped on the public HTTPS URL.
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (($_SERVER['SERVER_PORT'] ?? '') == 443) ||
    (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
);

$cookieParams = [
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
];
session_set_cookie_params($cookieParams);
ini_set('session.gc_maxlifetime', '86400'); // keep idle sessions for 24h
ini_set('session.use_strict_mode', '1');

session_start();
require_once 'db.php';
require_once 'tmdb_service.php';

// ── Read action ───────────────────────────────────────────────
$data   = [];
$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    if (isset($data['action'])) $action = $data['action'];
}

// ── Helper functions ──────────────────────────────────────────

/** Send a success JSON response and stop. */
function ok(array $payload): void {
    echo json_encode(['status' => 'success'] + $payload);
    exit;
}

/** Send an error JSON response and stop. */
function fail(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

/**
 * Validate that a newly entered email uses a real public email provider.
 * This does not prove the mailbox exists; that would require sending a
 * confirmation email. It blocks fake/demo domains such as test.com, x.com,
 * localhost, cinemax.local, and random invalid domains during registration
 * or admin account creation.
 */
function allowedPublicEmailDomains(): array {
    return [
        'gmail.com', 'googlemail.com',
        'yahoo.com', 'yahoo.co.uk', 'yahoo.com.eg', 'ymail.com', 'rocketmail.com',
        'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'proton.me', 'protonmail.com',
        'aol.com', 'mail.com', 'zoho.com', 'yandex.com', 'gmx.com', 'gmx.net'
    ];
}

function isAllowedPublicEmail(string $email): bool {
    $email = trim(strtolower($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = substr(strrchr($email, '@') ?: '', 1);
    return in_array($domain, allowedPublicEmailDomains(), true);
}

function requirePublicEmail(string $email): void {
    if (!isAllowedPublicEmail($email)) {
        fail('Please use a real email from Gmail, Yahoo, Outlook, Hotmail, iCloud, Proton, Zoho, Mail.com, Yandex, AOL, or GMX.');
    }
}


function requireStrongPassword(string $password, string $label = 'Password'): void {
    if (strlen($password) < 6) {
        fail($label . ' must be at least 6 characters.');
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        fail($label . ' must contain both letters and numbers. It cannot be only letters or only numbers.');
    }
}

function normalizePhoneNumber(string $phone): string {
    $phone = trim($phone);
    $phone = preg_replace('/[^0-9+]/', '', $phone);
    if (strpos($phone, '00') === 0) {
        $phone = '+' . substr($phone, 2);
    }
    return $phone;
}

function requireRecoveryChannel(string $channel, string $phone = ''): array {
    $channel = strtolower(trim($channel ?: 'email'));
    if (!in_array($channel, ['email', 'phone'], true)) {
        fail('Choose how you want to receive the code: email or WhatsApp/SMS.');
    }
    $phone = normalizePhoneNumber($phone);
    if ($channel === 'phone') {
        // Accept Egyptian/international style numbers for manual WhatsApp/SMS delivery.
        // This validates the shape only; the admin must still verify that the number belongs to the user.
        if (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
            fail('Please enter a valid phone number for WhatsApp/SMS, for example +201001234567.');
        }
    }
    return [$channel, $phone];
}


function publicBaseUrl(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($path ? $path : '');
}

function sendCinemaxEmail(PDO $pdo, string $to, string $subject, string $body): bool {
    $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@cinemax.local';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'CINEMAX Support';
    $headers = "From: {$fromName} <{$fromAddress}>\r\n" .
               "Reply-To: {$fromAddress}\r\n" .
               "Content-Type: text/plain; charset=UTF-8\r\n";
    $sent = false;
    $error = null;
    try {
        $sent = @mail($to, $subject, $body, $headers);
        if (!$sent) $error = 'PHP mail() is not configured on this server.';
    } catch (Throwable $e) {
        $sent = false;
        $error = $e->getMessage();
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO email_outbox (recipient_email, subject, body, status, error_message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$to, $subject, $body, $sent ? 'sent' : 'local_only', $error]);
    } catch (Throwable $e) {}
    return $sent;
}

function createPasswordResetCode(PDO $pdo, int $userId, string $email): string {
    $code = (string)random_int(100000, 999999);
    $hash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);
    $pdo->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$userId]);
    $stmt = $pdo->prepare("INSERT INTO password_reset_codes (user_id, email, code_hash, expires_at, ip_address) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $email, $hash, $expiresAt, $_SERVER['REMOTE_ADDR'] ?? null]);
    return $code;
}

function sendPasswordResetCode(PDO $pdo, array $user): array {
    $code = createPasswordResetCode($pdo, (int)$user['id'], $user['email']);
    $expiresAt = date('Y-m-d H:i:s', time() + 10 * 60);
    $subject = 'CINEMAX password reset code';
    $body = "Hello {$user['username']},\n\n" .
            "Your CINEMAX password reset code is: {$code}\n\n" .
            "This code expires in 10 minutes. If you did not request this, ignore this message.\n\n" .
            "CINEMAX";

    // For the university/local project, the code is generated by the system
    // and shown to the manager/admin, so the admin can send it manually by
    // WhatsApp, Gmail, Outlook, Yahoo, etc. We also attempt PHP mail when it
    // is configured, and always keep a copy in email_outbox for testing.
    $sent = sendCinemaxEmail($pdo, $user['email'], $subject, $body);
    return [
        'mail_sent' => $sent,
        'manual_code' => $code,
        'expires_at' => $expiresAt,
        'manual_message' => "CINEMAX password reset code for {$user['email']}: {$code}. This code expires in 10 minutes."
    ];
}

/** Remove login-only session data while keeping the PHP session usable for CSRF. */
function clearAuthSession(): void {
    unset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'], $_SESSION['governorate_id'], $_SESSION['governorate_name'], $_SESSION['governorate_ids']);
}

/** Return the real database user for the active session, or null if it is stale. */
function currentSessionUser(PDO $pdo): ?array {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT u.*, g.name_en AS governorate_name
        FROM users u
        LEFT JOIN governorates g ON u.governorate_id = g.id
        WHERE u.id = ?
        LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * Return every governorate id an admin/staff account is responsible for.
 * Reads the admin_governorates many-to-many table and always includes the
 * primary users.governorate_id, so single-region accounts keep working even
 * if the table is empty.
 */
function loadAdminGovernorateIds(PDO $pdo, int $userId, ?int $primaryGovId): array {
    $ids = [];
    if (tableExists($pdo, 'admin_governorates')) {
        try {
            $stmt = $pdo->prepare("SELECT governorate_id FROM admin_governorates WHERE user_id = ?");
            $stmt->execute([$userId]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (Throwable $e) { $ids = []; }
    }
    if ($primaryGovId) { $ids[] = (int)$primaryGovId; }
    return array_values(array_unique(array_filter($ids)));
}

/** Accept governorate_ids (array or comma string) or a single governorate_id. */
function adminParseGovernorateIds(array $data): array {
    $raw = $data['governorate_ids'] ?? null;
    if ($raw === null && isset($data['governorate_id'])) {
        $raw = $data['governorate_id'];
    }
    if (is_string($raw)) {
        $raw = array_map('trim', explode(',', $raw));
    }
    if (!is_array($raw)) { $raw = $raw === null ? [] : [$raw]; }
    $ids = array_values(array_unique(array_filter(array_map('intval', $raw))));
    return $ids;
}

/** Keep only ids that exist in governorates, preserving the given order. */
function adminValidateGovernorateIds(PDO $pdo, array $ids): array {
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id FROM governorates WHERE id IN ($ph)");
    $stmt->execute($ids);
    $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $ordered = [];
    foreach ($ids as $id) {
        if (in_array($id, $found, true) && !in_array($id, $ordered, true)) {
            $ordered[] = $id;
        }
    }
    return $ordered;
}

function adminGovernorateNames(PDO $pdo, array $ids): string {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return 'no assigned region';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT name_en FROM governorates WHERE id IN ($ph) ORDER BY name_en");
    $stmt->execute($ids);
    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $names ? implode(', ', $names) : 'assigned region';
}

/** Stop with 401 if no active valid database-backed session. Used by protected actions. */
function requireAuth(): void {    global $pdo;

    $user = currentSessionUser($pdo);
    if (!$user) {
        clearAuthSession();
        fail('Your session expired. Please sign in again.', 401);
    }

    if (!empty($user['is_blocked'])) {
        clearAuthSession();
        fail('Your account is blocked.', 403);
    }

    $_SESSION['user_id']  = (int)$user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role']     = $user['role'] ?? 'user';
    if (!empty($user['governorate_id'])) {
        $_SESSION['governorate_id'] = (int)$user['governorate_id'];
        $_SESSION['governorate_name'] = $user['governorate_name'] ?? '';
    } else {
        unset($_SESSION['governorate_id'], $_SESSION['governorate_name']);
    }
    $_SESSION['governorate_ids'] = loadAdminGovernorateIds(
        $pdo, (int)$user['id'], !empty($user['governorate_id']) ? (int)$user['governorate_id'] : null
    );
}

/** Stop with 403 if the current user is not an admin. */
function requireAdmin(): void {
    requireAuth();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        fail('Admin access required.', 403);
    }
}

/** Stop with 403 if the current user is neither admin nor staff. */
function requireStaffOrAdmin(): void {
    requireAuth();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'staff', 'regional_admin'], true)) {
        fail('Staff access required.', 403);
    }
}

/** Operational users handle cinema-day work. Manager admin is allowed as a fallback. */
function requireOperationalAdmin(): void {
    requireAuth();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['admin', 'staff', 'regional_admin'], true)) {
        fail('Admin, regional, or staff access required.', 403);
    }
}

function isRegionalAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'regional_admin';
}

/** All governorate ids the current admin/staff account covers. */
function regionalAdminGovernorateIds(): array {
    $ids = $_SESSION['governorate_ids'] ?? [];
    if (!$ids && !empty($_SESSION['governorate_id'])) {
        $ids = [(int)$_SESSION['governorate_id']];
    }
    return array_values(array_unique(array_map('intval', array_filter((array)$ids))));
}

/** Primary (first) region — kept for code paths that still expect one id. */
function regionalAdminGovernorateId(): int {
    $ids = regionalAdminGovernorateIds();
    if (isRegionalAdmin() && !$ids) {
        fail('Regional admin account is missing an assigned region.', 403);
    }
    return $ids[0] ?? 0;
}

function enforceRegionalGovernorateAccess(int $governorateId): void {
    if (isRegionalAdmin() && !in_array($governorateId, regionalAdminGovernorateIds(), true)) {
        fail('This record belongs to another region.', 403);
    }
}

function regionalScopeClause(string $cinemaAlias = 'c', string $prefix = 'WHERE'): array {
    if (!isRegionalAdmin()) {
        return ['', []];
    }
    $ids = regionalAdminGovernorateIds();
    if (!$ids) {
        fail('Regional admin account is missing an assigned region.', 403);
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [$prefix . " {$cinemaAlias}.governorate_id IN ($placeholders)", $ids];
}

function addSqlCondition(string $whereSql, string $condition): string {
    return $whereSql ? ($whereSql . " AND " . $condition) : ("WHERE " . $condition);
}

function adminBookingRow(PDO $pdo, int $bookingId): array {
    $stmt = $pdo->prepare("
        SELECT b.*, c.governorate_id
        FROM bookings b
        JOIN showtimes s ON b.showtime_id = s.id
        JOIN halls h ON s.hall_id = h.id
        JOIN cinemas c ON h.cinema_id = c.id
        WHERE b.id = ?
        LIMIT 1
    ");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        fail('Booking not found.', 404);
    }
    enforceRegionalGovernorateAccess((int)$booking['governorate_id']);
    return $booking;
}

/**
 * requireCsrf($data)
 * Verifies the csrf_token sent in a POST body matches the one
 * stored in $_SESSION. Uses hash_equals to prevent timing attacks.
 * Call this at the start of every state-changing POST action.
 */
function requireCsrf(array $data): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $sent = $data['csrf_token'] ?? '';
    $real = $_SESSION['csrf_token'] ?? '';
    if (!$sent || !$real || !hash_equals($real, $sent)) {
        fail('Invalid CSRF token. Refresh the page and try again.', 403);
    }
}

/** Escape HTML to prevent XSS when inserting user data. */
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}



/** Convert a numeric row + seat number into a human-readable label (A1, B12...). */
function seatLabel(int $row, int $number): string {
    return chr(64 + $row) . $number;
}

/** Create a short ticket verification code that is safe to put inside a QR code. */
function makeTicketCode(int $bookingId): string {
    // Every reserved ticket must have its own QR identity.
    // The booking id links the code to one reservation, the date helps humans read it,
    // and 8 random bytes make the code different even when many tickets are made quickly.
    return 'CMX-' . date('Ymd') . '-' . $bookingId . '-' . strtoupper(bin2hex(random_bytes(8)));
}

/** Create a different ticket code for every individual reserved seat. */
function makeSeatTicketCode(int $bookingId, int $row, int $number): string {
    $seat = seatLabel($row, $number);
    return 'CMX-SEAT-' . date('Ymd') . '-' . $bookingId . '-' . $seat . '-' . strtoupper(bin2hex(random_bytes(6)));
}

/** Build the QR payload for one individual seat ticket. */
function makeSeatQrText(int $bookingId, string $seatCode, string $seatLabel, ?string $paymentReference = ''): string {
    return 'CINEMAX|BOOKING=' . $bookingId . '|SEAT=' . $seatLabel . '|CODE=' . $seatCode . '|REF=' . ($paymentReference ?? '');
}

/** Return database failures as generic messages while preserving intentional validation errors. */
function failThrowable(Throwable $e, string $fallback = 'Request failed.'): void {
    if ($e instanceof PDOException) {
        fail('Database error.');
    }
    fail($e->getMessage() ?: $fallback);
}

/** Enforce database-friendly text lengths before inserts/updates. */
function requireMaxLength(string $value, int $max, string $label): void {
    if (mb_strlen($value, 'UTF-8') > $max) {
        fail("$label is too long. Maximum $max characters.");
    }
}

/** Lightweight session throttle for public forms. */
function throttleSessionAction(string $key, int $seconds): void {
    $now = time();
    $_SESSION['throttle'] = $_SESSION['throttle'] ?? [];
    $last = (int)($_SESSION['throttle'][$key] ?? 0);
    if ($last && ($now - $last) < $seconds) {
        fail('Please wait a moment before sending another request.', 429);
    }
    $_SESSION['throttle'][$key] = $now;
}

/** Limit repeated password-reset code guesses in the current browser session. */
function throttlePasswordResetCodeAttempt(string $email): void {
    $now = time();
    $key = 'reset_code:' . sha1(strtolower($email));
    $_SESSION['reset_code_attempts'] = $_SESSION['reset_code_attempts'] ?? [];
    $entry = $_SESSION['reset_code_attempts'][$key] ?? ['count' => 0, 'start' => $now];
    if (($now - (int)$entry['start']) > 10 * 60) {
        $entry = ['count' => 0, 'start' => $now];
    }
    if ((int)$entry['count'] >= 5) {
        fail('Too many incorrect reset-code attempts. Request a new code and try again later.', 429);
    }
    $entry['count'] = (int)$entry['count'] + 1;
    $_SESSION['reset_code_attempts'][$key] = $entry;
}

function clearPasswordResetCodeAttempts(string $email): void {
    $key = 'reset_code:' . sha1(strtolower($email));
    unset($_SESSION['reset_code_attempts'][$key]);
}

/** Store an in-app notification if the notifications table exists. */
function addNotification(PDO $pdo, int $userId, string $title, string $message): void {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $title, $message]);
    } catch (Throwable $e) {
        // Notifications are helpful, but they must never break booking/payment.
    }
}

/** Return true if a nullable table column exists. Used only for graceful migration compatibility. */
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** Return true if a table exists. Used for migration-friendly optional features. */
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

/** Final schema uses support_messages; older local installs may still have support_tickets. */
function supportMessagesTable(PDO $pdo): string {
    return tableExists($pdo, 'support_messages') ? 'support_messages' : 'support_tickets';
}

function ensurePasswordResetAttemptColumns(PDO $pdo): void {
    try {
        if (!tableExists($pdo, 'password_reset_codes')) return;
        if (!columnExists($pdo, 'password_reset_codes', 'failed_attempts')) {
            $pdo->exec("ALTER TABLE password_reset_codes ADD COLUMN failed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER used_at");
        }
        if (!columnExists($pdo, 'password_reset_codes', 'locked_at')) {
            $pdo->exec("ALTER TABLE password_reset_codes ADD COLUMN locked_at DATETIME NULL AFTER failed_attempts");
        }
    } catch (Throwable $e) {
        // Existing installs without ALTER privileges still have session throttling.
    }
}

/** Delete expired seat holds and holds that became booked. */
function cleanupSeatLocks(PDO $pdo, ?int $showtimeId = null): void {
    if (!tableExists($pdo, 'seat_locks')) {
        return;
    }

    try {
        if ($showtimeId) {
            $expired = $pdo->prepare("DELETE FROM seat_locks WHERE showtime_id = ? AND expires_at < NOW()");
            $expired->execute([$showtimeId]);

            $booked = $pdo->prepare("
                DELETE sl
                FROM seat_locks sl
                JOIN booking_seats bs
                  ON bs.showtime_id = sl.showtime_id
                 AND bs.seat_row = sl.seat_row
                 AND bs.seat_number = sl.seat_number
                JOIN bookings b
                  ON b.id = bs.booking_id
                 AND b.status = 'confirmed'
                WHERE sl.showtime_id = ?
            ");
            $booked->execute([$showtimeId]);
        } else {
            $pdo->exec("DELETE FROM seat_locks WHERE expires_at < NOW()");
            $pdo->exec("
                DELETE sl
                FROM seat_locks sl
                JOIN booking_seats bs
                  ON bs.showtime_id = sl.showtime_id
                 AND bs.seat_row = sl.seat_row
                 AND bs.seat_number = sl.seat_number
                JOIN bookings b
                  ON b.id = bs.booking_id
                 AND b.status = 'confirmed'
            ");
        }
    } catch (Throwable $e) {
        // Locks are temporary; cleanup must never break the main action.
    }
}

/** Validate and return the support region chosen by the customer. */
function requireSupportGovernorateId(PDO $pdo, array $data): int {
    $govId = (int)($data['governorate_id'] ?? 0);
    if (!$govId) {
        fail('Choose the region that should receive this support request.');
    }

    $stmt = $pdo->prepare("SELECT id FROM governorates WHERE id = ? LIMIT 1");
    $stmt->execute([$govId]);
    if (!$stmt->fetchColumn()) {
        fail('Selected support region was not found.');
    }

    return $govId;
}

/** Return a support ticket and enforce regional admin scope. */
function adminSupportTicketRow(PDO $pdo, int $ticketId): array {
    $supportTable = supportMessagesTable($pdo);
    $stmt = $pdo->prepare("
        SELECT t.*, g.name_en AS governorate_name
        FROM $supportTable t
        LEFT JOIN governorates g ON t.governorate_id = g.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if (!$ticket) {
        fail('Support ticket not found.', 404);
    }

    if (isRegionalAdmin()) {
        $ticketGovId = (int)($ticket['governorate_id'] ?? 0);
        if (!$ticketGovId || !in_array($ticketGovId, regionalAdminGovernorateIds(), true)) {
            fail('This support ticket belongs to another region.', 403);
        }
    }

    return $ticket;
}

/** Keep an audit row when a seat is cancelled, while booking_seats stays active-only. */
function archiveCancelledBookingSeat(PDO $pdo, int $bookingId, int $showtimeId, ?int $userId, int $row, int $number, float $refundAmount, string $reason): void {
    if (!tableExists($pdo, 'cancelled_booking_seats')) {
        return;
    }

    try {
        $seatTicketCode = null;
        $seatTicketStatus = 'cancelled';
        if (columnExists($pdo, 'booking_seats', 'seat_ticket_code')) {
            $codeStmt = $pdo->prepare("
                SELECT seat_ticket_code, seat_ticket_status
                FROM booking_seats
                WHERE booking_id = ? AND seat_row = ? AND seat_number = ?
                LIMIT 1
            ");
            $codeStmt->execute([$bookingId, $row, $number]);
            $codeRow = $codeStmt->fetch();
            if ($codeRow) {
                $seatTicketCode = $codeRow['seat_ticket_code'] ?? null;
                $seatTicketStatus = 'cancelled';
            }
        }

        if (columnExists($pdo, 'cancelled_booking_seats', 'seat_ticket_code')) {
            $stmt = $pdo->prepare("
                INSERT INTO cancelled_booking_seats
                  (booking_id, showtime_id, seat_row, seat_number, seat_ticket_code, seat_ticket_status, cancelled_by_user_id, refund_amount, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$bookingId, $showtimeId, $row, $number, $seatTicketCode, $seatTicketStatus, $userId, $refundAmount, $reason]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO cancelled_booking_seats
                  (booking_id, showtime_id, seat_row, seat_number, cancelled_by_user_id, refund_amount, reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$bookingId, $showtimeId, $row, $number, $userId, $refundAmount, $reason]);
        }
    } catch (Throwable $e) {
        // Cancellation itself must not fail just because the audit table is unavailable.
    }
}

/**
 * Load active booking seats and split them into refundable/cancellable vs used.
 * A used seat QR must never be refunded or deleted as an unused seat.
 */
function refundableSeatMaps(PDO $pdo, int $bookingId, bool $forUpdate = false): array {
    $statusSelect = columnExists($pdo, 'booking_seats', 'seat_ticket_status')
        ? 'seat_ticket_status'
        : "'valid' AS seat_ticket_status";
    $sql = "
        SELECT seat_row, seat_number, $statusSelect
        FROM booking_seats
        WHERE booking_id = ?
        ORDER BY seat_row, seat_number
    ";
    if ($forUpdate) $sql .= " FOR UPDATE";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$bookingId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        throw new Exception('No active seats found for this booking.');
    }

    $refundable = [];
    $used = [];
    foreach ($rows as $seat) {
        $row = (int)$seat['seat_row'];
        $number = (int)$seat['seat_number'];
        $key = "$row:$number";
        if (($seat['seat_ticket_status'] ?? 'valid') === 'used') {
            $used[$key] = true;
        } else {
            $refundable[$key] = true;
        }
    }

    return [$refundable, $used, $rows];
}

function cleanRequestedRefundSeats(array $seats, array $refundable, array $used, string $missingMessage): array {
    $cleanSeats = [];
    foreach ($seats as $seat) {
        $row = (int)($seat['row'] ?? 0);
        $number = (int)($seat['number'] ?? 0);
        $key = "$row:$number";
        if ($row < 1 || $number < 1) {
            throw new Exception($missingMessage);
        }
        if (!empty($used[$key])) {
            throw new Exception('Seat ' . seatLabel($row, $number) . ' has already been used and cannot be refunded or cancelled.');
        }
        if (empty($refundable[$key])) {
            throw new Exception($missingMessage);
        }
        $cleanSeats[$key] = ['row' => $row, 'number' => $number];
    }
    if (!$cleanSeats) {
        throw new Exception('Choose at least one unused seat.');
    }
    return $cleanSeats;
}

/** Auto-cancel stale unpaid cash reservations so they cannot hold seats forever. */
function expireStaleCashBookings(PDO $pdo): void {
    try {
        if (!tableExists($pdo, 'bookings') || !tableExists($pdo, 'booking_seats')) return;
        $stmt = $pdo->prepare("
            SELECT b.id, b.user_id, b.showtime_id
            FROM bookings b
            WHERE b.status = 'confirmed'
              AND b.payment_status = 'pending'
              AND b.payment_method = 'cash'
              AND b.created_at < (NOW() - INTERVAL " . PENDING_CASH_EXPIRY_MINUTES . " MINUTE)
            LIMIT 50
        ");
        $stmt->execute();
        $bookings = $stmt->fetchAll();
        if (!$bookings) return;

        foreach ($bookings as $booking) {
            $bookingId = (int)$booking['id'];
            $pdo->beginTransaction();
            $lock = $pdo->prepare("SELECT id FROM bookings WHERE id = ? AND status='confirmed' AND payment_status='pending' AND payment_method='cash' FOR UPDATE");
            $lock->execute([$bookingId]);
            if (!$lock->fetch()) {
                $pdo->rollBack();
                continue;
            }

            $seatStmt = $pdo->prepare("SELECT seat_row, seat_number FROM booking_seats WHERE booking_id = ? ORDER BY seat_row, seat_number");
            $seatStmt->execute([$bookingId]);
            foreach ($seatStmt->fetchAll() as $seat) {
                archiveCancelledBookingSeat(
                    $pdo,
                    $bookingId,
                    (int)$booking['showtime_id'],
                    null,
                    (int)$seat['seat_row'],
                    (int)$seat['seat_number'],
                    0.00,
                    'cash_booking_expired'
                );
            }

            $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ?")->execute([$bookingId]);
            $pdo->prepare("
                UPDATE bookings
                SET status='cancelled', payment_status='failed', ticket_status='cancelled', total_price=0
                WHERE id=?
            ")->execute([$bookingId]);
            if (!empty($booking['user_id'])) {
                addNotification($pdo, (int)$booking['user_id'], 'Cash reservation expired', "Booking #$bookingId was cancelled because the cash payment was not verified within " . PENDING_CASH_EXPIRY_MINUTES . " minutes.");
            }
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Expiry is best-effort; it must not break normal browsing.
    }
}

/** Core transaction used by both legacy book and new create_payment. */
function createBookingTransaction(PDO $pdo, array $data, string $paymentMethod): array {
    $stId        = (int)($data['showtime_id'] ?? 0);
    $seats       = $data['seats']        ?? [];
    $movieTitle  = trim($data['movie_title']  ?? '');
    $moviePoster = trim($data['movie_poster'] ?? '');

    if (!$stId)            throw new Exception('showtime_id required.');
    if (empty($seats))     throw new Exception('Select at least one seat.');
    if (!is_array($seats)) throw new Exception('Invalid seat data.');
    if (!$movieTitle)      throw new Exception('movie_title required.');

    $allowedMethods = ['cash', 'simulated_card', 'simulated_wallet'];
    if (!in_array($paymentMethod, $allowedMethods, true)) {
        throw new Exception('Invalid payment method.');
    }

    $pdo->beginTransaction();
    try {
        cleanupSeatLocks($pdo, $stId);

        $st = $pdo->prepare("SELECT s.*, h.total_rows, h.seats_per_row FROM showtimes s JOIN halls h ON s.hall_id = h.id WHERE s.id = ? AND s.show_datetime > NOW() FOR UPDATE");
        $st->execute([$stId]);
        $show = $st->fetch();
        if (!$show) throw new Exception('Showtime not found or already passed.');

        if ($paymentMethod === 'cash' && strtotime($show['show_datetime']) > time() + 86400) {
            throw new Exception('Cash at Cinema is only available during the final 24 hours before the screening. Please choose simulated card/wallet or a nearer showtime.');
        }

        $hasShowtimeSeatCol = columnExists($pdo, 'booking_seats', 'showtime_id');

        // Check every seat is valid and still free.
        $checkBooked = $pdo->prepare("\n            SELECT 1 FROM booking_seats bs\n            JOIN bookings b ON bs.booking_id = b.id\n            WHERE b.showtime_id = ? AND b.status = 'confirmed'\n              AND bs.seat_row = ? AND bs.seat_number = ?\n            LIMIT 1\n        ");
        $checkLock = $pdo->prepare("\n            SELECT user_id FROM seat_locks\n            WHERE showtime_id = ? AND seat_row = ? AND seat_number = ? AND expires_at > NOW()\n            LIMIT 1\n        ");

        $cleanSeats = [];
        foreach ($seats as $seat) {
            $r = (int)($seat['row'] ?? 0);
            $n = (int)($seat['number'] ?? 0);
            if ($r < 1 || $n < 1 || $r > (int)$show['total_rows'] || $n > (int)$show['seats_per_row']) {
                throw new Exception('Invalid seat coordinates.');
            }
            $checkBooked->execute([$stId, $r, $n]);
            if ($checkBooked->fetch()) throw new Exception('Seat ' . seatLabel($r, $n) . ' was just taken.');

            // Allow the current user to finish seats they already selected; block seats selected/booked by other users.
            $checkLock->execute([$stId, $r, $n]);
            $lock = $checkLock->fetch();
            if ($lock && (int)$lock['user_id'] !== (int)$_SESSION['user_id']) {
                throw new Exception('Seat ' . seatLabel($r, $n) . ' is already reserved by another user.');
            }
            $cleanSeats[] = ['row' => $r, 'number' => $n];
        }

        $total = round(((float)$show['price']) * count($cleanSeats), 2);
        $paymentStatus = $paymentMethod === 'cash' ? 'pending' : 'paid';
        $paymentRef = strtoupper($paymentMethod) . '-' . date('YmdHis') . '-' . random_int(1000, 9999);
        $paidAt = $paymentStatus === 'paid' ? date('Y-m-d H:i:s') : null;
        $cashExpiresAt = $paymentMethod === 'cash'
            ? date('Y-m-d H:i:s', time() + PENDING_CASH_EXPIRY_MINUTES * 60)
            : null;

        $ins = $pdo->prepare("\n            INSERT INTO bookings\n                (user_id, showtime_id, movie_title, movie_poster, total_price, status, payment_status, payment_method, payment_reference, paid_at, ticket_status)\n            VALUES (?, ?, ?, ?, ?, 'confirmed', ?, ?, ?, ?, 'valid')\n        ");
        $ins->execute([$_SESSION['user_id'], $stId, $movieTitle, $moviePoster ?: null, $total, $paymentStatus, $paymentMethod, $paymentRef, $paidAt]);
        $bookingId = (int)$pdo->lastInsertId();
        $ticketCode = makeTicketCode($bookingId);

        $updTicket = $pdo->prepare("UPDATE bookings SET ticket_code = ? WHERE id = ?");
        $updTicket->execute([$ticketCode, $bookingId]);

        $seatTickets = [];
        $hasSeatTicketCol = columnExists($pdo, 'booking_seats', 'seat_ticket_code');
        if ($hasShowtimeSeatCol && $hasSeatTicketCol) {
            $seatIns = $pdo->prepare("INSERT INTO booking_seats (booking_id, showtime_id, seat_row, seat_number, seat_ticket_code) VALUES (?, ?, ?, ?, ?)");
            foreach ($cleanSeats as $seat) {
                $seatCode = makeSeatTicketCode($bookingId, (int)$seat['row'], (int)$seat['number']);
                $seatLabel = seatLabel((int)$seat['row'], (int)$seat['number']);
                $seatIns->execute([$bookingId, $stId, $seat['row'], $seat['number'], $seatCode]);
                $seatTickets[] = ['row'=>(int)$seat['row'], 'number'=>(int)$seat['number'], 'seat'=>$seatLabel, 'ticket_code'=>$seatCode, 'qr_text'=>makeSeatQrText($bookingId, $seatCode, $seatLabel, $paymentRef)];
            }
        } elseif ($hasShowtimeSeatCol) {
            $seatIns = $pdo->prepare("INSERT INTO booking_seats (booking_id, showtime_id, seat_row, seat_number) VALUES (?, ?, ?, ?)");
            foreach ($cleanSeats as $seat) $seatIns->execute([$bookingId, $stId, $seat['row'], $seat['number']]);
        } else {
            $seatIns = $pdo->prepare("INSERT INTO booking_seats (booking_id, seat_row, seat_number) VALUES (?, ?, ?)");
            foreach ($cleanSeats as $seat) $seatIns->execute([$bookingId, $seat['row'], $seat['number']]);
        }

        // Clear user's temporary seat holds for these seats after the booking is saved.
        $delLock = $pdo->prepare("DELETE FROM seat_locks WHERE showtime_id = ? AND seat_row = ? AND seat_number = ? AND user_id = ?");
        foreach ($cleanSeats as $seat) $delLock->execute([$stId, $seat['row'], $seat['number'], $_SESSION['user_id']]);

        addNotification($pdo, (int)$_SESSION['user_id'], 'Booking confirmed', "Your booking #$bookingId for $movieTitle has been created.");

        $pdo->commit();
        return [
            'id' => $bookingId,
            'booking_id' => $bookingId,
            'total_price' => $total,
            'payment_status' => $paymentStatus,
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentRef,
            'ticket_code' => $ticketCode,
            'ticket_status' => 'valid',
            'show_datetime' => $show['show_datetime'],
            'cash_payment_deadline' => $cashExpiresAt ?: $show['show_datetime'],
            'cash_payment_expires_minutes' => $paymentMethod === 'cash' ? PENDING_CASH_EXPIRY_MINUTES : null,
            'qr_text' => "CINEMAX|BOOKING=$bookingId|CODE=$ticketCode",
            'seat_tickets' => $seatTickets ?? [],
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

expireStaleCashBookings($pdo);

// ── Router ────────────────────────────────────────────────────
switch ($action) {

/* ============================================================
   GOVERNORATES
   Returns all Egypt governorates that have at least one cinema.
   GET api.php?action=governorates
   ============================================================ */
case 'governorates':
    try {
        $stmt = $pdo->query("
            SELECT g.id, g.name_en, g.name_ar, g.slug,
                   COUNT(c.id) AS cinema_count
            FROM governorates g
            LEFT JOIN cinemas c ON c.governorate_id = g.id
            GROUP BY g.id
            HAVING cinema_count > 0
            ORDER BY g.name_en ASC
        ");
        ok(['governorates' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   CINEMAS
   Returns all cinemas, optionally filtered by governorate.
   GET api.php?action=cinemas[&gov_id=1]
   ============================================================ */
case 'cinemas':
    $govId = (int)($_GET['gov_id'] ?? 0);
    try {
        $sql = "
            SELECT c.*, g.name_en AS gov_name,
                   COUNT(h.id) AS hall_count
            FROM cinemas c
            JOIN governorates g ON c.governorate_id = g.id
            LEFT JOIN halls h ON h.cinema_id = c.id
        ";
        $params = [];
        if ($govId) { $sql .= " WHERE c.governorate_id = ?"; $params[] = $govId; }
        $sql .= " GROUP BY c.id ORDER BY g.name_en ASC, c.name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok(['cinemas' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   MOVIE CINEMAS
   Returns governorates → cinemas that have future showtimes
   for a given TMDB movie ID. Powers the booking accordion.
   GET api.php?action=movie_cinemas&tmdb_id=533535
   ============================================================ */
case 'movie_cinemas':
    $tmdbId = (int)($_GET['tmdb_id'] ?? $data['tmdb_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                g.id          AS gov_id,
                g.name_en     AS gov_name,
                g.name_ar     AS gov_name_ar,
                c.id          AS cinema_id,
                c.name        AS cinema_name,
                c.location    AS cinema_location,
                MIN(s.price)  AS min_price
            FROM showtimes s
            JOIN halls h        ON s.hall_id        = h.id
            JOIN cinemas c      ON h.cinema_id       = c.id
            JOIN governorates g ON c.governorate_id  = g.id
            WHERE s.tmdb_movie_id = ?
              AND s.show_datetime > NOW()
            GROUP BY g.id, g.name_en, g.name_ar, c.id, c.name, c.location
            ORDER BY g.name_en ASC, c.name ASC
        ");
        $stmt->execute([$tmdbId]);
        $rows = $stmt->fetchAll();

        // Group rows into: [ {gov_id, name, cinemas:[...]}, ... ]
        $govs = [];
        foreach ($rows as $r) {
            $gid = $r['gov_id'];
            if (!isset($govs[$gid])) {
                $govs[$gid] = [
                    'id'      => $gid,
                    'name_en' => $r['gov_name'],
                    'name_ar' => $r['gov_name_ar'],
                    'cinemas' => [],
                ];
            }
            $govs[$gid]['cinemas'][] = [
                'id'        => $r['cinema_id'],
                'name'      => $r['cinema_name'],
                'location'  => $r['cinema_location'],
                'min_price' => $r['min_price'],
            ];
        }
        ok(['governorates' => array_values($govs)]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   CINEMA MOVIES
   Returns the movies now showing at ONE cinema, each with its
   upcoming showtimes at that cinema only. Powers the "browse by
   cinema" screen.
   GET api.php?action=cinema_movies&cinema_id=3
   ============================================================ */
case 'cinema_movies':
    $cinemaId = (int)($_GET['cinema_id'] ?? $data['cinema_id'] ?? 0);
    if (!$cinemaId) fail('cinema_id required.');
    try {
        $cstmt = $pdo->prepare("
            SELECT c.id, c.name, c.location, g.name_en AS gov_name
            FROM cinemas c
            JOIN governorates g ON c.governorate_id = g.id
            WHERE c.id = ?
            LIMIT 1
        ");
        $cstmt->execute([$cinemaId]);
        $cinema = $cstmt->fetch();
        if (!$cinema) fail('Cinema not found.', 404);

        $stmt = $pdo->prepare("
            SELECT s.id AS showtime_id, s.tmdb_movie_id, s.movie_title, s.movie_poster,
                   s.show_datetime, s.price,
                   h.name AS hall_name, h.type AS hall_type,
                   h.total_rows, h.seats_per_row
            FROM showtimes s
            JOIN halls h ON s.hall_id = h.id
            WHERE h.cinema_id = ? AND s.show_datetime > NOW()
            ORDER BY s.movie_title ASC, s.show_datetime ASC
            LIMIT 300
        ");
        $stmt->execute([$cinemaId]);
        $rows = $stmt->fetchAll();

        // Group showtimes under each movie.
        $movies = [];
        foreach ($rows as $r) {
            $mid = $r['tmdb_movie_id'];
            if (!isset($movies[$mid])) {
                $movies[$mid] = [
                    'tmdb_movie_id' => (int)$mid,
                    'movie_title'   => $r['movie_title'],
                    'movie_poster'  => $r['movie_poster'],
                    'showtimes'     => [],
                ];
            }
            $movies[$mid]['showtimes'][] = [
                'showtime_id'   => (int)$r['showtime_id'],
                'show_datetime' => $r['show_datetime'],
                'price'         => $r['price'],
                'hall_name'     => $r['hall_name'],
                'hall_type'     => $r['hall_type'],
            ];
        }
        ok(['cinema' => $cinema, 'movies' => array_values($movies)]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   SHOWTIMES
   Returns future showtimes for a TMDB movie, optionally
   filtered to a specific cinema.
   GET api.php?action=showtimes&tmdb_id=533535[&cinema_id=3]
   ============================================================ */
case 'showtimes':
    $tmdbId   = (int)($_GET['tmdb_id']   ?? $data['tmdb_id']   ?? 0);
    $cinemaId = (int)($_GET['cinema_id'] ?? $data['cinema_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    try {
        $sql = "
            SELECT s.id, s.show_datetime, s.price,
                   h.name AS hall_name, h.type AS hall_type,
                   h.total_rows, h.seats_per_row,
                   c.id AS cinema_id, c.name AS cinema_name, c.location AS cinema_location
            FROM showtimes s
            JOIN halls h    ON s.hall_id   = h.id
            JOIN cinemas c  ON h.cinema_id = c.id
            WHERE s.tmdb_movie_id = ? AND s.show_datetime > NOW()
        ";
        $params = [$tmdbId];
        if ($cinemaId) { $sql .= " AND c.id = ?"; $params[] = $cinemaId; }
        $sql .= " ORDER BY s.show_datetime ASC LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        ok(['showtimes' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   SHOWTIME DETAILS
   Returns one showtime + every already-booked seat for it.
   The seat map uses booked_seats to mark seats as taken.
   GET api.php?action=showtime_details&showtime_id=12
   ============================================================ */
case 'showtime_details':
    $stId = (int)($_GET['showtime_id'] ?? $data['showtime_id'] ?? 0);
    if (!$stId) fail('showtime_id required.');
    try {
        cleanupSeatLocks($pdo, $stId);

        $s = $pdo->prepare("
            SELECT s.*, h.name AS hall_name, h.type AS hall_type,
                   h.total_rows, h.seats_per_row,
                   c.name AS cinema_name, c.location AS cinema_location
            FROM showtimes s
            JOIN halls h   ON s.hall_id   = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            WHERE s.id = ?
        ");
        $s->execute([$stId]);
        $showtime = $s->fetch();
        if (!$showtime) fail('Showtime not found.');

        // All seats currently confirmed for this showtime
        $bs = $pdo->prepare("
            SELECT bs.seat_row, bs.seat_number
            FROM booking_seats bs
            JOIN bookings b ON bs.booking_id = b.id
            WHERE b.showtime_id = ? AND b.status = 'confirmed'
        ");
        $bs->execute([$stId]);

        $locks = [];
        try {
            $ls = $pdo->prepare("
                SELECT seat_row, seat_number, expires_at,
                       IF(user_id = ?, 1, 0) AS is_mine
                FROM seat_locks
                WHERE showtime_id = ? AND expires_at > NOW()
            ");
            $ls->execute([(int)($_SESSION['user_id'] ?? 0), $stId]);
            $locks = $ls->fetchAll();
        } catch (Throwable $e) {
            // seat_locks exists in the final schema; ignore for older local DBs.
        }

        ok([
            'showtime'     => $showtime,
            'booked_seats' => $bs->fetchAll(),
            'locked_seats' => $locks,
        ]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   BOOK  ← MOST CRITICAL ACTION
   Creates a booking using a database TRANSACTION so two users
   can never get the same seat.
   POST { action:"book", showtime_id, tmdb_id, movie_title,
          movie_poster, seats:[{row,number},...] }
   ============================================================ */
case 'book':
    requireAuth();
    requireCsrf($data);
    fail('Legacy reservation endpoint is disabled. Please use checkout/payment.', 410);
    break;

/* ============================================================
   CREATE PAYMENT / CHECKOUT
   POST { action:"create_payment", showtime_id, movie_title,
          movie_poster, seats:[...], payment_method:"cash|simulated_card|simulated_wallet" }
   ============================================================ */
case 'create_payment':
    requireAuth();
    requireCsrf($data);
    $paymentMethod = trim($data['payment_method'] ?? 'simulated_card');
    $details = is_array($data['payment_details'] ?? null) ? $data['payment_details'] : [];
    if ($paymentMethod === 'simulated_card') {
        $cardNumber = preg_replace('/\D+/', '', $details['card_number'] ?? '');
        $cardName   = trim($details['card_name'] ?? '');
        $cardExpiryRaw = trim($details['card_expiry'] ?? '');
        $expiryDigits = preg_replace('/\D+/', '', $cardExpiryRaw);
        $cardExpiry = preg_match('/^\d{4}$/', $expiryDigits)
            ? substr($expiryDigits, 0, 2) . '/' . substr($expiryDigits, 2, 2)
            : $cardExpiryRaw;
        $expiryMonth = preg_match('/^\d{2}\/\d{2}$/', $cardExpiry) ? (int)substr($cardExpiry, 0, 2) : 0;
        $expiryYear = preg_match('/^\d{2}\/\d{2}$/', $cardExpiry) ? (2000 + (int)substr($cardExpiry, 3, 2)) : 0;
        $expiryEnd = $expiryYear && $expiryMonth ? strtotime(sprintf('%04d-%02d-01 +1 month -1 second', $expiryYear, $expiryMonth)) : false;
        $cardCvv    = preg_replace('/\D+/', '', $details['card_cvv'] ?? '');
        if (strlen($cardNumber) < 12 || !$cardName || !preg_match('/^\d{2}\/\d{2}$/', $cardExpiry) || $expiryMonth < 1 || $expiryMonth > 12 || !$expiryEnd || $expiryEnd < time() || strlen($cardCvv) < 3) {
            fail('Please enter valid card details.');
        }
    }
    if ($paymentMethod === 'simulated_wallet') {
        $walletNumber = preg_replace('/\D+/', '', $details['wallet_number'] ?? '');
        $walletPin    = preg_replace('/\D+/', '', $details['wallet_pin'] ?? '');
        if (strlen($walletNumber) < 10 || strlen($walletPin) < 4) {
            fail('Please enter valid wallet details.');
        }
    }
    try {
        $booking = createBookingTransaction($pdo, $data, $paymentMethod);
        ok(['booking' => $booking]);
    } catch (Throwable $e) { failThrowable($e); }
    break;

/* ============================================================
   LOCK SEATS
   POST { action:"lock_seats", showtime_id, seats:[{row,number},...] }
   Locks selected seats for 10 minutes while the user checks out.
   ============================================================ */
case 'lock_seats':
    requireAuth();
    requireCsrf($data);
    $stId = (int)($data['showtime_id'] ?? 0);
    $seats = $data['seats'] ?? [];
    if (!$stId || !is_array($seats) || empty($seats)) fail('Invalid seat reservation request.');
    try {
        cleanupSeatLocks($pdo, $stId);

        $showStmt = $pdo->prepare("
            SELECT h.total_rows, h.seats_per_row
            FROM showtimes s
            JOIN halls h ON s.hall_id = h.id
            WHERE s.id = ? AND s.show_datetime > NOW()
        ");
        $showStmt->execute([$stId]);
        $show = $showStmt->fetch();
        if (!$show) fail('Showtime not found or already passed.');

        $stmt = $pdo->prepare("\n            INSERT INTO seat_locks (showtime_id, seat_row, seat_number, user_id, expires_at)\n            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))\n            ON DUPLICATE KEY UPDATE\n              user_id = IF(expires_at < NOW() OR user_id = VALUES(user_id), VALUES(user_id), user_id),\n              expires_at = IF(expires_at < NOW() OR user_id = VALUES(user_id), VALUES(expires_at), expires_at)\n        ");
        $ownerCheck = $pdo->prepare("
            SELECT user_id
            FROM seat_locks
            WHERE showtime_id = ? AND seat_row = ? AND seat_number = ? AND expires_at > NOW()
        ");
        $bookedCheck = $pdo->prepare("
            SELECT 1
            FROM booking_seats bs
            JOIN bookings b ON bs.booking_id = b.id
            WHERE b.showtime_id = ? AND b.status = 'confirmed'
              AND bs.seat_row = ? AND bs.seat_number = ?
            LIMIT 1
        ");
        foreach ($seats as $seat) {
            $r = (int)($seat['row'] ?? 0); $n = (int)($seat['number'] ?? 0);
            if ($r < 1 || $n < 1 || $r > (int)$show['total_rows'] || $n > (int)$show['seats_per_row']) {
                fail('Invalid seat coordinates.');
            }
            $bookedCheck->execute([$stId, $r, $n]);
            if ($bookedCheck->fetch()) fail('Seat ' . seatLabel($r, $n) . ' is already booked.');

            $stmt->execute([$stId, $r, $n, $_SESSION['user_id']]);
            $ownerCheck->execute([$stId, $r, $n]);
            $owner = $ownerCheck->fetch();
            if (!$owner || (int)$owner['user_id'] !== (int)$_SESSION['user_id']) {
                fail('Seat ' . seatLabel($r, $n) . ' is already reserved by another user.');
            }
        }
        ok(['message' => 'Seats reserved for checkout.']);
    } catch (PDOException $e) { fail('Could not reserve seats. They may already be booked by another user.'); }
    break;

/* ============================================================
   RELEASE SEAT LOCKS
   POST { action:"release_seat_locks", showtime_id, seats:[{row,number},...] }
   Frees seats selected by the current user when they close the reservation.
   ============================================================ */
case 'release_seat_locks':
    requireAuth();
    requireCsrf($data);
    $stId = (int)($data['showtime_id'] ?? 0);
    $seats = $data['seats'] ?? [];
    if (!$stId || !is_array($seats)) fail('Invalid release request.');
    try {
        $stmt = $pdo->prepare("
            DELETE FROM seat_locks
            WHERE showtime_id = ?
              AND seat_row = ?
              AND seat_number = ?
              AND user_id = ?
        ");
        $released = 0;
        foreach ($seats as $seat) {
            $r = (int)($seat['row'] ?? 0);
            $n = (int)($seat['number'] ?? 0);
            if ($r < 1 || $n < 1) continue;
            $stmt->execute([$stId, $r, $n, $_SESSION['user_id']]);
            $released += $stmt->rowCount();
        }
        ok(['message' => 'Reservation closed.', 'released' => $released]);
    } catch (PDOException $e) { fail('Could not release reservation seats.'); }
    break;

/* ============================================================
   MY BOOKINGS
   Returns all bookings for the currently logged-in user.
   GET api.php?action=my_bookings
   ============================================================ */
case 'my_bookings':
    requireAuth();
    try {
        $s = $pdo->prepare("
            SELECT b.id, b.movie_title, b.movie_poster, b.total_price,
                   b.status, b.created_at, b.payment_status, b.payment_method,
                   b.payment_reference, b.paid_at, b.ticket_code, b.ticket_status,
                   s.show_datetime, s.price AS seat_price,
                   h.name AS hall_name, h.type AS hall_type,
                   c.id AS cinema_id, c.name AS cinema_name, c.location AS cinema_location,
                   c.governorate_id, g.name_en AS governorate_name
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h     ON s.hall_id     = h.id
            JOIN cinemas c   ON h.cinema_id   = c.id
            JOIN governorates g ON c.governorate_id = g.id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
        ");
        $s->execute([$_SESSION['user_id']]);
        $bookings = $s->fetchAll();

        $seatSelect = columnExists($pdo, 'booking_seats', 'seat_ticket_code')
            ? 'seat_row, seat_number, seat_ticket_code, seat_ticket_status'
            : 'seat_row, seat_number';
        $seatStmt = $pdo->prepare("
            SELECT $seatSelect
            FROM booking_seats WHERE booking_id = ?
            ORDER BY seat_row, seat_number
        ");
        $cancelledSeatStmt = tableExists($pdo, 'cancelled_booking_seats')
            ? $pdo->prepare("
                SELECT seat_row, seat_number, seat_ticket_code, seat_ticket_status, reason, refund_amount, cancelled_at
                FROM cancelled_booking_seats
                WHERE booking_id = ?
                ORDER BY cancelled_at DESC, seat_row, seat_number
            ")
            : null;
        foreach ($bookings as &$b) {
            $seatStmt->execute([$b['id']]);
            $b['seats'] = $seatStmt->fetchAll();
            foreach ($b['seats'] as &$seat) {
                if (!empty($seat['seat_ticket_code'])) {
                    $label = seatLabel((int)$seat['seat_row'], (int)$seat['seat_number']);
                    $seat['seat'] = $label;
                    $seat['qr_text'] = makeSeatQrText((int)$b['id'], (string)$seat['seat_ticket_code'], $label, $b['payment_reference'] ?? '');
                }
            }
            unset($seat);
            $b['cancelled_seats'] = [];
            if (!$b['seats'] && $cancelledSeatStmt) {
                $cancelledSeatStmt->execute([$b['id']]);
                $b['cancelled_seats'] = $cancelledSeatStmt->fetchAll();
                foreach ($b['cancelled_seats'] as &$seat) {
                    if (!empty($seat['seat_ticket_code'])) {
                        $label = seatLabel((int)$seat['seat_row'], (int)$seat['seat_number']);
                        $seat['seat'] = $label;
                        $seat['qr_text'] = makeSeatQrText((int)$b['id'], (string)$seat['seat_ticket_code'], $label, $b['payment_reference'] ?? '');
                    }
                }
                unset($seat);
            }
            $b['pending_refund_count'] = 0;
            if (tableExists($pdo, 'refund_requests')) {
                $refundStmt = $pdo->prepare("SELECT COUNT(*) FROM refund_requests WHERE booking_id = ? AND status = 'pending'");
                $refundStmt->execute([$b['id']]);
                $b['pending_refund_count'] = (int)$refundStmt->fetchColumn();
            }
        }
        ok(['bookings' => $bookings]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   GET COMMENTS
   Paginated reviews for a movie (by TMDB ID).
   GET api.php?action=get_comments&tmdb_id=533535&offset=0&limit=5
   ============================================================ */
case 'get_comments':
    $tmdbId = (int)($_GET['tmdb_id'] ?? 0);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $limit  = min(20, max(1, (int)($_GET['limit']  ?? 5)));
    if (!$tmdbId) fail('tmdb_id required.');
    try {
        $stmt = $pdo->prepare("
            SELECT c.id, c.body, c.rating, c.created_at, u.username,
                   ci.name AS cinema_name, g.name_en AS governorate_name
            FROM comments c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN cinemas ci ON c.cinema_id = ci.id
            LEFT JOIN governorates g ON c.governorate_id = g.id
            WHERE c.tmdb_movie_id = ?
            ORDER BY c.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$tmdbId]);

        $cnt = $pdo->prepare("SELECT COUNT(*) FROM comments WHERE tmdb_movie_id = ?");
        $cnt->execute([$tmdbId]);

        $avg = $pdo->prepare("SELECT ROUND(AVG(rating),1) FROM comments WHERE tmdb_movie_id = ? AND rating IS NOT NULL");
        $avg->execute([$tmdbId]);

        ok([
            'comments'   => $stmt->fetchAll(),
            'total'      => (int)$cnt->fetchColumn(),
            'avg_rating' => $avg->fetchColumn(),
        ]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   ADD COMMENT
   POST { action:"add_comment", tmdb_id:533535, body:"...", rating:4 }
   ============================================================ */
case 'add_comment':
    requireAuth();
    requireCsrf($data);
    $tmdbId = (int)($data['tmdb_id'] ?? 0);
    $body   = trim($data['body']     ?? '');
    $rating = isset($data['rating']) ? (int)$data['rating'] : null;
    $cinemaId = (int)($data['cinema_id'] ?? 0);
    $governorateId = (int)($data['governorate_id'] ?? 0);

    if (!$tmdbId)                                    fail('tmdb_id required.');
    if (strlen($body) < 3)                           fail('Comment too short (min 3 chars).');
    if ($rating !== null && ($rating < 1 || $rating > 5)) fail('Rating must be 1–5.');

    try {
        if ($cinemaId) {
            $locStmt = $pdo->prepare("SELECT governorate_id FROM cinemas WHERE id = ? LIMIT 1");
            $locStmt->execute([$cinemaId]);
            $cinemaGov = $locStmt->fetchColumn();
            if (!$cinemaGov) fail('Selected cinema was not found.');
            $governorateId = (int)$cinemaGov;
        } elseif ($governorateId) {
            $locStmt = $pdo->prepare("SELECT id FROM governorates WHERE id = ? LIMIT 1");
            $locStmt->execute([$governorateId]);
            if (!$locStmt->fetchColumn()) fail('Selected region was not found.');
        }

        $existing = $pdo->prepare("SELECT id FROM comments WHERE user_id = ? AND tmdb_movie_id = ? LIMIT 1");
        $existing->execute([$_SESSION['user_id'], $tmdbId]);
        $existingId = (int)$existing->fetchColumn();
        if ($existingId) {
            $stmt = $pdo->prepare("
                UPDATE comments
                SET cinema_id = ?, governorate_id = ?, body = ?, rating = ?, created_at = CURRENT_TIMESTAMP
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$cinemaId ?: null, $governorateId ?: null, $body, $rating, $existingId, $_SESSION['user_id']]);
            ok(['message' => 'Review updated.']);
        }

        $stmt = $pdo->prepare("
            INSERT INTO comments (user_id, tmdb_movie_id, cinema_id, governorate_id, body, rating)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $tmdbId, $cinemaId ?: null, $governorateId ?: null, $body, $rating]);
        ok(['message' => 'Review posted.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   LOGIN
   POST { action:"login", email:"x@x.com", password:"..." }
   ============================================================ */
case 'login':
    $email    = trim($data['email']    ?? '');
    $password = $data['password'] ?? '';
    if (!$email || !$password) fail('Email and password are required.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
    try {
        $s = $pdo->prepare("
            SELECT u.id, u.username, u.password_hash, u.role, u.is_blocked, u.governorate_id,
                   g.name_en AS governorate_name
            FROM users u
            LEFT JOIN governorates g ON u.governorate_id = g.id
            WHERE u.email = ?
            LIMIT 1
        ");
        $s->execute([$email]);
        $user = $s->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
            if (!empty($user['is_blocked'])) {
                fail('This account has been blocked. Please contact support.', 403);
            }

            // Enforce separated admin login doors.
            // The manager login accepts manager/full-admin accounts only.
            // The regional login accepts regional admin/staff accounts only.
            // Normal website login cannot be used with admin-only accounts.
            $role = $user['role'] ?? 'user';
            if ($clientCtx === 'manager_admin' && $role !== 'admin') {
                fail('Use the Regional Admin login for regional/staff accounts. Manager login accepts manager accounts only.', 403);
            }
            if ($clientCtx === 'regional_admin' && !in_array($role, ['regional_admin', 'staff'], true)) {
                fail('Use the Manager Admin login for manager accounts. Regional login accepts regional admin or staff accounts only.', 403);
            }
            if ($clientCtx === '' && in_array($role, ['admin', 'regional_admin', 'staff'], true)) {
                fail('Admin accounts must sign in from the admin page, not the normal user login.', 403);
            }

            session_regenerate_id(true);
            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'] ?? 'user';
            if (!empty($user['governorate_id'])) {
                $_SESSION['governorate_id'] = (int)$user['governorate_id'];
                $_SESSION['governorate_name'] = $user['governorate_name'] ?? '';
            } else {
                unset($_SESSION['governorate_id'], $_SESSION['governorate_name']);
            }
            $_SESSION['governorate_ids'] = loadAdminGovernorateIds(
                $pdo, (int)$user['id'], !empty($user['governorate_id']) ? (int)$user['governorate_id'] : null
            );
            $govNames = [];
            if (!empty($_SESSION['governorate_ids']) && tableExists($pdo, 'governorates')) {
                $ph = implode(',', array_fill(0, count($_SESSION['governorate_ids']), '?'));
                $gn = $pdo->prepare("SELECT name_en FROM governorates WHERE id IN ($ph) ORDER BY name_en");
                $gn->execute($_SESSION['governorate_ids']);
                $govNames = $gn->fetchAll(PDO::FETCH_COLUMN);
            }
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            ok(['user' => [
                'id'       => $user['id'],
                'username' => $user['username'],
                'email'    => $email,
                'role'     => $_SESSION['role'],
                'governorate_id' => $_SESSION['governorate_id'] ?? null,
                'governorate_name' => $_SESSION['governorate_name'] ?? null,
                'governorate_ids' => $_SESSION['governorate_ids'] ?? [],
                'governorate_names' => $govNames,
            ]]);
        }
        fail('Invalid email or password.');
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   REGISTER
   POST { action:"register", username:"ali", email:"...", password:"..." }
   ============================================================ */
case 'register':
    $username = trim($data['username'] ?? '');
    $email    = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $data['password'] ?? '';

    if (strlen($username) < 3)                        fail('Username must be at least 3 characters.');
    requireMaxLength($username, 60, 'Full name');
    requireMaxLength($email, 120, 'Email');
    requireStrongPassword($password, 'Password');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))   fail('Invalid email address.');
    requirePublicEmail($email);

    try {
        $s = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        $s->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $userId = (int)$pdo->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['user_id']  = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role']     = 'user';
        unset($_SESSION['governorate_id'], $_SESSION['governorate_name']);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        ok(['user' => ['id' => $userId, 'username' => $username, 'email' => $email, 'role' => 'user', 'governorate_id' => null, 'governorate_name' => null]]);
    } catch (PDOException $e) {
        fail('Email or username already taken.');
    }
    break;


/* ============================================================
   FORGOT PASSWORD
   Options:
   - change: send a verification code to the real account email.
   - remember: creates an admin request. Secure systems cannot reveal old passwords.
   ============================================================ */
case 'forgot_password_request':
    $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $mode = trim($data['mode'] ?? 'change');
    $deliveryChannel = trim($data['delivery_channel'] ?? 'email');
    $contactPhone = trim($data['phone'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
    requirePublicEmail($email);
    if (!in_array($mode, ['change', 'remember'], true)) fail('Invalid password recovery option.');
    [$deliveryChannel, $contactPhone] = requireRecoveryChannel($deliveryChannel, $contactPhone);
    try {
        $stmt = $pdo->prepare("SELECT id, username, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Generic response prevents account discovery. If the email exists, we process it.
        $response = ['message' => 'If this email exists in CINEMAX, the recovery request has been processed.'];
        if ($user) {
            if ($mode === 'change') {
                if ($deliveryChannel === 'email') {
                    $mailInfo = sendPasswordResetCode($pdo, $user);
                    $note = $mailInfo['mail_sent']
                        ? 'Verification code was generated and an email attempt was made. Admin can also send the visible code manually.'
                        : 'Verification code generated for manual delivery by admin through Gmail, Outlook, Yahoo, or another verified email channel.';
                } else {
                    $code = createPasswordResetCode($pdo, (int)$user['id'], $email);
                    $mailInfo = [
                        'mail_sent' => false,
                        'manual_code' => $code,
                        'expires_at' => date('Y-m-d H:i:s', time() + 10 * 60),
                        'manual_message' => "CINEMAX password reset code for {$email}: {$code}. This code expires in 10 minutes."
                    ];
                    $note = "Verification code generated for manual delivery by WhatsApp/SMS to {$contactPhone}. Admin must verify that this number belongs to the user before sending the code.";
                }

                $pdo->prepare("INSERT INTO forgot_password_requests (user_id, email, request_type, status, admin_note, admin_visible_code, preferred_channel, contact_phone, code_expires_at) VALUES (?, ?, 'change', 'code_sent', ?, ?, ?, ?, ?)")
                    ->execute([
                        (int)$user['id'],
                        $email,
                        $note,
                        $mailInfo['manual_code'],
                        $deliveryChannel,
                        $deliveryChannel === 'phone' ? $contactPhone : null,
                        $mailInfo['expires_at']
                    ]);
                $response['message'] = $deliveryChannel === 'phone'
                    ? 'A verification code request was created. The admin will verify your phone number and send the code by WhatsApp/SMS.'
                    : 'A verification code request was created. The admin will send the code to your registered email, then you can enter it here to change your password.';
            } else {
                $rememberNote = $deliveryChannel === 'phone'
                    ? "User requested password help through WhatsApp/SMS at {$contactPhone}. Old passwords cannot be shown; admin should ask the user to reset the password securely."
                    : 'User requested password help through email. Old passwords cannot be shown; admin should ask the user to reset the password securely.';
                $pdo->prepare("INSERT INTO forgot_password_requests (user_id, email, request_type, status, admin_note, preferred_channel, contact_phone) VALUES (?, ?, 'remember', 'pending', ?, ?, ?)")
                    ->execute([(int)$user['id'], $email, $rememberNote, $deliveryChannel, $deliveryChannel === 'phone' ? $contactPhone : null]);
                // Notify manager/admin accounts inside the system.
                $admins = $pdo->query("SELECT id FROM users WHERE role='admin' AND is_blocked=0")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $adminId) {
                    addNotification($pdo, (int)$adminId, 'Forgot password request', "A user requested password help for {$email}.");
                }
            }
        }
        ok($response);
    } catch (Throwable $e) { fail('Database error.'); }
    break;

case 'reset_password_with_code':
    $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $code = preg_replace('/\D+/', '', (string)($data['code'] ?? ''));
    $newPassword = (string)($data['new_password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
    requirePublicEmail($email);
    if (strlen($code) !== 6) fail('Enter the 6-digit verification code.');
    requireStrongPassword($newPassword, 'New password');
    throttlePasswordResetCodeAttempt($email);
    ensurePasswordResetAttemptColumns($pdo);
    try {
        $hasResetAttemptColumns = columnExists($pdo, 'password_reset_codes', 'failed_attempts')
            && columnExists($pdo, 'password_reset_codes', 'locked_at');
        $attemptSelect = $hasResetAttemptColumns
            ? ", COALESCE(prc.failed_attempts, 0) AS failed_attempts, prc.locked_at"
            : ", 0 AS failed_attempts, NULL AS locked_at";
        $stmt = $pdo->prepare("SELECT u.id AS user_id, prc.id AS code_id, prc.code_hash, prc.expires_at
                                      $attemptSelect
                               FROM password_reset_codes prc
                               JOIN users u ON u.id = prc.user_id
                               WHERE prc.email = ? AND prc.used_at IS NULL
                               ORDER BY prc.id DESC LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row && !empty($row['locked_at'])) {
            fail('Too many incorrect reset-code attempts. Request a new code.', 429);
        }
        if (!$row || strtotime($row['expires_at']) < time()) {
            fail('Invalid or expired verification code.');
        }
        if (!password_verify($code, $row['code_hash'])) {
            if ($hasResetAttemptColumns) {
                $attempts = (int)$row['failed_attempts'] + 1;
                if ($attempts >= 5) {
                    $pdo->prepare("UPDATE password_reset_codes SET failed_attempts=?, locked_at=NOW(), used_at=NOW() WHERE id=?")
                        ->execute([$attempts, (int)$row['code_id']]);
                } else {
                    $pdo->prepare("UPDATE password_reset_codes SET failed_attempts=? WHERE id=?")
                        ->execute([$attempts, (int)$row['code_id']]);
                }
            }
            fail('Invalid or expired verification code.');
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$row['user_id']]);
        $pdo->prepare("UPDATE password_reset_codes SET used_at = NOW() WHERE id = ?")->execute([(int)$row['code_id']]);
        $pdo->prepare("UPDATE forgot_password_requests SET status='resolved', resolved_at=NOW(), admin_note='Password changed by verification code.' WHERE user_id=? AND request_type='change' AND status IN ('pending','code_sent')")
            ->execute([(int)$row['user_id']]);
        $pdo->commit();
        clearPasswordResetCodeAttempts($email);
        ok(['message' => 'Password changed successfully. You can sign in with the new password.']);
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); fail('Database error.'); }
    break;

/* ============================================================
   LOGOUT / SESSION CHECK
   ============================================================ */
case 'logout':
    requireCsrf($data);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'] ?? false, $p['httponly'] ?? false);
    }
    session_destroy();
    ok(['message' => 'Logged out.']);
    break;

case 'session':
    $sessionUser = currentSessionUser($pdo);
    if ($sessionUser) {
        if (!empty($sessionUser['is_blocked'])) {
            clearAuthSession();
            fail('Your account is blocked.', 403);
        }
        $_SESSION['user_id']  = (int)$sessionUser['id'];
        $_SESSION['username'] = $sessionUser['username'];
        $_SESSION['role']     = $sessionUser['role'] ?? 'user';
        if (!empty($sessionUser['governorate_id'])) {
            $_SESSION['governorate_id'] = (int)$sessionUser['governorate_id'];
            $_SESSION['governorate_name'] = $sessionUser['governorate_name'] ?? '';
        } else {
            unset($_SESSION['governorate_id'], $_SESSION['governorate_name']);
        }
        $_SESSION['governorate_ids'] = loadAdminGovernorateIds(
            $pdo, (int)$sessionUser['id'], !empty($sessionUser['governorate_id']) ? (int)$sessionUser['governorate_id'] : null
        );
        // Region names for the full set (used by the admin header / scope label).
        $govNames = [];
        if (!empty($_SESSION['governorate_ids']) && tableExists($pdo, 'governorates')) {
            $ph = implode(',', array_fill(0, count($_SESSION['governorate_ids']), '?'));
            $gn = $pdo->prepare("SELECT name_en FROM governorates WHERE id IN ($ph) ORDER BY name_en");
            $gn->execute($_SESSION['governorate_ids']);
            $govNames = $gn->fetchAll(PDO::FETCH_COLUMN);
        }
        ok(['user' => [
            'id'       => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email'    => $sessionUser['email'] ?? '',
            'role'     => $_SESSION['role'] ?? 'user',
            'governorate_id' => $_SESSION['governorate_id'] ?? null,
            'governorate_name' => $_SESSION['governorate_name'] ?? null,
            'governorate_ids' => $_SESSION['governorate_ids'] ?? [],
            'governorate_names' => $govNames,
        ]]);
    }
    clearAuthSession();
    fail('Not logged in.', 401);
    break;

/* ============================================================
   SUPPORT
   POST { action:"support", name, email, subject, message }
   ============================================================ */
case 'support':
    requireCsrf($data);
    throttleSessionAction('support_form', 60);
    $name    = trim($data['name']    ?? '');
    $email   = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $subject = trim($data['subject'] ?? '');
    $message = trim($data['message'] ?? '');
    $govId   = requireSupportGovernorateId($pdo, $data);
    if (!$name || !$subject || !$message || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('All fields are required and must be valid.');
    }
    requireMaxLength($name, 100, 'Name');
    requireMaxLength($email, 120, 'Email');
    requirePublicEmail($email);
    requireMaxLength($subject, 255, 'Subject');
    requireMaxLength($message, 5000, 'Message');
    try {
        $supportTable = supportMessagesTable($pdo);
        $pdo->prepare("INSERT INTO $supportTable (user_id, governorate_id, name, email, subject, message) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$_SESSION['user_id'] ?? null, $govId, $name, $email, $subject, $message]);
        ok(['message' => 'Support request submitted. We\'ll get back to you soon.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   SYNC STATUS — when was the last TMDB sync?
   GET api.php?action=sync_status
   ============================================================ */
case 'sync_status':
    try {
        $last = $pdo->query("SELECT * FROM sync_log ORDER BY synced_at DESC LIMIT 1")->fetch();
        ok(['last_sync' => $last ?: null]);
    } catch (PDOException $e) {
        ok(['last_sync' => null]);
    }
    break;

/* ============================================================
   USER NOTIFICATIONS
   GET  my_notifications
   POST mark_notification_read { notification_id }
   ============================================================ */
case 'my_notifications':
    requireAuth();
    try {
        $stmt = $pdo->prepare("
            SELECT id, title, message, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$_SESSION['user_id']]);
        ok(['notifications' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'mark_notification_read':
    requireAuth();
    requireCsrf($data);
    $notificationId = (int)($data['notification_id'] ?? 0);
    if (!$notificationId) fail('notification_id required.');
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notificationId, $_SESSION['user_id']]);
        ok(['message' => 'Notification marked as read.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   SUPPORT TICKETS
   ============================================================ */
case 'create_support_ticket':
    requireAuth();
    requireCsrf($data);
    throttleSessionAction('tracked_support_ticket', 45);
    $name    = trim($data['name']    ?? ($_SESSION['username'] ?? ''));
    $email   = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $subject = trim($data['subject'] ?? '');
    $message = trim($data['message'] ?? '');
    $govId   = requireSupportGovernorateId($pdo, $data);
    if (!$name || !$subject || !$message || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('All fields are required and must be valid.');
    }
    requireMaxLength($name, 100, 'Name');
    requireMaxLength($email, 120, 'Email');
    requirePublicEmail($email);
    requireMaxLength($subject, 255, 'Subject');
    requireMaxLength($message, 5000, 'Message');
    try {
        $supportTable = supportMessagesTable($pdo);
        $stmt = $pdo->prepare("INSERT INTO $supportTable (user_id, governorate_id, name, email, subject, message) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'] ?? null, $govId, $name, $email, $subject, $message]);
        ok(['message' => 'Support ticket created.', 'ticket_id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'my_support_tickets':
    requireAuth();
    try {
        $supportTable = supportMessagesTable($pdo);
        $stmt = $pdo->prepare("
            SELECT t.id, t.subject, t.message, t.status, t.created_at,
                   g.name_en AS governorate_name
            FROM $supportTable t
            LEFT JOIN governorates g ON t.governorate_id = g.id
            WHERE t.user_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $tickets = $stmt->fetchAll();

        $replyStmt = $pdo->prepare("
            SELECT r.ticket_id, r.message, r.created_at, u.username AS admin_name
            FROM support_replies r
            JOIN users u ON r.admin_id = u.id
            WHERE r.ticket_id = ?
            ORDER BY r.created_at ASC
        ");
        foreach ($tickets as &$ticket) {
            $replyStmt->execute([$ticket['id']]);
            $ticket['replies'] = $replyStmt->fetchAll();
        }
        ok(['tickets' => $tickets]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_support_tickets':
    requireOperationalAdmin();
    try {
        $supportTable = supportMessagesTable($pdo);
        $whereSql = '';
        $params = [];
        if (isRegionalAdmin()) {
            $ids = regionalAdminGovernorateIds();
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $whereSql = "WHERE t.governorate_id IN ($ph)";
            $params = $ids;
        }
        $stmt = $pdo->prepare("
            SELECT t.*, u.username, g.name_en AS governorate_name
            FROM $supportTable t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN governorates g ON t.governorate_id = g.id
            $whereSql
            ORDER BY t.created_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $replyStmt = $pdo->prepare("
            SELECT r.ticket_id, r.message, r.created_at, u.username AS admin_name
            FROM support_replies r
            JOIN users u ON r.admin_id = u.id
            WHERE r.ticket_id = ?
            ORDER BY r.created_at ASC
        ");
        foreach ($rows as &$ticket) {
            $replyStmt->execute([$ticket['id']]);
            $ticket['replies'] = $replyStmt->fetchAll();
        }
        unset($ticket);

        ok(['tickets' => $rows]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_reply_ticket':
    requireOperationalAdmin();
    requireCsrf($data);
    $ticketId = (int)($data['ticket_id'] ?? 0);
    $message  = trim($data['message'] ?? '');
    if (!$ticketId || !$message) fail('ticket_id and message are required.');
    try {
        $supportTable = supportMessagesTable($pdo);
        adminSupportTicketRow($pdo, $ticketId);
        $stmt = $pdo->prepare("INSERT INTO support_replies (ticket_id, admin_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$ticketId, $_SESSION['user_id'], $message]);
        $pdo->prepare("UPDATE $supportTable SET status='pending' WHERE id=?")->execute([$ticketId]);

        $owner = $pdo->prepare("SELECT user_id FROM $supportTable WHERE id=?");
        $owner->execute([$ticketId]);
        $userId = (int)$owner->fetchColumn();
        if ($userId) addNotification($pdo, $userId, 'Support replied', "Your support ticket #$ticketId has a new reply.");

        ok(['message' => 'Reply added.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_close_ticket':
    requireOperationalAdmin();
    requireCsrf($data);
    $ticketId = (int)($data['ticket_id'] ?? 0);
    if (!$ticketId) fail('ticket_id required.');
    try {
        $supportTable = supportMessagesTable($pdo);
        adminSupportTicketRow($pdo, $ticketId);
        $pdo->prepare("UPDATE $supportTable SET status='closed' WHERE id=?")->execute([$ticketId]);
        ok(['message' => 'Ticket closed.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;



/* ============================================================
   CANCEL BOOKING
   POST { action:"cancel_booking", booking_id }
   User can cancel only their own future, unused booking.
   ============================================================ */
case 'cancel_booking':
    requireAuth();
    requireCsrf($data);
    $bookingId = (int)($data['booking_id'] ?? 0);
    if (!$bookingId) fail('booking_id required.');
    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("\n            SELECT b.*, s.show_datetime, s.price AS seat_price\n            FROM bookings b JOIN showtimes s ON b.showtime_id = s.id\n            WHERE b.id = ? AND b.user_id = ?\n            FOR UPDATE\n        ");
        $s->execute([$bookingId, $_SESSION['user_id']]);
        $b = $s->fetch();
        if (!$b) throw new Exception('Booking not found.');
        if ($b['status'] === 'cancelled') throw new Exception('Booking is already cancelled.');
        if (strtotime($b['show_datetime']) <= time()) throw new Exception('Past bookings cannot be cancelled.');
        if (($b['ticket_status'] ?? '') === 'used') throw new Exception('Used tickets cannot be cancelled.');
        if (($b['payment_status'] ?? '') === 'paid') throw new Exception('Paid bookings must use the refund request flow.');

        $seatStmt = $pdo->prepare("SELECT seat_row, seat_number FROM booking_seats WHERE booking_id = ? ORDER BY seat_row, seat_number");
        $seatStmt->execute([$bookingId]);
        $seatsToCancel = $seatStmt->fetchAll();
        $seatPrice = 0.00;
        foreach ($seatsToCancel as $seat) {
            archiveCancelledBookingSeat(
                $pdo,
                $bookingId,
                (int)$b['showtime_id'],
                (int)$_SESSION['user_id'],
                (int)$seat['seat_row'],
                (int)$seat['seat_number'],
                $seatPrice,
                'user_full_cancel'
            );
        }

        $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ?")->execute([$bookingId]);
        $paymentStatus = ($b['payment_status'] ?? '') === 'paid' ? 'refunded' : ($b['payment_status'] ?? 'pending');
        $u = $pdo->prepare("UPDATE bookings SET status='cancelled', ticket_status='cancelled', payment_status=?, total_price=0 WHERE id=?");
        $u->execute([$paymentStatus, $bookingId]);
        addNotification($pdo, (int)$_SESSION['user_id'], 'Booking cancelled', "Your booking #$bookingId has been cancelled.");
        $pdo->commit();
        ok(['message' => 'Booking cancelled.', 'payment_status' => $paymentStatus]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException) fail('Database error.');
        fail($e->getMessage() === 'Booking not found.' ? 'Booking not found.' : $e->getMessage(), $e->getMessage() === 'Booking not found.' ? 404 : 200);
    }
    break;

/* ============================================================
   CANCEL SELECTED BOOKING SEATS
   POST { action:"cancel_booking_seats", booking_id, seats:[{row,number}] }
   User can cancel one or more seats from their own future booking.
   If all seats are cancelled, the full booking becomes cancelled.
   ============================================================ */
case 'cancel_booking_seats':
    requireAuth();
    requireCsrf($data);
    $bookingId = (int)($data['booking_id'] ?? 0);
    $seats = $data['seats'] ?? [];
    if (!$bookingId || !is_array($seats) || empty($seats)) fail('Choose at least one seat to cancel.');

    try {
        $pdo->beginTransaction();

        $s = $pdo->prepare("
            SELECT b.*, s.show_datetime, s.price AS seat_price
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            WHERE b.id = ? AND b.user_id = ?
            FOR UPDATE
        ");
        $s->execute([$bookingId, $_SESSION['user_id']]);
        $booking = $s->fetch();

        if (!$booking) throw new Exception('Booking not found.');
        if ($booking['status'] === 'cancelled') throw new Exception('Booking is already cancelled.');
        if (strtotime($booking['show_datetime']) <= time()) throw new Exception('Past bookings cannot be cancelled.');
        if (($booking['ticket_status'] ?? '') === 'used') throw new Exception('Used tickets cannot be cancelled.');
        if (($booking['payment_status'] ?? '') === 'paid') throw new Exception('Paid bookings must use the refund request flow.');

        $currentStmt = $pdo->prepare("
            SELECT seat_row, seat_number
            FROM booking_seats
            WHERE booking_id = ?
            ORDER BY seat_row, seat_number
        ");
        $currentStmt->execute([$bookingId]);
        $currentSeats = $currentStmt->fetchAll();
        if (!$currentSeats) throw new Exception('No seats found for this booking.');

        $owned = [];
        foreach ($currentSeats as $seat) {
            $owned[(int)$seat['seat_row'] . ':' . (int)$seat['seat_number']] = true;
        }

        $cleanSeats = [];
        foreach ($seats as $seat) {
            $r = (int)($seat['row'] ?? 0);
            $n = (int)($seat['number'] ?? 0);
            $key = "$r:$n";
            if ($r < 1 || $n < 1 || empty($owned[$key])) {
                throw new Exception('Selected seat does not belong to this booking.');
            }
            $cleanSeats[$key] = ['row' => $r, 'number' => $n];
        }

        $cancelledCount = count($cleanSeats);
        $seatPrice = (float)($booking['seat_price'] ?? 0);
        $archiveAmount = 0.00;
        $refundAmount = 0.00;

        $deleteSeat = $pdo->prepare("
            DELETE FROM booking_seats
            WHERE booking_id = ? AND seat_row = ? AND seat_number = ?
        ");
        foreach ($cleanSeats as $seat) {
            archiveCancelledBookingSeat(
                $pdo,
                $bookingId,
                (int)$booking['showtime_id'],
                (int)$_SESSION['user_id'],
                $seat['row'],
                $seat['number'],
                $archiveAmount,
                'user_partial_cancel'
            );
            $deleteSeat->execute([$bookingId, $seat['row'], $seat['number']]);
        }

        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM booking_seats WHERE booking_id = ?");
        $remainingStmt->execute([$bookingId]);
        $remaining = (int)$remainingStmt->fetchColumn();

        if ($remaining <= 0) {
            $paymentStatus = ($booking['payment_status'] ?? '') === 'paid' ? 'refunded' : ($booking['payment_status'] ?? 'pending');
            $u = $pdo->prepare("
                UPDATE bookings
                SET status = 'cancelled',
                    ticket_status = 'cancelled',
                    payment_status = ?,
                    total_price = 0
                WHERE id = ?
            ");
            $u->execute([$paymentStatus, $bookingId]);
            addNotification($pdo, (int)$_SESSION['user_id'], 'Booking cancelled', "All seats for booking #$bookingId have been cancelled.");
            $message = 'All seats cancelled. Booking cancelled.';
        } else {
            $newTotal = max(0, $seatPrice * $remaining);
            $u = $pdo->prepare("UPDATE bookings SET total_price = ? WHERE id = ?");
            $u->execute([$newTotal, $bookingId]);
            addNotification($pdo, (int)$_SESSION['user_id'], 'Seats cancelled', "$cancelledCount seat(s) were cancelled from booking #$bookingId.");
            $message = 'Selected seat(s) cancelled.';
        }

        $pdo->commit();
        ok([
            'message' => $message,
            'cancelled_seats' => $cancelledCount,
            'remaining_seats' => $remaining,
            'refund_amount' => $refundAmount,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        failThrowable($e);
    }
    break;

/* ============================================================
   TICKET VERIFICATION — staff/admin
   GET/POST ticket_code
   ============================================================ */
/* ============================================================
   REQUEST REFUND FOR SELECTED SEATS
   POST { action:"request_refund", booking_id, seats:[{row,number}] }
   Paid bookings are not modified immediately. A regional/admin user
   must approve the refund from admin.html.
   ============================================================ */
case 'request_refund':
    requireAuth();
    requireCsrf($data);
    if (!tableExists($pdo, 'refund_requests')) {
        fail('Refund request table is missing. Import the latest schema.sql first.');
    }

    $bookingId = (int)($data['booking_id'] ?? 0);
    $seats = $data['seats'] ?? [];
    if (!$bookingId || !is_array($seats) || empty($seats)) fail('Choose at least one seat to refund.');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT b.*, s.show_datetime, s.price AS seat_price, c.governorate_id
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            WHERE b.id = ? AND b.user_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$bookingId, $_SESSION['user_id']]);
        $booking = $stmt->fetch();
        if (!$booking) throw new Exception('Booking not found.');
        if ($booking['status'] === 'cancelled') throw new Exception('Booking is already cancelled.');
        if (($booking['ticket_status'] ?? '') === 'used') throw new Exception('Used tickets cannot be refunded.');
        if (($booking['payment_status'] ?? '') !== 'paid') throw new Exception('Only paid bookings need refund approval.');
        if (strtotime($booking['show_datetime']) <= time()) throw new Exception('Past bookings cannot be refunded.');

        $pending = $pdo->prepare("SELECT COUNT(*) FROM refund_requests WHERE booking_id = ? AND status = 'pending'");
        $pending->execute([$bookingId]);
        if ((int)$pending->fetchColumn() > 0) {
            throw new Exception('A refund request for this booking is already awaiting admin review.');
        }

        [$refundableSeats, $usedSeats] = refundableSeatMaps($pdo, $bookingId, true);
        $cleanSeats = cleanRequestedRefundSeats(
            $seats,
            $refundableSeats,
            $usedSeats,
            'Selected seat does not belong to this booking.'
        );

        $refundAmount = ((float)$booking['seat_price']) * count($cleanSeats);
        $insert = $pdo->prepare("
            INSERT INTO refund_requests
              (booking_id, user_id, showtime_id, governorate_id, seats_json, refund_amount, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $insert->execute([
            $bookingId,
            $_SESSION['user_id'],
            (int)$booking['showtime_id'],
            (int)$booking['governorate_id'],
            json_encode(array_values($cleanSeats)),
            $refundAmount,
        ]);
        $requestId = (int)$pdo->lastInsertId();

        addNotification($pdo, (int)$_SESSION['user_id'], 'Refund requested', "Booking #$bookingId refund request is awaiting regional admin review.");
        $pdo->commit();
        ok([
            'message' => 'Refund request sent. Awaiting regional admin approval.',
            'refund_amount' => $refundAmount,
            'request_id' => $requestId,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        failThrowable($e);
    }
    break;

case 'refund_request_status':
    requireAuth();
    if (!tableExists($pdo, 'refund_requests')) {
        fail('Refund requests are not available.');
    }
    $requestId = (int)($_GET['request_id'] ?? $data['request_id'] ?? 0);
    if (!$requestId) fail('request_id required.');
    try {
        $stmt = $pdo->prepare("
            SELECT id, booking_id, status, refund_amount, processed_at
            FROM refund_requests
            WHERE id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestId, $_SESSION['user_id']]);
        $row = $stmt->fetch();
        if (!$row) fail('Refund request not found.', 404);
        ok(['request' => $row]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'verify_ticket':
    requireOperationalAdmin();
    $code = trim($_GET['ticket_code'] ?? $data['ticket_code'] ?? '');
    if (!$code) fail('ticket_code required.');
    try {
        $isSeatTicket = false;
        $seatLabel = null;
        if (columnExists($pdo, 'booking_seats', 'seat_ticket_code')) {
            $s = $pdo->prepare("
                SELECT b.id, b.movie_title, b.total_price, b.status, b.payment_status, b.payment_method,
                       b.ticket_code, b.ticket_status, b.created_at, s.show_datetime, h.name AS hall_name,
                       c.name AS cinema_name, c.governorate_id, g.name_en AS governorate_name, u.username, u.email,
                       bs.seat_row, bs.seat_number, bs.seat_ticket_code, bs.seat_ticket_status
                FROM booking_seats bs
                JOIN bookings b ON bs.booking_id = b.id
                JOIN users u ON b.user_id = u.id
                JOIN showtimes s ON b.showtime_id = s.id
                JOIN halls h ON s.hall_id = h.id
                JOIN cinemas c ON h.cinema_id = c.id
                JOIN governorates g ON c.governorate_id = g.id
                WHERE bs.seat_ticket_code = ?
                LIMIT 1
            ");
            $s->execute([$code]);
            $ticket = $s->fetch();
            if ($ticket) {
                $isSeatTicket = true;
                $seatLabel = seatLabel((int)$ticket['seat_row'], (int)$ticket['seat_number']);
                $ticket['seats'] = [[
                    'seat_row' => (int)$ticket['seat_row'],
                    'seat_number' => (int)$ticket['seat_number'],
                    'seat' => $seatLabel,
                    'seat_ticket_code' => $ticket['seat_ticket_code'],
                    'seat_ticket_status' => $ticket['seat_ticket_status'],
                ]];
            }
        }

        if (empty($ticket)) {
            $s = $pdo->prepare("\n            SELECT b.id, b.movie_title, b.total_price, b.status, b.payment_status, b.payment_method,\n                   b.ticket_code, b.ticket_status, b.created_at, s.show_datetime, h.name AS hall_name,\n                   c.name AS cinema_name, c.governorate_id, g.name_en AS governorate_name, u.username, u.email\n            FROM bookings b\n            JOIN users u ON b.user_id = u.id\n            JOIN showtimes s ON b.showtime_id = s.id\n            JOIN halls h ON s.hall_id = h.id\n            JOIN cinemas c ON h.cinema_id = c.id\n            JOIN governorates g ON c.governorate_id = g.id\n            WHERE b.ticket_code = ?\n        ");
            $s->execute([$code]);
            $ticket = $s->fetch();
            if (!$ticket) fail('Ticket not found.', 404);
            $seatSelect = columnExists($pdo, 'booking_seats', 'seat_ticket_code')
                ? 'seat_row, seat_number, seat_ticket_code, seat_ticket_status'
                : 'seat_row, seat_number';
            $seatStmt = $pdo->prepare("SELECT $seatSelect FROM booking_seats WHERE booking_id=? ORDER BY seat_row, seat_number");
            $seatStmt->execute([$ticket['id']]);
            $ticket['seats'] = $seatStmt->fetchAll();
        }

        enforceRegionalGovernorateAccess((int)$ticket['governorate_id']);
        $showtimeTs = strtotime((string)$ticket['show_datetime']);
        $isExpired = $showtimeTs !== false && $showtimeTs < time();
        if ($ticket['status'] !== 'confirmed' || $ticket['ticket_status'] === 'cancelled') {
            $ticket['validity_state'] = 'cancelled';
            $ticket['validity_message'] = 'Booking was cancelled.';
        } elseif ($ticket['payment_status'] !== 'paid') {
            $ticket['validity_state'] = 'awaiting_payment';
            $ticket['validity_message'] = 'Payment is not verified yet.';
        } elseif ($isSeatTicket && ($ticket['seat_ticket_status'] ?? '') === 'used') {
            $ticket['validity_state'] = 'used';
            $ticket['validity_message'] = 'This seat ticket was already used.';
        } elseif (!$isSeatTicket && $ticket['ticket_status'] === 'used') {
            $ticket['validity_state'] = 'used';
            $ticket['validity_message'] = 'Ticket was already used.';
        } elseif ($isExpired) {
            $ticket['validity_state'] = 'expired';
            $ticket['validity_message'] = 'Screening time has passed.';
        } elseif ($isSeatTicket && ($ticket['seat_ticket_status'] ?? '') === 'valid') {
            $ticket['validity_state'] = 'valid';
            $ticket['validity_message'] = 'Seat ticket is valid for this screening.';
        } elseif (!$isSeatTicket && $ticket['ticket_status'] === 'valid') {
            $ticket['validity_state'] = 'valid';
            $ticket['validity_message'] = 'Ticket is valid for this screening.';
        } else {
            $ticket['validity_state'] = 'invalid';
            $ticket['validity_message'] = 'Ticket is not usable.';
        }
        $ticket['is_seat_ticket'] = $isSeatTicket;
        $ticket['seat_label'] = $seatLabel;
        $ticket['scanned_code'] = $code;
        $ticket['is_valid'] = $ticket['validity_state'] === 'valid';
        ok(['ticket' => $ticket]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'mark_ticket_used':
    requireOperationalAdmin();
    requireCsrf($data);
    $code = trim($data['ticket_code'] ?? '');
    if (!$code) fail('ticket_code required.');
    try {
        if (columnExists($pdo, 'booking_seats', 'seat_ticket_code')) {
            $s = $pdo->prepare("
                SELECT b.id, b.user_id, b.status, b.ticket_status, b.payment_status, s.show_datetime, c.governorate_id,
                       bs.id AS booking_seat_id, bs.seat_row, bs.seat_number, bs.seat_ticket_status
                FROM booking_seats bs
                JOIN bookings b ON bs.booking_id = b.id
                JOIN showtimes s ON b.showtime_id = s.id
                JOIN halls h ON s.hall_id = h.id
                JOIN cinemas c ON h.cinema_id = c.id
                WHERE bs.seat_ticket_code = ?
                LIMIT 1
            ");
            $s->execute([$code]);
            $seatTicket = $s->fetch();
            if ($seatTicket) {
                enforceRegionalGovernorateAccess((int)$seatTicket['governorate_id']);
                if ($seatTicket['status'] !== 'confirmed') fail('Booking is not confirmed.');
                if ($seatTicket['ticket_status'] !== 'valid') fail('Booking ticket is not valid. Current status: ' . $seatTicket['ticket_status']);
                if ($seatTicket['seat_ticket_status'] !== 'valid') fail('Seat ticket is not valid. Current status: ' . $seatTicket['seat_ticket_status']);
                if ($seatTicket['payment_status'] !== 'paid') fail('Payment must be paid before this ticket can be used.');
                if (strtotime((string)$seatTicket['show_datetime']) < time()) fail('Ticket expired because the screening time has passed.');
                $u = $pdo->prepare("UPDATE booking_seats SET seat_ticket_status='used' WHERE id=?");
                $u->execute([$seatTicket['booking_seat_id']]);
                $remainingValid = $pdo->prepare("
                    SELECT COUNT(*) FROM booking_seats
                    WHERE booking_id = ? AND COALESCE(seat_ticket_status, 'valid') <> 'used'
                ");
                $remainingValid->execute([(int)$seatTicket['id']]);
                if ((int)$remainingValid->fetchColumn() === 0) {
                    $pdo->prepare("UPDATE bookings SET ticket_status='used' WHERE id=?")
                        ->execute([(int)$seatTicket['id']]);
                }
                addNotification($pdo, (int)$seatTicket['user_id'], 'Seat ticket used', 'Seat ' . seatLabel((int)$seatTicket['seat_row'], (int)$seatTicket['seat_number']) . " for booking #{$seatTicket['id']} has been marked as used.");
                ok(['message' => 'Seat ticket marked as used.']);
            }
        }

        $s = $pdo->prepare("
            SELECT b.id, b.user_id, b.status, b.ticket_status, b.payment_status, s.show_datetime, c.governorate_id
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            WHERE b.ticket_code = ?
        ");
        $s->execute([$code]);
        $b = $s->fetch();
        if (!$b) fail('Ticket not found.', 404);
        enforceRegionalGovernorateAccess((int)$b['governorate_id']);
        if ($b['status'] !== 'confirmed') fail('Booking is not confirmed.');
        if ($b['ticket_status'] !== 'valid') fail('Ticket is not valid. Current status: ' . $b['ticket_status']);
        if ($b['payment_status'] !== 'paid') fail('Payment must be paid before this ticket can be used.');
        if (strtotime((string)$b['show_datetime']) < time()) fail('Ticket expired because the screening time has passed.');
        $u = $pdo->prepare("UPDATE bookings SET ticket_status='used' WHERE id=?");
        $u->execute([$b['id']]);
        addNotification($pdo, (int)$b['user_id'], 'Ticket used', "Your ticket for booking #{$b['id']} has been marked as used.");
        ok(['message' => 'Ticket marked as used.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

/* ============================================================
   ADMIN DASHBOARD / PAYMENTS / SYNC LOGS
   ============================================================ */
case 'admin_refund_requests':
    requireOperationalAdmin();
    if (!tableExists($pdo, 'refund_requests')) {
        ok(['requests' => []]);
    }
    try {
        [$whereSql, $params] = regionalScopeClause('c');
        // Only show refund requests that still need a decision. Once an admin
        // approves or rejects a request it should disappear from this list.
        $whereSql = addSqlCondition($whereSql, "rr.status = 'pending'");
        $stmt = $pdo->prepare("
            SELECT rr.*, b.movie_title, b.ticket_code, b.payment_status, b.status AS booking_status,
                   s.show_datetime, c.name AS cinema_name, c.governorate_id,
                   g.name_en AS governorate_name, h.name AS hall_name,
                   u.username, u.email,
                   processor.username AS processed_by_name
            FROM refund_requests rr
            JOIN bookings b ON rr.booking_id = b.id
            JOIN users u ON rr.user_id = u.id
            JOIN showtimes s ON rr.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            JOIN governorates g ON c.governorate_id = g.id
            LEFT JOIN users processor ON rr.processed_by = processor.id
            $whereSql
            ORDER BY FIELD(rr.status, 'pending', 'approved', 'rejected'), rr.requested_at DESC
            LIMIT 200
        ");
        $stmt->execute($params);
        ok(['requests' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_process_refund_request':
    requireOperationalAdmin();
    requireCsrf($data);
    if (!tableExists($pdo, 'refund_requests')) {
        fail('Refund request table is missing.');
    }
    $requestId = (int)($data['request_id'] ?? 0);
    $decision = trim($data['decision'] ?? '');
    if (!$requestId || !in_array($decision, ['approved', 'rejected'], true)) {
        fail('request_id and valid decision are required.');
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            SELECT rr.*, b.status AS booking_status, b.ticket_status, b.payment_status, b.showtime_id, b.total_price,
                   b.user_id AS booking_user_id, s.price AS seat_price, c.governorate_id
            FROM refund_requests rr
            JOIN bookings b ON rr.booking_id = b.id
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            WHERE rr.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch();
        if (!$request) throw new Exception('Refund request not found.');
        enforceRegionalGovernorateAccess((int)$request['governorate_id']);
        if ($request['status'] !== 'pending') throw new Exception('Refund request is already processed.');

        if ($decision === 'rejected') {
            $update = $pdo->prepare("UPDATE refund_requests SET status='rejected', processed_by=?, processed_at=NOW() WHERE id=?");
            $update->execute([$_SESSION['user_id'], $requestId]);
            addNotification($pdo, (int)$request['user_id'], 'Refund rejected', "Refund request #$requestId for booking #{$request['booking_id']} was rejected.");
            $pdo->commit();
            ok(['message' => 'Refund request rejected.']);
        }

        if ($request['booking_status'] === 'cancelled') throw new Exception('Booking is already cancelled.');
        if (($request['ticket_status'] ?? '') === 'used') throw new Exception('Used tickets cannot be refunded.');
        if (($request['payment_status'] ?? '') !== 'paid') throw new Exception('Only paid bookings can be refunded.');

        $seats = json_decode($request['seats_json'] ?? '[]', true);
        if (!is_array($seats) || empty($seats)) throw new Exception('Refund request has no seats.');

        [$refundableSeats, $usedSeats] = refundableSeatMaps($pdo, (int)$request['booking_id'], true);
        $cleanSeats = cleanRequestedRefundSeats(
            $seats,
            $refundableSeats,
            $usedSeats,
            'One or more requested seats are no longer active.'
        );

        $refundAmount = max(0, (float)($request['refund_amount'] ?? 0));
        $seatPrice = $refundAmount > 0
            ? round($refundAmount / max(1, count($cleanSeats)), 2)
            : (float)($request['seat_price'] ?? 0);
        $deleteSeat = $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ? AND seat_row = ? AND seat_number = ?");
        foreach ($cleanSeats as $seat) {
            archiveCancelledBookingSeat(
                $pdo,
                (int)$request['booking_id'],
                (int)$request['showtime_id'],
                (int)$_SESSION['user_id'],
                $seat['row'],
                $seat['number'],
                $seatPrice,
                'admin_approved_refund_request'
            );
            $deleteSeat->execute([(int)$request['booking_id'], $seat['row'], $seat['number']]);
        }

        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM booking_seats WHERE booking_id = ?");
        $remainingStmt->execute([(int)$request['booking_id']]);
        $remaining = (int)$remainingStmt->fetchColumn();
        if ($refundAmount <= 0) {
            $refundAmount = $seatPrice * count($cleanSeats);
        }

        if ($remaining <= 0) {
            $pdo->prepare("
                UPDATE bookings
                SET status='cancelled', ticket_status='cancelled', payment_status='refunded', total_price=0
                WHERE id=?
            ")->execute([(int)$request['booking_id']]);
            $message = 'Refund approved. Full reservation cancelled.';
        } else {
            $newTotal = max(0, (float)($request['total_price'] ?? 0) - $refundAmount);
            $pdo->prepare("UPDATE bookings SET total_price = ? WHERE id = ?")->execute([$newTotal, (int)$request['booking_id']]);
            $message = 'Refund approved for selected seat(s).';
        }

        $update = $pdo->prepare("
            UPDATE refund_requests
            SET status='approved', processed_by=?, processed_at=NOW(), refund_amount=?
            WHERE id=?
        ");
        $update->execute([$_SESSION['user_id'], $refundAmount, $requestId]);
        addNotification($pdo, (int)$request['user_id'], 'Refund approved', "Booking #{$request['booking_id']}: $message");

        $pdo->commit();
        ok(['message' => $message, 'remaining_seats' => $remaining, 'refund_amount' => $refundAmount]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        failThrowable($e);
    }
    break;

case 'admin_cinema_reservations':
    requireOperationalAdmin();
    try {
        [$cinemaWhere, $cinemaParams] = regionalScopeClause('c');
        $cinemasStmt = $pdo->prepare("
            SELECT c.id, c.name, c.location, g.name_en AS governorate_name,
                   (
                     SELECT COUNT(*)
                     FROM bookings b
                     JOIN showtimes s ON b.showtime_id = s.id
                     JOIN halls h ON s.hall_id = h.id
                     WHERE h.cinema_id = c.id AND b.status = 'confirmed'
                   ) AS reservation_count,
                   (
                     SELECT COUNT(*)
                     FROM bookings b
                     JOIN showtimes s ON b.showtime_id = s.id
                     JOIN halls h ON s.hall_id = h.id
                     WHERE h.cinema_id = c.id
                       AND b.status = 'confirmed'
                       AND b.payment_status = 'pending'
                   ) AS pending_payment_count,
                   (
                     SELECT COUNT(*)
                     FROM refund_requests rr
                     JOIN showtimes s ON rr.showtime_id = s.id
                     JOIN halls h ON s.hall_id = h.id
                     WHERE h.cinema_id = c.id
                       AND rr.status = 'pending'
                   ) AS pending_refund_count
            FROM cinemas c
            JOIN governorates g ON c.governorate_id = g.id
            $cinemaWhere
            ORDER BY g.name_en ASC, c.name ASC
        ");
        $cinemasStmt->execute($cinemaParams);
        $cinemas = $cinemasStmt->fetchAll();

        $byId = [];
        foreach ($cinemas as &$cinema) {
            $cinema['reservation_count'] = (int)$cinema['reservation_count'];
            $cinema['pending_payment_count'] = (int)$cinema['pending_payment_count'];
            $cinema['pending_refund_count'] = (int)$cinema['pending_refund_count'];
            $cinema['reservations'] = [];
            $byId[(int)$cinema['id']] = &$cinema;
        }
        unset($cinema);

        [$reservationWhere, $reservationParams] = regionalScopeClause('c');
        $reservationWhere = addSqlCondition($reservationWhere, "b.status = 'confirmed'");
        $reservationStmt = $pdo->prepare("
            SELECT b.id, b.movie_title, b.total_price, b.payment_status, b.ticket_status,
                   b.created_at, s.show_datetime, h.name AS hall_name,
                   c.id AS cinema_id, c.name AS cinema_name,
                   u.username, u.email,
                   GROUP_CONCAT(CONCAT(CHAR(64 + bs.seat_row), bs.seat_number) ORDER BY bs.seat_row, bs.seat_number SEPARATOR ', ') AS seats
            FROM bookings b
            JOIN users u ON b.user_id = u.id
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            LEFT JOIN booking_seats bs ON bs.booking_id = b.id
            $reservationWhere
            GROUP BY b.id, b.movie_title, b.total_price, b.payment_status, b.ticket_status,
                     b.created_at, s.show_datetime, h.name, c.id, c.name, u.username, u.email
            ORDER BY c.name ASC, s.show_datetime ASC, b.created_at DESC
            LIMIT 500
        ");
        $reservationStmt->execute($reservationParams);
        foreach ($reservationStmt->fetchAll() as $reservation) {
            $cinemaId = (int)$reservation['cinema_id'];
            if (isset($byId[$cinemaId])) {
                $byId[$cinemaId]['reservations'][] = $reservation;
            }
        }

        ok(['cinemas' => array_values($cinemas)]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_dashboard_stats':
    requireStaffOrAdmin();
    try {
        $stats = [];
        $bookingFrom = "
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
        ";
        [$whereSql, $params] = regionalScopeClause('c');

        if (isRegionalAdmin()) {
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT b.user_id) $bookingFrom $whereSql");
            $stmt->execute($params);
            $stats['total_users'] = (int)$stmt->fetchColumn();
            $names = $_SESSION['governorate_names'] ?? [];
            $stats['scope_name'] = $names ? implode(', ', $names) : ($_SESSION['governorate_name'] ?? 'Assigned region');
        } else {
            $stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            $stats['scope_name'] = 'All regions';
        }

        $queries = [
            'total_bookings' => "SELECT COUNT(*) $bookingFrom $whereSql",
            'today_bookings' => "SELECT COUNT(*) $bookingFrom " . addSqlCondition($whereSql, "DATE(b.created_at)=CURDATE()"),
            'total_revenue' => "SELECT COALESCE(SUM(b.total_price),0) $bookingFrom " . addSqlCondition($whereSql, "b.payment_status='paid' AND b.status='confirmed'"),
            'pending_payments' => "SELECT COUNT(*) $bookingFrom " . addSqlCondition($whereSql, "b.payment_status='pending' AND b.status='confirmed'"),
            'paid_payments' => "SELECT COUNT(*) $bookingFrom " . addSqlCondition($whereSql, "b.payment_status='paid' AND b.status='confirmed'"),
            'cancelled_bookings' => "SELECT COUNT(*) $bookingFrom " . addSqlCondition($whereSql, "b.status='cancelled'"),
        ];

        foreach ($queries as $key => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $stats[$key] = $key === 'total_revenue' ? (float)$stmt->fetchColumn() : (int)$stmt->fetchColumn();
        }

        // ---- LOSSES (money returned/lost from refunds + admin cancellations) ----
        // booking.total_price is zeroed on refund, so the preserved per-seat
        // refund_amount in cancelled_booking_seats is the reliable source.
        $lossFrom = "
            FROM cancelled_booking_seats cbs
            JOIN showtimes s ON cbs.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
        ";
        $lossStmt = $pdo->prepare("SELECT COALESCE(SUM(cbs.refund_amount),0) $lossFrom $whereSql");
        $lossStmt->execute($params);
        $stats['total_losses'] = (float)$lossStmt->fetchColumn();

        $refundCountStmt = $pdo->prepare("SELECT COUNT(*) $lossFrom " . addSqlCondition($whereSql, "cbs.refund_amount > 0"));
        $refundCountStmt->execute($params);
        $stats['refunded_seats'] = (int)$refundCountStmt->fetchColumn();

        // total_revenue is already net of partial/full refunds because booking
        // totals are reduced when seats are refunded. Do not subtract losses a
        // second time; expose gross_revenue separately for reporting context.
        $stats['gross_revenue'] = round($stats['total_revenue'] + $stats['total_losses'], 2);
        $stats['net_result'] = round($stats['total_revenue'], 2);

        $last = $pdo->query("SELECT * FROM sync_log ORDER BY id DESC LIMIT 1")->fetch();
        $stats['last_sync'] = $last ?: null;
        ok(['stats' => $stats]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_sync_logs':
    requireAdmin();
    try {
        $rows = $pdo->query("SELECT * FROM sync_log ORDER BY id DESC LIMIT 30")->fetchAll();
        ok(['logs' => $rows]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_sync_status':
    requireAdmin();
    try {
        $last = $pdo->query("SELECT * FROM sync_log ORDER BY id DESC LIMIT 1")->fetch();
        $futureShowtimes = (int)$pdo->query("SELECT COUNT(*) FROM showtimes WHERE show_datetime > NOW()")->fetchColumn();
        ok(['last_sync' => $last ?: null, 'future_showtimes' => $futureShowtimes]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_payments':
    requireOperationalAdmin();
    try {
        [$whereSql, $params] = regionalScopeClause('c');
        // Show ALL payments (past and upcoming, every status). The admin UI
        // decides which action buttons to display: once a payment has been
        // verified/rejected/refunded the actions are hidden, but the row stays
        // visible so there is a full payment history.
        $stmt = $pdo->prepare("\n            SELECT b.id, b.movie_title, b.total_price, b.status, b.payment_status, b.payment_method,\n                   b.payment_reference, b.paid_at, b.ticket_code, b.ticket_status, b.created_at,\n                   s.show_datetime, c.name AS cinema_name, c.governorate_id, g.name_en AS governorate_name,\n                   h.name AS hall_name, u.username, u.email\n            FROM bookings b\n            JOIN users u ON b.user_id = u.id\n            JOIN showtimes s ON b.showtime_id = s.id\n            JOIN halls h ON s.hall_id = h.id\n            JOIN cinemas c ON h.cinema_id = c.id\n            JOIN governorates g ON c.governorate_id = g.id\n            $whereSql\n            ORDER BY b.created_at DESC LIMIT 200\n        ");
        $stmt->execute($params);
        $payments = $stmt->fetchAll();
        $adminSeatSelect = columnExists($pdo, 'booking_seats', 'seat_ticket_code')
            ? 'seat_row, seat_number, seat_ticket_code, seat_ticket_status'
            : 'seat_row, seat_number';
        $seatStmt = $pdo->prepare("
            SELECT $adminSeatSelect
            FROM booking_seats
            WHERE booking_id = ?
            ORDER BY seat_row, seat_number
        ");
        foreach ($payments as &$payment) {
            $seatStmt->execute([$payment['id']]);
            $payment['seats'] = $seatStmt->fetchAll();
            $payment['seat_tickets'] = [];
            foreach ($payment['seats'] as &$seat) {
                if (!empty($seat['seat_ticket_code'])) {
                    $label = seatLabel((int)$seat['seat_row'], (int)$seat['seat_number']);
                    $seat['seat'] = $label;
                    $seat['qr_text'] = makeSeatQrText((int)$payment['id'], (string)$seat['seat_ticket_code'], $label, $payment['payment_reference'] ?? '');
                    $payment['seat_tickets'][] = ['row'=>(int)$seat['seat_row'], 'number'=>(int)$seat['seat_number'], 'seat'=>$label, 'ticket_code'=>$seat['seat_ticket_code'], 'status'=>$seat['seat_ticket_status'] ?? 'valid', 'qr_text'=>$seat['qr_text']];
                }
            }
            unset($seat);
        }
        unset($payment);
        ok(['payments' => $payments]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_verify_payment':
case 'admin_reject_payment':
case 'admin_refund_payment':
    requireOperationalAdmin();
    requireCsrf($data);
    $bookingId = (int)($data['booking_id'] ?? 0);
    if (!$bookingId) fail('booking_id required.');
    try {
        $bookingRow = adminBookingRow($pdo, $bookingId);
        $bookingUserId = (int)$bookingRow['user_id'];
        $pdo->beginTransaction();
        $seatCountStmt = $pdo->prepare("SELECT COUNT(*) FROM booking_seats WHERE booking_id = ?");
        $seatCountStmt->execute([$bookingId]);
        $activeSeatCount = (int)$seatCountStmt->fetchColumn();

        if ($action === 'admin_verify_payment') {
            if (($bookingRow['payment_status'] ?? '') === 'paid') {
                throw new Exception('Payment is already verified.');
            }
            if (($bookingRow['status'] ?? '') === 'cancelled' || ($bookingRow['ticket_status'] ?? '') === 'cancelled' || $activeSeatCount < 1) {
                throw new Exception('Cannot verify a cancelled or empty booking.');
            }
            $ticketCode = trim((string)($bookingRow['ticket_code'] ?? ''));
            if ($ticketCode === '') {
                $ticketCode = makeTicketCode($bookingId);
            }
            $stmt = $pdo->prepare("
                UPDATE bookings
                SET payment_status='paid',
                    paid_at=COALESCE(paid_at, NOW()),
                    status='confirmed',
                    ticket_status='valid',
                    ticket_code=?
                WHERE id=?
            ");
            $stmt->execute([$ticketCode, $bookingId]);
            $msg = 'Payment verified.';
            $notifyTitle = 'Payment verified';
        } elseif ($action === 'admin_reject_payment') {
            if (($bookingRow['payment_status'] ?? '') === 'paid') {
                throw new Exception('Paid bookings must be refunded, not rejected.');
            }
            $seatStmt = $pdo->prepare("SELECT seat_row, seat_number FROM booking_seats WHERE booking_id = ?");
            $seatStmt->execute([$bookingId]);
            foreach ($seatStmt->fetchAll() as $seat) {
                archiveCancelledBookingSeat(
                    $pdo,
                    $bookingId,
                    (int)$bookingRow['showtime_id'],
                    (int)$_SESSION['user_id'],
                    (int)$seat['seat_row'],
                    (int)$seat['seat_number'],
                    0.00,
                    'admin_reject_booking'
                );
            }
            $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ?")->execute([$bookingId]);
            $stmt = $pdo->prepare("UPDATE bookings SET payment_status='failed', status='cancelled', ticket_status='cancelled', total_price=0 WHERE id=?");
            $stmt->execute([$bookingId]);
            $msg = 'Payment rejected and booking cancelled.';
            $notifyTitle = 'Payment rejected';
        } else {
            if (($bookingRow['payment_status'] ?? '') !== 'paid') {
                throw new Exception('Only paid bookings can be refunded.');
            }
            [$refundableSeats, $usedSeats, $allSeats] = refundableSeatMaps($pdo, $bookingId, true);
            if (!empty($usedSeats)) {
                throw new Exception('Cannot refund the full booking because one or more seat tickets were already used. Refund only unused seats.');
            }
            $seatPrice = 0;
            if (!empty($bookingRow['total_price'])) {
                $seatPrice = (float)$bookingRow['total_price'] / max(1, $activeSeatCount);
            }
            foreach ($allSeats as $seat) {
                archiveCancelledBookingSeat(
                    $pdo,
                    $bookingId,
                    (int)$bookingRow['showtime_id'],
                    (int)$_SESSION['user_id'],
                    (int)$seat['seat_row'],
                    (int)$seat['seat_number'],
                    $seatPrice,
                    'admin_refund_booking'
                );
            }
            $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ?")->execute([$bookingId]);
            $stmt = $pdo->prepare("UPDATE bookings SET payment_status='refunded', status='cancelled', ticket_status='cancelled', total_price=0 WHERE id=?");
            $stmt->execute([$bookingId]);
            $msg = 'Payment refunded and booking cancelled.';
            $notifyTitle = 'Payment refunded';
        }
        if ($bookingUserId) addNotification($pdo, $bookingUserId, $notifyTitle, "Booking #$bookingId: $msg");
        $pdo->commit();

        // Return ticket information after payment verification so the admin UI can
        // immediately show the generated QR code instead of requiring a manual search.
        if ($action === 'admin_verify_payment') {
            $seatTickets = [];
            if (columnExists($pdo, 'booking_seats', 'seat_ticket_code')) {
                $seatStmt = $pdo->prepare("
                    SELECT seat_row, seat_number, seat_ticket_code, seat_ticket_status
                    FROM booking_seats
                    WHERE booking_id = ?
                    ORDER BY seat_row, seat_number
                ");
                $seatStmt->execute([$bookingId]);
                foreach ($seatStmt->fetchAll() as $seat) {
                    $row = (int)$seat['seat_row'];
                    $num = (int)$seat['seat_number'];
                    $label = seatLabel($row, $num);
                    $code = trim((string)($seat['seat_ticket_code'] ?? ''));
                    if ($code === '') {
                        $code = makeSeatTicketCode($bookingId, $row, $num);
                        $pdo->prepare("UPDATE booking_seats SET seat_ticket_code = ?, seat_ticket_status = 'valid' WHERE booking_id = ? AND seat_row = ? AND seat_number = ?")
                            ->execute([$code, $bookingId, $row, $num]);
                    }
                    $seatTickets[] = ['row'=>$row, 'number'=>$num, 'seat'=>$label, 'ticket_code'=>$code, 'status'=>$seat['seat_ticket_status'] ?? 'valid', 'qr_text'=>makeSeatQrText($bookingId, $code, $label, $bookingRow['payment_reference'] ?? '')];
                }
            }
            ok([
                'message' => $msg,
                'booking_id' => $bookingId,
                'ticket_code' => $ticketCode,
                'qr_text' => "CINEMAX|BOOKING=$bookingId|CODE=$ticketCode",
                'seat_tickets' => $seatTickets,
            ]);
        }

        ok(['message' => $msg]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail($e instanceof PDOException ? 'Database error.' : $e->getMessage());
    }
    break;

/* ============================================================
   TRIGGER SYNC — run tmdb_sync.php in the background
   POST { action:"trigger_sync" }   (auth required)
   ============================================================ */
case 'admin_cancel_booking_maintenance':
    requireOperationalAdmin();
    requireCsrf($data);
    $bookingId = (int)($data['booking_id'] ?? 0);
    $reasonText = trim($data['reason'] ?? 'Cinema maintenance');
    if (!$bookingId) fail('booking_id required.');
    if ($reasonText === '') $reasonText = 'Cinema maintenance';
    if (strlen($reasonText) > 120) $reasonText = substr($reasonText, 0, 120);

    try {
        $bookingRow = adminBookingRow($pdo, $bookingId);
        $bookingUserId = (int)$bookingRow['user_id'];
        $pdo->beginTransaction();

        $seatStmt = $pdo->prepare("SELECT seat_row, seat_number FROM booking_seats WHERE booking_id = ? ORDER BY seat_row, seat_number");
        $seatStmt->execute([$bookingId]);
        $seats = $seatStmt->fetchAll();
        if (!$seats) throw new Exception('No active seats found for this booking.');
        if (($bookingRow['status'] ?? '') === 'cancelled' || ($bookingRow['ticket_status'] ?? '') === 'cancelled') {
            throw new Exception('Booking is already cancelled.');
        }

        $isPaid = ($bookingRow['payment_status'] ?? '') === 'paid';
        $seatPrice = !empty($bookingRow['total_price']) ? (float)$bookingRow['total_price'] / max(1, count($seats)) : 0.00;
        foreach ($seats as $seat) {
            archiveCancelledBookingSeat(
                $pdo,
                $bookingId,
                (int)$bookingRow['showtime_id'],
                (int)$_SESSION['user_id'],
                (int)$seat['seat_row'],
                (int)$seat['seat_number'],
                $isPaid ? $seatPrice : 0.00,
                'admin_maintenance_cancel'
            );
        }

        $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ?")->execute([$bookingId]);
        $paymentStatus = $isPaid ? 'refunded' : 'failed';
        $stmt = $pdo->prepare("
            UPDATE bookings
            SET status='cancelled',
                ticket_status='cancelled',
                payment_status=?,
                total_price=0
            WHERE id=?
        ");
        $stmt->execute([$paymentStatus, $bookingId]);

        $msg = "Reservation cancelled by admin because of $reasonText.";
        if ($bookingUserId) {
            addNotification($pdo, $bookingUserId, 'Reservation cancelled', "Booking #$bookingId: $msg");
        }

        $pdo->commit();
        ok(['message' => $msg]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail($e instanceof PDOException ? 'Database error.' : $e->getMessage());
    }
    break;

case 'admin_refund_booking_seats':
    requireOperationalAdmin();
    requireCsrf($data);
    $bookingId = (int)($data['booking_id'] ?? 0);
    $seats = $data['seats'] ?? [];
    if (!$bookingId || !is_array($seats) || empty($seats)) fail('Choose at least one seat.');

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT b.*, s.show_datetime, s.price AS seat_price, c.governorate_id
            FROM bookings b
            JOIN showtimes s ON b.showtime_id = s.id
            JOIN halls h ON s.hall_id = h.id
            JOIN cinemas c ON h.cinema_id = c.id
            WHERE b.id = ?
            FOR UPDATE
        ");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking) throw new Exception('Booking not found.');
        enforceRegionalGovernorateAccess((int)$booking['governorate_id']);
        if ($booking['status'] === 'cancelled') throw new Exception('Booking is already cancelled.');
        if (($booking['ticket_status'] ?? '') === 'used') throw new Exception('Used tickets cannot be changed.');

        [$refundableSeats, $usedSeats] = refundableSeatMaps($pdo, $bookingId, true);
        $cleanSeats = cleanRequestedRefundSeats(
            $seats,
            $refundableSeats,
            $usedSeats,
            'Selected seat is not active in this booking.'
        );

        $isPaidBooking = ($booking['payment_status'] ?? '') === 'paid';
        $seatPrice = (float)($booking['seat_price'] ?? 0);
        $archiveAmount = $isPaidBooking ? $seatPrice : 0.00;
        $reason = $isPaidBooking ? 'admin_refund_seat' : 'admin_delete_seat';
        $deleteSeat = $pdo->prepare("DELETE FROM booking_seats WHERE booking_id = ? AND seat_row = ? AND seat_number = ?");
        foreach ($cleanSeats as $seat) {
            archiveCancelledBookingSeat(
                $pdo,
                $bookingId,
                (int)$booking['showtime_id'],
                (int)$_SESSION['user_id'],
                $seat['row'],
                $seat['number'],
                $archiveAmount,
                $reason
            );
            $deleteSeat->execute([$bookingId, $seat['row'], $seat['number']]);
        }

        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM booking_seats WHERE booking_id = ?");
        $remainingStmt->execute([$bookingId]);
        $remaining = (int)$remainingStmt->fetchColumn();
        $cancelledCount = count($cleanSeats);
        $refundAmount = $archiveAmount * $cancelledCount;

        if ($remaining <= 0) {
            $paymentStatus = $isPaidBooking ? 'refunded' : 'failed';
            $update = $pdo->prepare("
                UPDATE bookings
                SET status = 'cancelled',
                    ticket_status = 'cancelled',
                    payment_status = ?,
                    total_price = 0
                WHERE id = ?
            ");
            $update->execute([$paymentStatus, $bookingId]);
            $message = $isPaidBooking
                ? 'Reservation refunded and deleted.'
                : 'Reservation deleted.';
        } else {
            $newTotal = max(0, $seatPrice * $remaining);
            $pdo->prepare("UPDATE bookings SET total_price = ? WHERE id = ?")->execute([$newTotal, $bookingId]);
            $message = $isPaidBooking
                ? 'Selected seat(s) refunded.'
                : 'Selected seat(s) deleted.';
        }

        addNotification(
            $pdo,
            (int)$booking['user_id'],
            $isPaidBooking ? 'Reservation refunded' : 'Reservation updated',
            "Booking #$bookingId: $message"
        );

        $pdo->commit();
        ok([
            'message' => $message,
            'cancelled_seats' => $cancelledCount,
            'remaining_seats' => $remaining,
            'refund_amount' => $refundAmount,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        failThrowable($e);
    }
    break;

case 'admin_database_health':
    requireAdmin();
    try {
        $counts = [];
        $supportTable = supportMessagesTable($pdo);
        foreach (['users', 'bookings', 'booking_seats', 'cancelled_booking_seats', 'refund_requests', 'seat_locks', $supportTable, 'support_replies', 'notifications', 'sync_log'] as $table) {
            $counts[$table] = tableExists($pdo, $table) ? (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() : 0;
        }
        $counts['support_requests'] = tableExists($pdo, 'support_requests') ? (int)$pdo->query("SELECT COUNT(*) FROM support_requests")->fetchColumn() : 0;
        $counts['expired_locks'] = tableExists($pdo, 'seat_locks') ? (int)$pdo->query("SELECT COUNT(*) FROM seat_locks WHERE expires_at < NOW()")->fetchColumn() : 0;
        ok(['counts' => $counts]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_cleanup_database':
    requireAdmin();
    requireCsrf($data);
    try {
        $summary = [
            'expired_locks_deleted' => 0,
            'orphan_locks_deleted' => 0,
            'booked_locks_deleted' => 0,
        ];

        $expired = $pdo->prepare("DELETE FROM seat_locks WHERE expires_at < NOW()");
        $expired->execute();
        $summary['expired_locks_deleted'] = $expired->rowCount();

        $orphan = $pdo->prepare("
            DELETE sl
            FROM seat_locks sl
            LEFT JOIN showtimes s ON s.id = sl.showtime_id
            LEFT JOIN users u ON u.id = sl.user_id
            WHERE s.id IS NULL OR u.id IS NULL
        ");
        $orphan->execute();
        $summary['orphan_locks_deleted'] = $orphan->rowCount();

        $bookedLocks = $pdo->prepare("
            DELETE sl
            FROM seat_locks sl
            JOIN booking_seats bs
              ON bs.showtime_id = sl.showtime_id
             AND bs.seat_row = sl.seat_row
             AND bs.seat_number = sl.seat_number
        ");
        $bookedLocks->execute();
        $summary['booked_locks_deleted'] = $bookedLocks->rowCount();

        ok(['message' => 'Safe cleanup completed. Financial, booking, support, and cancellation history were not deleted.', 'summary' => $summary]);
    } catch (PDOException $e) { fail('Database cleanup failed.'); }
    break;


case 'admin_region_transfer_notice':
    requireStaffOrAdmin();
    try {
        $stmt = $pdo->prepare("SELECT id, title, message, created_at FROM notifications
                               WHERE user_id = ? AND is_read = 0 AND title = 'Regional assignment updated'
                               ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $notice = $stmt->fetch();
        if (!$notice) ok(['notice' => null]);
        $message = (string)($notice['message'] ?? '');
        $previous = '';
        $newRegion = '';
        if (preg_match('/changed from (.*?) to (.*?)\./', $message, $m)) {
            $previous = trim($m[1]);
            $newRegion = trim($m[2]);
        }
        ok(['notice' => [
            'id' => (int)$notice['id'],
            'message' => $message,
            'previous_region' => $previous,
            'new_region' => $newRegion,
            'created_at' => $notice['created_at'] ?? null,
        ]]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_dismiss_region_transfer_notice':
    requireStaffOrAdmin();
    requireCsrf($data);
    $notificationId = (int)($data['notification_id'] ?? 0);
    if (!$notificationId) fail('notification_id required.');
    try {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND title = 'Regional assignment updated'");
        $stmt->execute([$notificationId, (int)$_SESSION['user_id']]);
        ok(['message' => 'Region update dismissed.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_forgot_password_requests':
    requireAdmin();
    try {
        $stmt = $pdo->query("SELECT f.id, f.email, f.request_type, f.status, f.admin_note, f.admin_visible_code, f.preferred_channel, f.contact_phone, f.code_expires_at, f.created_at, f.resolved_at,
                                    u.username, a.username AS admin_name
                             FROM forgot_password_requests f
                             LEFT JOIN users u ON u.id = f.user_id
                             LEFT JOIN users a ON a.id = f.admin_id
                             ORDER BY f.created_at DESC
                             LIMIT 100");
        ok(['requests' => $stmt->fetchAll()]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_handle_forgot_password_request':
    requireAdmin();
    requireCsrf($data);
    $requestId = (int)($data['request_id'] ?? 0);
    $decision = trim($data['decision'] ?? 'resolved');
    $note = trim($data['note'] ?? '');
    if (!$requestId) fail('request_id required.');
    if (!in_array($decision, ['resolved','rejected'], true)) fail('Invalid decision.');
    try {
        $stmt = $pdo->prepare("UPDATE forgot_password_requests SET status=?, admin_id=?, admin_note=?, resolved_at=NOW() WHERE id=?");
        $stmt->execute([$decision, (int)$_SESSION['user_id'], $note ?: ($decision === 'resolved' ? 'Handled by admin.' : 'Rejected by admin.'), $requestId]);
        ok(['message' => 'Forgot password request updated.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_region_admins':
    requireAdmin();
    try {
        // List operational admin accounts (regional admins + staff) with the
        // FULL set of regions each one covers.
        $stmt = $pdo->query("
            SELECT u.id, u.username, u.email, u.role, u.is_blocked, u.governorate_id, u.created_at,
                   pg.name_en AS primary_region,
                   GROUP_CONCAT(DISTINCT g.id ORDER BY g.name_en) AS region_ids,
                   GROUP_CONCAT(DISTINCT g.name_en ORDER BY g.name_en SEPARATOR ', ') AS region_names
            FROM users u
            LEFT JOIN governorates pg ON u.governorate_id = pg.id
            LEFT JOIN admin_governorates ag ON ag.user_id = u.id
            LEFT JOIN governorates g ON ag.governorate_id = g.id
            WHERE u.role IN ('regional_admin','staff')
            GROUP BY u.id, u.username, u.email, u.role, u.is_blocked, u.governorate_id, u.created_at, pg.name_en
            ORDER BY u.username ASC
        ");
        $admins = $stmt->fetchAll();
        foreach ($admins as &$a) {
            // Fall back to the primary region if the join table has no rows yet.
            if (empty($a['region_ids']) && !empty($a['governorate_id'])) {
                $a['region_ids'] = (string)$a['governorate_id'];
                $a['region_names'] = $a['primary_region'] ?? '';
            }
            $a['region_id_list'] = $a['region_ids']
                ? array_map('intval', explode(',', $a['region_ids']))
                : [];
        }
        unset($a);
        ok(['admins' => $admins]);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_create_region_admin':
    requireAdmin();
    requireCsrf($data);
    $username = trim($data['username'] ?? '');
    $email = filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $password = $data['password'] ?? '';
    // Accept either a single governorate_id or a list governorate_ids[].
    $govIds = adminParseGovernorateIds($data);

    if (strlen($username) < 3) fail('Username must be at least 3 characters.');
    requireMaxLength($username, 60, 'Full name');
    requireMaxLength($email, 120, 'Email');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address.');
    requirePublicEmail($email);
    requireStrongPassword($password, 'Password');
    if (!$govIds) fail('Choose at least one region for this admin.');

    try {
        $validIds = adminValidateGovernorateIds($pdo, $govIds);
        if (!$validIds) fail('No valid region was selected.');

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password_hash, role, governorate_id)
            VALUES (?, ?, ?, 'regional_admin', ?)
        ");
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $validIds[0]]);
        $newId = (int)$pdo->lastInsertId();

        $link = $pdo->prepare("INSERT INTO admin_governorates (user_id, governorate_id) VALUES (?, ?)");
        foreach ($validIds as $gid) { $link->execute([$newId, $gid]); }
        $pdo->commit();
        ok(['message' => 'Regional admin account created with ' . count($validIds) . ' region(s).']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Email or username already taken.');
    }
    break;

case 'admin_set_region_admin_status':
    requireAdmin();
    requireCsrf($data);
    $userId = (int)($data['user_id'] ?? 0);
    $isBlocked = !empty($data['is_blocked']) ? 1 : 0;
    if (!$userId) fail('user_id required.');
    if ($userId === (int)($_SESSION['user_id'] ?? 0)) fail('You cannot deactivate your own account.');
    try {
        $stmt = $pdo->prepare("UPDATE users SET is_blocked = ? WHERE id = ? AND role IN ('regional_admin','staff')");
        $stmt->execute([$isBlocked, $userId]);
        ok(['message' => $isBlocked ? 'Account deactivated.' : 'Account reactivated.']);
    } catch (PDOException $e) { fail('Database error.'); }
    break;

case 'admin_update_region_admin_region':
    // Replace the FULL set of regions for an account (one or many).
    requireAdmin();
    requireCsrf($data);
    $userId = (int)($data['user_id'] ?? 0);
    $govIds = adminParseGovernorateIds($data);
    if (!$userId) fail('user_id required.');
    if (!$govIds) fail('Select at least one region.');
    try {
        $validIds = adminValidateGovernorateIds($pdo, $govIds);
        if (!$validIds) fail('No valid region was selected.');

        $check = $pdo->prepare("SELECT id, governorate_id FROM users WHERE id = ? AND role IN ('regional_admin','staff') LIMIT 1");
        $check->execute([$userId]);
        $target = $check->fetch();
        if (!$target) fail('Account not found.');
        $oldIds = loadAdminGovernorateIds($pdo, $userId, !empty($target['governorate_id']) ? (int)$target['governorate_id'] : null);
        $oldNames = adminGovernorateNames($pdo, $oldIds);
        $newNames = adminGovernorateNames($pdo, $validIds);

        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM admin_governorates WHERE user_id = ?")->execute([$userId]);
        $link = $pdo->prepare("INSERT INTO admin_governorates (user_id, governorate_id) VALUES (?, ?)");
        foreach ($validIds as $gid) { $link->execute([$userId, $gid]); }
        // Keep the primary governorate_id in sync (first selected region).
        $pdo->prepare("UPDATE users SET governorate_id = ? WHERE id = ?")->execute([$validIds[0], $userId]);
        $pdo->commit();
        if ($oldNames !== $newNames) {
            addNotification($pdo, $userId, 'Regional assignment updated',
                'Your regional assignment changed from ' . $oldNames . ' to ' . $newNames . '.');
        }
        ok(['message' => 'Regions updated (' . count($validIds) . ').']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Database error.');
    }
    break;

case 'admin_update_region_admin_role':
    // Change an operational account's role. Cannot create/manage managers here.
    requireAdmin();
    requireCsrf($data);
    $userId = (int)($data['user_id'] ?? 0);
    $newRole = trim($data['role'] ?? '');
    $allowed = ['regional_admin', 'staff', 'user'];
    if (!$userId) fail('user_id required.');
    if (!in_array($newRole, $allowed, true)) fail('Role must be one of: regional_admin, staff, user.');
    if ($userId === (int)($_SESSION['user_id'] ?? 0)) fail('You cannot change your own role here.');
    try {
        $check = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
        $check->execute([$userId]);
        $row = $check->fetch();
        if (!$row) fail('Account not found.');
        if (($row['role'] ?? '') === 'admin') fail('Manager accounts cannot be changed here.');

        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$newRole, $userId]);
        // A plain user has no regional responsibilities.
        if ($newRole === 'user') {
            $pdo->prepare("DELETE FROM admin_governorates WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("UPDATE users SET governorate_id = NULL WHERE id = ?")->execute([$userId]);
        }
        $pdo->commit();
        ok(['message' => 'Role updated to ' . $newRole . '.']);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fail('Database error.');
    }
    break;

case 'trigger_sync':
case 'admin_trigger_sync':
    requireAdmin();
    requireCsrf($data);
    $syncFile = __DIR__ . '/tmdb_sync.php';
    if (!file_exists($syncFile)) fail('Sync script not found.');
    $phpBin = 'php';
    foreach ([PHP_BINDIR . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php'), 'C:\\xampp\\php\\php.exe'] as $candidate) {
        if (is_file($candidate)) {
            $phpBin = $candidate;
            break;
        }
    }
    $cmd = PHP_OS_FAMILY === 'Windows'
        ? 'start /B "" "' . $phpBin . '" "' . $syncFile . '"'
        : escapeshellarg($phpBin) . ' ' . escapeshellarg($syncFile) . ' > /dev/null 2>&1 &';
    @exec($cmd);
    ok(['message' => 'Sync started. Check sync_status in ~30 seconds.']);
    break;

/* ============================================================
   CSRF TOKEN
   GET api.php?action=csrf_token
   Returns (and generates on first call) the session's token.
   Frontend stores it and sends it back in every POST body.
   ============================================================ */
case 'csrf_token':
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    ok(['csrf_token' => $_SESSION['csrf_token']]);
    break;

/* ============================================================
   TMDB PROXY ACTIONS
   The browser used to call TMDB directly with the Bearer token
   embedded in script.js. That exposed our key. Now the frontend
   calls api.php and PHP makes the TMDB request server-side.
   ============================================================ */
case 'movies_now_showing':
    ok(['movies' => tmdb_get_bookable_movies($pdo)]);
    break;

case 'movies_upcoming':
    ok(['movies' => tmdb_get_curated_movies('upcoming')]);
    break;

case 'movie_trailer':
    $tmdbId = (int)($_GET['tmdb_id'] ?? $data['tmdb_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    ok(['trailer_url' => tmdb_get_trailer($tmdbId)]);
    break;

case 'movie_cast':
    $tmdbId = (int)($_GET['tmdb_id'] ?? $data['tmdb_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    ok(['cast' => tmdb_get_cast($tmdbId)]);
    break;

case 'movie_details':
    $tmdbId = (int)($_GET['tmdb_id'] ?? $data['tmdb_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    $movie = tmdb_get_movie_details($tmdbId);
    if (!$movie) fail('Movie not found.', 404);
    ok(['movie' => $movie]);
    break;

case 'streaming_providers':
    $tmdbId = (int)($_GET['tmdb_id'] ?? $data['tmdb_id'] ?? 0);
    if (!$tmdbId) fail('tmdb_id required.');
    ok(['providers' => tmdb_get_streaming($tmdbId)]);
    break;

case 'person_details':
    $personId = (int)($_GET['person_id'] ?? $data['person_id'] ?? 0);
    if (!$personId) fail('person_id required.');
    $person = tmdb_get_person($personId);
    if (!$person) fail('Person not found.', 404);
    ok(['person' => $person]);
    break;

default:
    fail('Unknown action: ' . h($action), 400);
    break;
}
