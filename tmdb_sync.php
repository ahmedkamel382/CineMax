<?php
/**
 * CINEMAX — TMDB LIVE SYNC  (tmdb_sync.php)
 * ============================================================
 * Fetches now-playing and upcoming movies from TMDB and
 * generates cinema showtimes for any new movies.
 *
 * HOW TO RUN:
 * Manual CLI:  php tmdb_sync.php
 * Browser:     yoursite.com/tmdb_sync.php?key=CRON_SECRET
 * Auto (cron): 0 * * * * php /path/to/tmdb_sync.php
 *
 * WHAT IT DOES:
 * 1. Fetches now_playing + upcoming movies from TMDB (EG & US regions)
 * 2. Applies same date filter as the frontend JS
 * (upcoming = release_date > today only)
 * 3. For each NEW now_playing movie → generates 14 days
 * of showtimes across cinemas in ALL of Egypt
 * 4. Logs the sync run to the sync_log table
 * ============================================================
 */

if (PHP_SAPI !== 'cli') {
    $cp = dirname(__DIR__) . '/config.php';
    if (file_exists($cp)) require_once $cp;
    $secret = defined('CRON_SECRET') ? CRON_SECRET : '';
    if ($secret && ($_GET['key'] ?? '') !== $secret) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden. Provide ?key=CRON_SECRET in the URL.']));
    }
    header('Content-Type: text/plain');
}

$cp = dirname(__DIR__) . '/config.php';
if (file_exists($cp)) require_once $cp;
require_once 'db.php';

$TMDB_BEARER = defined('TMDB_BEARER_TOKEN') ? TMDB_BEARER_TOKEN : ($_ENV['TMDB_BEARER_TOKEN'] ?? '');
if (!$TMDB_BEARER) {
    die("ERROR: TMDB_BEARER_TOKEN not set in config.php\nGet a free key at https://www.themoviedb.org/settings/apin");
}

define('TMDB_BASE', 'https://api.themoviedb.org/3');
define('IMG_W500',  'https://image.tmdb.org/t/p/w500');

function tmdb(string $endpoint, string $bearer, array $params = []): ?array {
    $params['language'] = 'en-US';
    $url = TMDB_BASE . $endpoint . '?' . http_build_query($params);
    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'header'  => "Authorization: Bearer $bearer\r\nUser-Agent: CinemaxEgypt/3.0\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? json_decode($raw, true) : null;
}

function log_line(string $msg): void {
    echo date('[Y-m-d H:i:s]') . " $msg\n";
    flush();
}

