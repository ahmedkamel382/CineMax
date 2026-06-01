<?php
/**
 * CINEMAX — VIRTUAL CINEMA API  (vc_api.php)
 * ============================================================
 * Backend for the immersive virtual cinema room.
 *
 * Reuses db.php and the SAME PHP session as the main site, so a
 * user who logged in on index.html is recognised here automatically.
 *
 * Actions (api-style, ?action=...):
 *   token            GET   -> returns/creates the session CSRF token
 *   vc_join          POST  -> occupy a seat in a showtime room
 *   vc_leave         POST  -> leave the room (free the seat)
 *   vc_send          POST  -> send a comment from your seat
 *   vc_poll          GET   -> heartbeat + neighbour presence + new
 *                             messages from the seats next to you
 *
 * NEIGHBOUR RULE: a message is delivered only to occupants of the
 * seats immediately to the LEFT and RIGHT in the same row
 * (seat_number ± 1). Like a real cinema, your voice doesn't carry
 * across the hall — only the person beside you hears it.
 * ============================================================
 */

declare(strict_types=1);

// Shared session with the public website user login.
// The main app uses the CINEMAXUSER cookie for normal users, so the virtual cinema
// uses the same session name before session_start().
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('CINEMAXUSER');
    $isHttps = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443) || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';   // provides $pdo (or exits with JSON error)

// ── helpers ──────────────────────────────────────────────────
function vc_ok(array $payload): void {
    echo json_encode(['status' => 'success'] + $payload);
    exit;
}
function vc_fail(string $msg, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}
function vc_input(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw ?: '[]', true);
        if (is_array($j)) return $j;
    }
    return $_GET;
}

/**
 * Make the Virtual Cinema self-installing for demo testing.
 * This prevents "Database error" when the main cinema database exists
 * but the two new virtual cinema tables were not imported yet.
 */
