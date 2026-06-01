<?php
/**
 * CINEMAX — TMDB SERVICE  (tmdb_service.php)
 * ============================================================
 * Server-side wrapper around the TMDB API.
 *
 * Every TMDB call is now made from PHP, NOT from the browser.
 * This means the Bearer token never leaves the server, so
 * users cannot see it in DevTools.
 *
 * USED BY: api.php (actions movies_now_showing, movies_upcoming,
 *                   movie_trailer, movie_cast, movie_details,
 *                   streaming_providers, person_details)
 *
 * RESPONSE SHAPE: identical to what the old in-browser
 * fetchMovies() returned, so the frontend grid keeps working
 * with minimal JS changes.
 * ============================================================
 */

// Load config (one level above web root) if not already loaded
if (!defined('TMDB_BEARER_TOKEN')) {
    $cp = dirname(__DIR__) . '/config.php';
    if (file_exists($cp)) require_once $cp;
}

if (!defined('TMDB_BASE'))   define('TMDB_BASE',   'https://api.themoviedb.org/3');
if (!defined('TMDB_IMG500')) define('TMDB_IMG500', 'https://image.tmdb.org/t/p/w500');
if (!defined('TMDB_IMGORIG'))define('TMDB_IMGORIG','https://image.tmdb.org/t/p/original');
if (!defined('TMDB_IMG185')) define('TMDB_IMG185', 'https://image.tmdb.org/t/p/w185');
if (!defined('TMDB_IMG300')) define('TMDB_IMG300', 'https://image.tmdb.org/t/p/w300');

// Streaming-exclusive provider IDs we filter out of "Now Showing"
// (Netflix, Amazon Prime, Disney+, HBO Max, Hulu, Shahid, Apple TV+, OSN+)
if (!defined('TMDB_STREAMING_IDS')) {
    define('TMDB_STREAMING_IDS', json_encode([8, 9, 337, 384, 350, 15, 283, 531]));
}

// ── Simple file cache to avoid hammering TMDB ────────────────
// /cache/tmdb/<hash>.json — invalidates after CACHE_TTL seconds
const TMDB_CACHE_DIR = __DIR__ . '/cache/tmdb';
const TMDB_CACHE_TTL = 1800; // 30 minutes

if (!is_dir(TMDB_CACHE_DIR)) {
    @mkdir(TMDB_CACHE_DIR, 0775, true);
}

/**
 * tmdb_get($endpoint, $params)
 * Low-level GET to TMDB with caching. Returns decoded array or null.
 */