// ── Get existing tmdb_movie_ids so we know what's new ─────────
$futureShowtimeCounts = [];
$futureRows = $pdo->query("
    SELECT tmdb_movie_id, COUNT(*) AS future_count
    FROM showtimes
    WHERE show_datetime > NOW()
    GROUP BY tmdb_movie_id
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($futureRows as $row) {
    $futureShowtimeCounts[(int)$row['tmdb_movie_id']] = (int)$row['future_count'];
}

// ── Hall IDs for showtimes (ALL of Egypt) ───────
$halls = $pdo->query("
    SELECT h.id, h.type
    FROM halls h
    JOIN cinemas c ON h.cinema_id = c.id
    JOIN governorates g ON c.governorate_id = g.id
    ORDER BY RAND()
    LIMIT 40
")->fetchAll();

$prices = ['standard' => 150, 'vip' => 250, 'imax' => 220, '4dx' => 280];
$times  = ['10:30:00', '13:00:00', '15:45:00', '18:30:00', '21:15:00', '00:00:00'];

$stmtInsertShowtime = $pdo->prepare("
    INSERT IGNORE INTO showtimes
        (tmdb_movie_id, movie_title, movie_poster, hall_id, show_datetime, price)
    VALUES (?, ?, ?, ?, ?, ?)
");

function generateShowtimes(PDO $pdo, $stmt, array $halls, array $prices, array $times, int $tmdbId, string $title, ?string $poster): int {
    if (!$halls) return 0;
    $count = 0;
    $maxTimes = count($times);

    // 1. Assign this movie to 10-15 random halls for the ENTIRE WEEK
    // This ensures if a user selects a cinema, the movie is there every day.
    shuffle($halls);
    $assignedHalls = array_slice($halls, 0, rand(10, 15));

    // 2. Loop through today + next 13 days
    for ($day = 0; $day <= 13; $day++) {
        $date = date('Y-m-d', strtotime("+$day days"));

        foreach ($assignedHalls as $hall) {
            $price = $prices[$hall['type']] ?? 150;

            // 3. GUARANTEE 3 to 6 showings every single day
            $numShowings = rand(3, $maxTimes);

            // Shuffle times, pick the amount we need, and sort chronologically
            $dailyTimes = $times;
            shuffle($dailyTimes);
            $selectedTimes = array_slice($dailyTimes, 0, $numShowings);
            sort($selectedTimes);

            // Insert these exact showtimes into the database
            foreach ($selectedTimes as $t) {
                try {
                    $stmt->execute([$tmdbId, $title, $poster, $hall['id'], "$date $t", $price]);
                    $count += $stmt->rowCount();
                } catch (Throwable $e) {
                    // Keep syncing the rest of the catalogue.
                }
            }
        }
    }
    return $count;
}

log_line('=== CINEMAX TMDB SYNC STARTED ===');

$today   = date('Y-m-d');
$stats   = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'showtimes' => 0, 'errors' => 0];

// ── Sync both now_playing and upcoming ────────────────────────
$endpointMap = ['now_playing' => true, 'upcoming' => false];

foreach ($endpointMap as $endpoint => $generateShowtimesFlag) {
    log_line("--- Fetching: $endpoint ---");

    // Loop through BOTH regions
    $regions = ['EG', 'US'];
    foreach ($regions as $region) {
        log_line("  -> Region: $region");
        for ($page = 1; $page <= 2; $page++) {
            $data = tmdb("/movie/$endpoint", $TMDB_BEARER, ['page' => $page, 'region' => $region]);
            if (!$data || empty($data['results'])) {
                $stats['errors']++;
                break;
            }

            log_line("    Page $page: " . count($data['results']) . " movies");

            foreach ($data['results'] as $m) {
                if (empty($m['release_date'])) continue;

                // ── SAME DATE FILTER AS FRONTEND ──────────────────
                if ($endpoint === 'now_playing' && $m['release_date'] > $today) continue;
                if ($endpoint === 'upcoming' && $m['release_date'] <= $today) continue;

                // ── GUARD 1: STRICT LANGUAGE CURATION ──
                // Blocks Indian (hi) and other non-target regional languages completely
                $lang = $m['original_language'] ?? '';
                if ($lang !== 'ar' && $lang !== 'en') {
                    continue;
                }

                $tmdbId = (int)$m['id'];
                $title = $m['title'] ?? $m['original_title'] ?? 'Unknown';
                $poster = $m['poster_path'] ? IMG_W500 . $m['poster_path'] : null;

                // ── GUARD 2: BLOCK STREAMING-EXCLUSIVE TITLES (Netflix, Disney+, etc.) ──
                $pr = tmdb("/movie/{$tmdbId}/watch/providers", $TMDB_BEARER);
                $usFlat = $pr['results']['US']['flatrate'] ?? [];
                $egFlat = $pr['results']['EG']['flatrate'] ?? [];
                $flatrate = array_merge($usFlat, $egFlat);

                // Match against enterprise streaming provider IDs
                $streamingIds = [8, 9, 337, 384, 350, 15, 283, 531];
                $hasStreaming = false;
                foreach ($flatrate as $p) {
                    if (in_array((int)($p['provider_id'] ?? 0), $streamingIds, true)) {
                        $hasStreaming = true;
                        break;
                    }
                }

                // If it's a streaming-exclusive (like a Netflix Original), skip scheduling it
                if ($hasStreaming) {
                    continue;
                }

                // Skip if we already have showtimes for this movie
                $futureCount = $futureShowtimeCounts[$tmdbId] ?? 0;

                // ── RESTORED: GENERATE SHOWTIMES LOGIC ──
                if ($generateShowtimesFlag && $halls) {
                    if ($futureCount >= 120) {
                        $stats['skipped']++;
                        continue;
                    }
                    $count = generateShowtimes($pdo, $stmtInsertShowtime, $halls, $prices, $times, $tmdbId, $title, $poster);
                    $stats['showtimes'] += $count;
                    $futureShowtimeCounts[$tmdbId] = $futureCount + $count;
                    if ($count > 0) {
                        log_line("  + Topped up showtimes for: $title ($count new slots)");
                    }
                    $futureCount > 0 ? $stats['updated']++ : $stats['added']++;
                } else {
                    $futureCount > 0 ? $stats['skipped']++ : $stats['added']++;
                }

                usleep(100000); // 0.1s delay — be polite to TMDB
            }
            if ($page >= ($data['total_pages'] ?? 1)) break;
        }
    }
}

// ── Write sync log ────────────────────────────────────────────
$note = "Added:{$stats['added']} Updated:{$stats['updated']} Skipped:{$stats['skipped']} Showtimes:{$stats['showtimes']} Errors:{$stats['errors']}";
try {
    $pdo->prepare("INSERT INTO sync_log (added, updated, errors, note) VALUES (?, ?, ?, ?)")
        ->execute([$stats['added'], $stats['updated'], $stats['errors'], $note]);
} catch (Exception $e) { /* sync_log table may not exist yet */ }

log_line('=== SYNC COMPLETE ===');
log_line("  New movies processed: {$stats['added']}");
log_line("  Movies topped up: {$stats['updated']}");
log_line("  Movies skipped (enough future showtimes): {$stats['skipped']}");
log_line("  Showtime slots created: {$stats['showtimes']}");

// ── Remove showtimes from yesterday and earlier ────────────────
// This keeps the database clean. Showtimes more than 1 hour past
// are removed so the booking modal never shows expired screenings.
$deleted = $pdo->exec("DELETE FROM showtimes WHERE show_datetime < NOW() - INTERVAL 1 HOUR");
log_line("  Past showtimes cleaned up: $deleted rows removed");
log_line('');
log_line('To run hourly automatically, add this to crontab:');
log_line('  0 * * * * php ' . __FILE__);