function vc_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS virtual_showtimes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tmdb_movie_id INT NOT NULL,
        movie_title VARCHAR(255) NOT NULL,
        movie_poster VARCHAR(500) NULL,
        trailer_url VARCHAR(500) NULL,
        show_datetime DATETIME NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 120.00,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_virtual_show_datetime (show_datetime),
        INDEX idx_virtual_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS virtual_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        virtual_showtime_id INT NOT NULL,
        ticket_code VARCHAR(80) NOT NULL UNIQUE,
        status ENUM('reserved','cancelled') NOT NULL DEFAULT 'reserved',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_one_active_virtual_ticket (user_id, status),
        INDEX idx_virtual_booking_user (user_id),
        INDEX idx_virtual_booking_showtime (virtual_showtime_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    try {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM virtual_showtimes")->fetchColumn();
        if ($count === 0) {
            $pdo->exec("INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, show_datetime, price, is_open, is_active)
                SELECT s.tmdb_movie_id, s.movie_title, MAX(s.movie_poster), NOW(), 120.00, 1, 1
                FROM showtimes s
                GROUP BY s.tmdb_movie_id, s.movie_title
                ORDER BY MIN(s.show_datetime)
                LIMIT 6");
        }
        $count = (int)$pdo->query("SELECT COUNT(*) FROM virtual_showtimes")->fetchColumn();
        if ($count === 0) {
            $stmt = $pdo->prepare("INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, trailer_url, show_datetime, price, is_open, is_active) VALUES (?, ?, ?, ?, NOW(), ?, 1, 1)");
            $stmt->execute([550, 'Virtual Cinema Demo Trailer', NULL, NULL, 120.00]);
            $stmt->execute([603, 'Virtual Cinema Evening Trailer', NULL, NULL, 120.00]);
            $stmt->execute([11, 'Virtual Cinema Night Trailer', NULL, NULL, 120.00]);
        }
    } catch (Throwable $e) {
        // Last-resort fallback: keep the demo usable even if normal showtimes are empty.
        try {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM virtual_showtimes")->fetchColumn();
            if ($count === 0) {
                $pdo->exec("INSERT INTO virtual_showtimes (tmdb_movie_id, movie_title, movie_poster, trailer_url, show_datetime, price, is_open, is_active) VALUES
                    (550, 'Virtual Cinema Demo Trailer', NULL, NULL, NOW(), 120.00, 1, 1),
                    (603, 'Virtual Cinema Evening Trailer', NULL, NULL, NOW(), 120.00, 1, 1),
                    (11, 'Virtual Cinema Night Trailer', NULL, NULL, NOW(), 120.00, 1, 1)");
            }
        } catch (Throwable $ignored) {}
    }

    try {
        $pdo->exec("UPDATE virtual_showtimes SET is_open = 1 WHERE is_active = 1");
    } catch (Throwable $e) {
        // Older installs still work because vc_load_showtime also opens demo sessions.
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS virtual_presence (
        id INT AUTO_INCREMENT PRIMARY KEY,
        showtime_id INT NOT NULL,
        user_id INT NOT NULL,
        seat_row INT NOT NULL,
        seat_number INT NOT NULL,
        display_name VARCHAR(60) NOT NULL,
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        last_seen DATETIME NOT NULL,
        UNIQUE KEY uniq_presence_seat (showtime_id, seat_row, seat_number),
        UNIQUE KEY uniq_presence_user (showtime_id, user_id),
        INDEX idx_presence_showtime (showtime_id),
        INDEX idx_presence_lastseen (last_seen)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS seat_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        showtime_id INT NOT NULL,
        user_id INT NOT NULL,
        seat_row INT NOT NULL,
        seat_number INT NOT NULL,
        display_name VARCHAR(60) NOT NULL,
        body VARCHAR(280) NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_msg_showtime_id (showtime_id, id),
        INDEX idx_msg_row (showtime_id, seat_row, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function vc_require_login(): array {
    if (empty($_SESSION['user_id'])) {
        vc_fail('Please sign in on the main CINEMAX site first, then reopen the cinema.', 401);
    }
    return [
        'id'       => (int)$_SESSION['user_id'],
        'username' => (string)($_SESSION['username'] ?? 'Guest'),
    ];
}
function vc_require_csrf(array $in): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return;
    $sent = (string)($in['csrf_token'] ?? '');
    $real = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $real === '' || !hash_equals($real, $sent)) {
        vc_fail('Invalid session token. Refresh the page.', 403);
    }
}

/**
 * Load the virtual room geometry. Virtual Cinema is a direct demo now:
 * entering the room opens the trailer immediately, without countdown waiting.
 */
function vc_load_showtime(PDO $pdo, int $showtimeId): array {
    $stmt = $pdo->prepare(""
        . "SELECT id, tmdb_movie_id, movie_title, movie_poster, trailer_url, "
        . "show_datetime, price, is_open "
        . "FROM virtual_showtimes "
        . "WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$showtimeId]);
    $row = $stmt->fetch();
    if (!$row) vc_fail('Virtual cinema showtime not found.', 404);
    $row['open'] = true;
    $row['is_open'] = 1;
    // Virtual cinema is online only, so it does not use real branches or halls.
    $row['cinema_name'] = 'Virtual Cinema';
    $row['hall_name'] = 'Online Screening Room';
    $row['hall_type'] = 'Virtual';
    $row['region_name'] = 'Online';
    $row['total_rows'] = 5;
    $row['seats_per_row'] = 8;
    return $row;
}

function vc_my_ticket(PDO $pdo, int $userId): ?array {
    $s = $pdo->prepare("SELECT vb.*, vs.movie_title, vs.show_datetime,
               1 AS is_open
        FROM virtual_bookings vb
        JOIN virtual_showtimes vs ON vb.virtual_showtime_id = vs.id
        WHERE vb.user_id = ? AND vb.status = 'reserved'
        LIMIT 1");
    $s->execute([$userId]);
    $row = $s->fetch();
    return $row ?: null;
}

/** Drop presences that have not sent a heartbeat in 35 seconds. */
function vc_sweep(PDO $pdo, int $showtimeId): void {
    try {
        $del = $pdo->prepare("DELETE FROM virtual_presence
                              WHERE showtime_id = ? AND last_seen < (NOW() - INTERVAL 35 SECOND)");
        $del->execute([$showtimeId]);
    } catch (Throwable $e) { /* sweeping is best-effort */ }
}

/** Current user's live seat in this room, or null. */
function vc_my_seat(PDO $pdo, int $showtimeId, int $userId): ?array {
    $stmt = $pdo->prepare("SELECT seat_row, seat_number FROM virtual_presence
                           WHERE showtime_id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$showtimeId, $userId]);
    $r = $stmt->fetch();
    return $r ? ['row' => (int)$r['seat_row'], 'number' => (int)$r['seat_number']] : null;
}

// ── routing ──────────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$in = vc_input();

try {
    vc_ensure_schema($pdo);
    switch ($action) {


    /* ----------------------------------------------------------
       DEMO SHOWTIME — picks the nearest showtime for the demo room
       GET ?action=vc_demo_showtime
       ---------------------------------------------------------- */
    case 'vc_demo_showtime': {
        $stmt = $pdo->query("SELECT id FROM virtual_showtimes WHERE is_active = 1 ORDER BY show_datetime ASC, id ASC LIMIT 1");
        $id = (int)($stmt->fetchColumn() ?: 0);
        if (!$id) vc_fail('No virtual cinema showtime exists yet. Run TMDB sync/seed data first.');
        vc_ok(['showtime_id' => $id]);
        break;
    }

    case 'token':
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        vc_ok(['csrf_token' => $_SESSION['csrf_token']]);
        break;


    /* ----------------------------------------------------------
       VIRTUAL SESSIONS — list online-only virtual cinema dates.
       This is separate from normal cinema regions/branches.
       GET ?action=vc_sessions
       ---------------------------------------------------------- */
    case 'vc_sessions': {
        $user = vc_require_login();
        $ticket = vc_my_ticket($pdo, $user['id']);

        $stmt = $pdo->query("SELECT id, tmdb_movie_id, movie_title, movie_poster, trailer_url,
                                    show_datetime, price,
                                    1 AS is_open,
                                    is_active
                             FROM virtual_showtimes
                             WHERE is_active = 1
                             ORDER BY show_datetime ASC, id ASC
                             LIMIT 20");
        vc_ok([
            'ticket' => $ticket,
            'sessions' => $stmt->fetchAll()
        ]);
        break;
    }

    /* ----------------------------------------------------------
       RESERVE VIRTUAL TICKET — one active ticket per user.
       POST { showtime_id, csrf_token }
       ---------------------------------------------------------- */
    case 'vc_reserve_ticket': {
        $user = vc_require_login();
        vc_require_csrf($in);
        $showtimeId = (int)($in['showtime_id'] ?? 0);
        if (!$showtimeId) vc_fail('Choose a virtual cinema date first.');

        $existing = vc_my_ticket($pdo, $user['id']);
        if ($existing) {
            vc_fail('You already have one active virtual cinema ticket. Open it before reserving another one.', 409);
        }

        $show = vc_load_showtime($pdo, $showtimeId);
        $code = 'VC-' . date('Ymd') . '-' . $user['id'] . '-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt = $pdo->prepare("INSERT INTO virtual_bookings
            (user_id, virtual_showtime_id, ticket_code, status, created_at)
            VALUES (?, ?, ?, 'reserved', NOW())");
        try {
            $stmt->execute([$user['id'], $showtimeId, $code]);
        } catch (Throwable $e) {
            vc_fail('Could not reserve this virtual ticket. You may already have one active ticket.', 409);
        }
        vc_ok(['ticket_code' => $code, 'showtime_id' => $showtimeId, 'showtime' => $show]);
        break;
    }

    /* ----------------------------------------------------------
       JOIN — occupy a seat
       POST { showtime_id, seat_row, seat_number, csrf_token }
       ---------------------------------------------------------- */
    case 'vc_join': {
        $user = vc_require_login();
        vc_require_csrf($in);
        $showtimeId = (int)($in['showtime_id'] ?? 0);
        $row = (int)($in['seat_row'] ?? 0);
        $num = (int)($in['seat_number'] ?? 0);
        if (!$showtimeId || $row < 1 || $num < 1) vc_fail('Pick a seat first.');

        $ticket = vc_my_ticket($pdo, $user['id']);
        if (!$ticket || (int)$ticket['virtual_showtime_id'] !== $showtimeId) {
            vc_fail('Reserve one virtual cinema ticket first.');
        }
        $show = vc_load_showtime($pdo, $showtimeId);
        if ($row > $show['total_rows'] || $num > $show['seats_per_row']) {
            vc_fail('That seat is outside this hall.');
        }

        vc_sweep($pdo, $showtimeId);

        try {
            $pdo->beginTransaction();

            // Is the seat held by a *different* live user?
            $occ = $pdo->prepare("SELECT user_id FROM virtual_presence
                                  WHERE showtime_id = ? AND seat_row = ? AND seat_number = ?
                                  FOR UPDATE");
            $occ->execute([$showtimeId, $row, $num]);
            $holder = $occ->fetch();
            if ($holder && (int)$holder['user_id'] !== $user['id']) {
                $pdo->commit();
                vc_fail('Someone is already sitting there. Choose another seat.');
            }

            // Move the user to the new seat (one seat per user per showtime).
            $pdo->prepare("DELETE FROM virtual_presence WHERE showtime_id = ? AND user_id = ?")
                ->execute([$showtimeId, $user['id']]);
            $pdo->prepare("INSERT INTO virtual_presence
                              (showtime_id, user_id, seat_row, seat_number, display_name, last_seen)
                           VALUES (?, ?, ?, ?, ?, NOW())")
                ->execute([$showtimeId, $user['id'], $row, $num, $user['username']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            vc_fail('Could not take that seat. Try another.');
        }

        vc_ok([
            'showtime' => $show,
            'seat'     => ['row' => $row, 'number' => $num],
            'message'  => 'You are seated.',
        ]);
        break;
    }

    /* ----------------------------------------------------------
       LEAVE — free the seat
       POST { showtime_id, csrf_token }
       ---------------------------------------------------------- */
    case 'vc_leave': {
        $user = vc_require_login();
        vc_require_csrf($in);
        $showtimeId = (int)($in['showtime_id'] ?? 0);
        if ($showtimeId) {
            $pdo->prepare("DELETE FROM virtual_presence WHERE showtime_id = ? AND user_id = ?")
                ->execute([$showtimeId, $user['id']]);
        }
        vc_ok(['message' => 'You left your seat.']);
        break;
    }

    /* ----------------------------------------------------------
       SEND — comment from your seat
       POST { showtime_id, body, csrf_token }
       ---------------------------------------------------------- */
    case 'vc_send': {
        $user = vc_require_login();
        vc_require_csrf($in);
        $showtimeId = (int)($in['showtime_id'] ?? 0);
        $body = trim((string)($in['body'] ?? ''));
        if (!$showtimeId) vc_fail('Missing room.');
        if ($body === '') vc_fail('Type something first.');
        if (mb_strlen($body) > 280) $body = mb_substr($body, 0, 280);

        $seat = vc_my_seat($pdo, $showtimeId, $user['id']);
        if (!$seat) vc_fail('Take a seat before you can talk to your neighbour.');

        // refresh heartbeat while we are here
        $pdo->prepare("UPDATE virtual_presence SET last_seen = NOW()
                       WHERE showtime_id = ? AND user_id = ?")
            ->execute([$showtimeId, $user['id']]);

        $pdo->prepare("INSERT INTO seat_messages
                          (showtime_id, user_id, seat_row, seat_number, display_name, body, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$showtimeId, $user['id'], $seat['row'], $seat['number'], $user['username'], $body]);

        vc_ok(['message_id' => (int)$pdo->lastInsertId()]);
        break;
    }

    /* ----------------------------------------------------------
       POLL — heartbeat + neighbours + new adjacent messages
       GET  ?action=vc_poll&showtime_id=..&after=<lastMessageId>
       ---------------------------------------------------------- */
    case 'vc_poll': {
        $user = vc_require_login();
        $showtimeId = (int)($_GET['showtime_id'] ?? 0);
        $after = (int)($_GET['after'] ?? 0);
        if (!$showtimeId) vc_fail('Missing room.');

        $ticket = vc_my_ticket($pdo, $user['id']);
        if (!$ticket || (int)$ticket['virtual_showtime_id'] !== $showtimeId) {
            vc_fail('Reserve one virtual cinema ticket first.');
        }
        $show = vc_load_showtime($pdo, $showtimeId);

        // heartbeat (keeps our seat alive) then sweep stale bodies
        $seat = vc_my_seat($pdo, $showtimeId, $user['id']);
        if ($seat) {
            $pdo->prepare("UPDATE virtual_presence SET last_seen = NOW()
                           WHERE showtime_id = ? AND user_id = ?")
                ->execute([$showtimeId, $user['id']]);
        }
        vc_sweep($pdo, $showtimeId);

        // who is currently seated (for drawing the room)
        $occStmt = $pdo->prepare("SELECT seat_row, seat_number, display_name, user_id
                                  FROM virtual_presence WHERE showtime_id = ?");
        $occStmt->execute([$showtimeId]);
        $occupants = array_map(function ($r) use ($user) {
            return [
                'row'    => (int)$r['seat_row'],
                'number' => (int)$r['seat_number'],
                'name'   => $r['display_name'],
                'is_me'  => ((int)$r['user_id'] === $user['id']),
            ];
        }, $occStmt->fetchAll());

        // new messages from the seats immediately beside me (and my own)
        $messages = [];
        $neighbours = ['left' => null, 'right' => null];
        if ($seat) {
            $mStmt = $pdo->prepare("
                SELECT id, seat_row, seat_number, display_name, body, created_at, user_id
                FROM seat_messages
                WHERE showtime_id = ?
                  AND id > ?
                  AND seat_row = ?
                  AND seat_number IN (?, ?, ?)
                ORDER BY id ASC
                LIMIT 50
            ");
            $mStmt->execute([
                $showtimeId, $after, $seat['row'],
                $seat['number'] - 1, $seat['number'], $seat['number'] + 1,
            ]);
            foreach ($mStmt->fetchAll() as $m) {
                $side = 'me';
                if ((int)$m['seat_number'] === $seat['number'] - 1) $side = 'left';
                elseif ((int)$m['seat_number'] === $seat['number'] + 1) $side = 'right';
                elseif ((int)$m['user_id'] !== $user['id']) $side = 'me'; // same seat edge case
                $messages[] = [
                    'id'    => (int)$m['id'],
                    'name'  => $m['display_name'],
                    'body'  => $m['body'],
                    'side'  => $side,
                    'mine'  => ((int)$m['user_id'] === $user['id']),
                ];
            }
            // who is in the seats beside me right now?
            foreach ($occupants as $o) {
                if ($o['row'] === $seat['row'] && $o['number'] === $seat['number'] - 1) $neighbours['left'] = $o['name'];
                if ($o['row'] === $seat['row'] && $o['number'] === $seat['number'] + 1) $neighbours['right'] = $o['name'];
            }
        }

        vc_ok([
            'showtime'   => $show,
            'seat'       => $seat,
            'occupants'  => $occupants,
            'neighbours' => $neighbours,
            'messages'   => $messages,
            'server_now' => date('c'),
        ]);
        break;
    }

    default:
        vc_fail('Unknown action.', 404);
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    vc_fail('Database error. Please make sure the cinema database is imported and the Virtual Cinema tables exist.', 500);
} catch (Throwable $e) {
    vc_fail('Unexpected error.', 500);
}