function tmdb_get(string $endpoint, array $params = []): ?array {
    $bearer = defined('TMDB_BEARER_TOKEN') ? TMDB_BEARER_TOKEN : '';
    if (!$bearer) return null;

    $params['language'] = $params['language'] ?? 'en-US';
    $url = TMDB_BASE . $endpoint . '?' . http_build_query($params);

    $cacheKey  = md5($url);
    $cacheFile = TMDB_CACHE_DIR . '/' . $cacheKey . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < TMDB_CACHE_TTL) {
        $fh = @fopen($cacheFile, 'rb');
        if ($fh) {
            @flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
            @flock($fh, LOCK_UN);
            fclose($fh);
            if ($raw !== false) {
                $cached = json_decode($raw, true);
                if (is_array($cached)) return $cached;
            }
        }
    }

    $ctx = stream_context_create(['http' => [
        'timeout' => 10,
        'header'  => "Authorization: Bearer $bearer\r\nUser-Agent: CinemaxEgypt/5.0\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;

    $data = json_decode($raw, true);
    if (!is_array($data) || isset($data['status_code'])) {
        // TMDB error response — don't cache
        return null;
    }

    @file_put_contents($cacheFile, $raw, LOCK_EX);
    return $data;
}

/**
 * Fetch the TMDB genre list once, cache, and return id → name map.
 */
function tmdb_genres(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $data = tmdb_get('/genre/movie/list');
    $map = [];
    if (!empty($data['genres'])) {
        foreach ($data['genres'] as $g) $map[(int)$g['id']] = $g['name'];
    }
    $cached = $map;
    return $map;
}

/**
 * Convert a raw TMDB movie row into the shape the frontend expects.
 * Matches the structure that fetchMovies() used to return in script.js.
 */
function tmdb_format_movie(array $m, array $genres, string $type): array {
    return [
        'id'          => (string)$m['id'],
        'tmdbId'      => (int)$m['id'],
        'title'       => $m['title'] ?? $m['original_title'] ?? 'Unknown',
        'description' => $m['overview'] ?? '',
        'posterUrl'   => !empty($m['poster_path'])
            ? TMDB_IMG500 . $m['poster_path']
            : 'https://via.placeholder.com/500x750?text=No+Poster',
        'backdropUrl' => !empty($m['backdrop_path'])
            ? TMDB_IMGORIG . $m['backdrop_path']
            : 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?w=1920&q=80',
        'trailerUrl'  => '',
        'imdbRating'  => !empty($m['vote_average']) ? number_format($m['vote_average'], 1) : '–',
        'rtRating'    => !empty($m['vote_average']) ? floor($m['vote_average'] * 10) . '%' : '–%',
        'releaseDate' => $m['release_date'] ?? '',
        'isComingSoon'        => $type === 'upcoming',
        'isCurrentlyPlaying'  => $type === 'now_playing',
        'isCatalog'           => false,
        'genres'              => array_values(array_filter(array_map(
            fn($id) => $genres[(int)$id] ?? null,
            $m['genre_ids'] ?? []
        ))),
    ];
}

/**
 * tmdb_get_curated_movies($type)
 * Reproduces the curation logic from the old in-browser fetchMovies():
 *   • merges EG + US regions
 *   • date-filters (now_playing = released, upcoming = future)
 *   • drops streaming-exclusive titles
 *   • mixes 4 Arabic + 8 English, sorted by popularity
 *
 * Returns an array of frontend-ready movie objects.
 */
function tmdb_get_curated_movies(string $type): array {
    $genres = tmdb_genres();

    $resEG = tmdb_get("/movie/$type", ['page' => 1, 'region' => 'EG']);
    $resUS = tmdb_get("/movie/$type", ['page' => 1, 'region' => 'US']);

    $combined = array_merge($resEG['results'] ?? [], $resUS['results'] ?? []);

    // Deduplicate by movie id
    $byId = [];
    foreach ($combined as $m) {
        if (!empty($m['id'])) $byId[$m['id']] = $m;
    }
    $results = array_values($byId);

    // Date filter
    $today = date('Y-m-d');
    $results = array_filter($results, function ($m) use ($type, $today) {
        if (empty($m['release_date'])) return false;
        if ($type === 'now_playing') return $m['release_date'] <= $today;
        if ($type === 'upcoming')    return $m['release_date'] >  $today;
        return true;
    });

    // Streaming-exclusive filter — only check the top 30 to avoid 30+ provider lookups
    // (we'll trim to 12 below anyway, this gives us breathing room)
    usort($results, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
    $top30 = array_slice($results, 0, 30);

    $streamingIds = json_decode(TMDB_STREAMING_IDS, true);
    $filtered = [];
    foreach ($top30 as $m) {
        $pr = tmdb_get("/movie/{$m['id']}/watch/providers");
        $usFlat = $pr['results']['US']['flatrate'] ?? [];
        $hasStreaming = false;
        foreach ($usFlat as $p) {
            if (in_array((int)($p['provider_id'] ?? 0), $streamingIds, true)) {
                $hasStreaming = true;
                break;
            }
        }
        if (!$hasStreaming) $filtered[] = $m;
    }
    $results = $filtered;

    // 4 Arabic + (12 − Arabic count) English, sorted by popularity
    $arabic  = array_values(array_filter($results, fn($m) => ($m['original_language'] ?? '') === 'ar'));
    $english = array_values(array_filter($results, fn($m) => ($m['original_language'] ?? '') === 'en'));

    usort($arabic,  fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));
    usort($english, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

    $topAr = array_slice($arabic, 0, 4);
    $topEn = array_slice($english, 0, 12 - count($topAr));

    $final = array_merge($topAr, $topEn);
    usort($final, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0));

    return array_map(fn($m) => tmdb_format_movie($m, $genres, $type), $final);
}

/**
 * Return only movies that actually have future showtimes in MySQL, so the
 * homepage does not advertise films the user cannot book.
 */
function tmdb_get_bookable_movies(PDO $pdo, int $limit = 12): array {
    $stmt = $pdo->prepare("
        SELECT tmdb_movie_id, MAX(movie_title) AS movie_title, MAX(movie_poster) AS movie_poster,
               MIN(show_datetime) AS next_showtime, COUNT(*) AS future_showtimes
        FROM showtimes
        WHERE show_datetime > NOW()
        GROUP BY tmdb_movie_id
        ORDER BY next_showtime ASC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return tmdb_get_curated_movies('now_playing');
    }

    $genres = tmdb_genres();
    $movies = [];
    foreach ($rows as $row) {
        $tmdbId = (int)$row['tmdb_movie_id'];
        $details = tmdb_get("/movie/$tmdbId");
        if (is_array($details) && !isset($details['status_code'])) {
            $details['genre_ids'] = array_map(fn($g) => $g['id'] ?? null, $details['genres'] ?? []);
            $movie = tmdb_format_movie($details, $genres, 'now_playing');
        } else {
            $movie = [
                'id' => (string)$tmdbId,
                'tmdbId' => $tmdbId,
                'title' => $row['movie_title'] ?? 'Unknown',
                'description' => '',
                'posterUrl' => $row['movie_poster'] ?: 'https://via.placeholder.com/500x750?text=No+Poster',
                'backdropUrl' => 'https://images.unsplash.com/photo-1536440136628-849c177e76a1?w=1920&q=80',
                'trailerUrl' => '',
                'imdbRating' => '-',
                'rtRating' => '-%',
                'releaseDate' => '',
                'isComingSoon' => false,
                'isCurrentlyPlaying' => true,
                'isCatalog' => false,
                'genres' => [],
            ];
        }
        $movie['futureShowtimes'] = (int)$row['future_showtimes'];
        $movie['nextShowtime'] = $row['next_showtime'];
        $movies[] = $movie;
    }
    return $movies;
}

/**
 * tmdb_get_trailer($tmdbId)
 * Returns an embeddable YouTube URL or '' if no good trailer found.
 * Mirrors the picking logic from the old in-browser fetchTrailer().
 */
function tmdb_get_trailer(int $tmdbId): string {
    $data = tmdb_get("/movie/$tmdbId/videos");
    $videos = $data['results'] ?? [];

    $trailers = array_filter($videos, fn($v) =>
        ($v['type'] ?? '') === 'Trailer' && ($v['site'] ?? '') === 'YouTube'
    );
    if (!$trailers) return '';

    // Drop DVD/Blu-ray promos when we have alternatives
    $clean = array_filter($trailers, fn($t) =>
        !preg_match('/dvd|vhs|blu-ray|home video|buy on/i', $t['name'] ?? '')
    );
    if ($clean) $trailers = $clean;

    // Prefer Official / Theatrical / Main and not "Final"
    $pick = null;
    foreach ($trailers as $t) {
        $n = strtolower($t['name'] ?? '');
        if ((str_contains($n, 'official') || str_contains($n, 'theatrical') || str_contains($n, 'main'))
            && !str_contains($n, 'final')) {
            $pick = $t;
            break;
        }
    }
    if (!$pick) {
        foreach ($trailers as $t) {
            if (!str_contains(strtolower($t['name'] ?? ''), 'final')) { $pick = $t; break; }
        }
    }
    if (!$pick) $pick = reset($trailers);

    return $pick ? 'https://www.youtube.com/embed/' . $pick['key'] : '';
}

/**
 * tmdb_get_cast($tmdbId)
 * Returns top 8 cast + director (director first) in the shape the
 * frontend expects (id, name, character, photo, isDirector).
 */
function tmdb_get_cast(int $tmdbId): array {
    $data = tmdb_get("/movie/$tmdbId/credits");
    if (!$data) return [];

    $cast = array_slice($data['cast'] ?? [], 0, 8);
    $cast = array_map(fn($a) => [
        'id'         => (int)$a['id'],
        'name'       => $a['name'] ?? '',
        'character'  => $a['character'] ?? '',
        'photo'      => !empty($a['profile_path']) ? TMDB_IMG185 . $a['profile_path'] : null,
        'isDirector' => false,
    ], $cast);

    foreach ($data['crew'] ?? [] as $c) {
        if (($c['job'] ?? '') === 'Director') {
            array_unshift($cast, [
                'id'         => (int)$c['id'],
                'name'       => $c['name'] ?? '',
                'character'  => 'Director',
                'photo'      => !empty($c['profile_path']) ? TMDB_IMG185 . $c['profile_path'] : null,
                'isDirector' => true,
            ]);
            break;
        }
    }
    return $cast;
}

/**
 * tmdb_get_streaming($tmdbId)
 * Returns a list of streaming providers (logos + names) to show
 * on the movie details page. EG preferred, falls back to US.
 */
function tmdb_get_streaming(int $tmdbId): array {
    $data = tmdb_get("/movie/$tmdbId/watch/providers");
    $providers = $data['results']['EG']['flatrate']
        ?? $data['results']['US']['flatrate']
        ?? [];
    return array_map(fn($p) => [
        'provider_id'   => (int)($p['provider_id'] ?? 0),
        'provider_name' => $p['provider_name'] ?? '',
        'logo_url'      => !empty($p['logo_path']) ? TMDB_IMG500 . $p['logo_path'] : null,
    ], array_slice($providers, 0, 5));
}

/**
 * tmdb_get_movie_details($tmdbId)
 * Used when a user clicks a film inside a person's filmography
 * that isn't already in our Now Showing / Coming Soon cache.
 */
function tmdb_get_movie_details(int $tmdbId): ?array {
    $m = tmdb_get("/movie/$tmdbId");
    if (!$m || empty($m['id'])) return null;
    $today  = date('Y-m-d');
    $type   = !empty($m['release_date']) && $m['release_date'] > $today ? 'upcoming' : 'now_playing';
    $genres = tmdb_genres();

    // /movie/{id} returns genres as full objects, not ids — normalize first
    if (!empty($m['genres']) && empty($m['genre_ids'])) {
        $m['genre_ids'] = array_map(fn($g) => (int)$g['id'], $m['genres']);
    }
    return tmdb_format_movie($m, $genres, $type);
}

/**
 * tmdb_get_person($personId)
 * Returns combined bio + deduplicated filmography for the
 * Person view. Matches what the old frontend code built up.
 */
function tmdb_get_person(int $personId): ?array {
    $bio     = tmdb_get("/person/$personId");
    $credits = tmdb_get("/person/$personId/movie_credits");
    if (!$bio || empty($bio['id'])) return null;

    // Merge cast + crew (Director) by movie id
    $byId = [];
    foreach ($credits['cast'] ?? [] as $m) {
        if (empty($m['poster_path'])) continue;
        $byId[$m['id']] = $m + ['roleLabel' => $m['character'] ?? 'Actor'];
    }
    foreach ($credits['crew'] ?? [] as $m) {
        if (($m['job'] ?? '') !== 'Director') continue;
        if (empty($m['poster_path'])) continue;
        $byId[$m['id']] = ($byId[$m['id']] ?? $m) + ['roleLabel' => 'Director'];
    }
    $movies = array_values(array_filter($byId, fn($m) => ($m['vote_average'] ?? 0) > 0));
    usort($movies, fn($a, $b) => ($b['vote_average'] ?? 0) <=> ($a['vote_average'] ?? 0));

    $avg = count($movies)
        ? number_format(array_sum(array_column($movies, 'vote_average')) / count($movies), 1)
        : '–';

    $top20 = array_slice($movies, 0, 20);
    $top20 = array_map(fn($m) => [
        'id'           => (int)$m['id'],
        'title'        => $m['title'] ?? $m['original_title'] ?? '',
        'poster_path'  => $m['poster_path'] ?? null,
        'poster_url'   => !empty($m['poster_path']) ? TMDB_IMG500 . $m['poster_path'] : null,
        'vote_average' => (float)($m['vote_average'] ?? 0),
        'roleLabel'    => $m['roleLabel'] ?? '',
        'release_date' => $m['release_date'] ?? '',
    ], $top20);

    return [
        'id'                  => (int)$bio['id'],
        'name'                => $bio['name'] ?? '',
        'photo'               => !empty($bio['profile_path']) ? TMDB_IMG300 . $bio['profile_path'] : null,
        'biography'           => $bio['biography'] ?? '',
        'birthday'            => $bio['birthday'] ?? '',
        'place_of_birth'      => $bio['place_of_birth'] ?? '',
        'known_for_department'=> $bio['known_for_department'] ?? 'Acting',
        'film_count'          => count($movies),
        'avg_rating'          => $avg,
        'films'               => $top20,
    ];
}
