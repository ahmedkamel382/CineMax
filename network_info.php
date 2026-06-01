<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function is_private_ipv4(string $ip): bool {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return false;
    }

    return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[0-1])\.)/', $ip) === 1;
}

$candidates = [];
$tunnelUrl = null;
$tunnelHost = null;
$tunnelUpdatedAt = null;
$tunnelAgeSeconds = null;
$tunnelFreshSeconds = 30 * 60 * 60;
$tunnelAlive = false;

function add_candidate(array &$candidates, string $candidate): void {
    $candidate = trim($candidate);
    if (is_private_ipv4($candidate) && !in_array($candidate, $candidates, true)) {
        $candidates[] = $candidate;
    }
}


function http_url_is_alive(string $url): bool {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => "Cache-Control: no-cache\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $result = @file_get_contents(rtrim($url, '/') . '/', false, $context, 0, 128);
    if ($result === false && empty($http_response_header)) {
        return false;
    }
    if (!empty($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $code = (int)$m[1];
        return $code >= 200 && $code < 500;
    }
    return true;
}

function detect_windows_wifi_ip(): ?string {
    if (stripos(PHP_OS_FAMILY, 'Windows') === false || !function_exists('shell_exec')) {
        return null;
    }

    $output = @shell_exec('ipconfig');
    if (!$output) {
        return null;
    }

    $normalized = str_replace(["\r\n", "\r"], "\n", $output);
    if (preg_match('/Wireless LAN adapter Wi-Fi:[\s\S]*?IPv4 Address[^\n:]*:\s*([0-9.]+)/i', $normalized, $m)) {
        return $m[1];
    }

    $blocks = preg_split('/\r?\n\r?\n/', $output) ?: [];
    foreach ($blocks as $block) {
        if (
            stripos($block, 'Wireless LAN adapter Wi-Fi') !== false
            && stripos($block, 'Media disconnected') === false
            && preg_match('/IPv4 Address[^\:]*:\s*([0-9\.]+)/i', $block, $m)
        ) {
            return $m[1];
        }
    }

    foreach ($blocks as $block) {
        if (
            preg_match('/Default Gateway[^\n:]*:\s*([0-9.]+)/i', $block)
            && preg_match('/IPv4 Address[^\:]*:\s*([0-9\.]+)/i', $block, $m)
        ) {
            return $m[1];
        }
    }

    return null;
}

add_candidate($candidates, detect_windows_wifi_ip() ?? '');

foreach ([
    $_SERVER['SERVER_ADDR'] ?? '',
    gethostbyname(gethostname()),
] as $candidate) {
    add_candidate($candidates, $candidate);
}

foreach ([
    __DIR__ . '/cloudflare_tunnel_url.txt',
] as $source) {
    if (!is_file($source)) {
        continue;
    }
    $sourceMtime = (int)@filemtime($source);
    if (time() - $sourceMtime > $tunnelFreshSeconds) {
        continue;
    }

    $content = @file_get_contents($source);
    if ($content && preg_match('/https:\/\/[a-z0-9-]+\.trycloudflare\.com/i', $content, $m)) {
        $tunnelUrl = $m[0];
        $tunnelHost = parse_url($tunnelUrl, PHP_URL_HOST);
        $tunnelUpdatedAt = date('c', $sourceMtime);
        $tunnelAgeSeconds = time() - $sourceMtime;
        $tunnelAlive = http_url_is_alive($tunnelUrl);
        if (!$tunnelAlive) {
            $tunnelUrl = null;
            $tunnelHost = null;
            $tunnelUpdatedAt = date('c', $sourceMtime);
            $tunnelAgeSeconds = time() - $sourceMtime;
            continue;
        }
        break;
    }
}

echo json_encode([
    'status' => 'success',
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'detected_ip' => $candidates[0] ?? null,
    'tunnel_url' => $tunnelUrl,
    'tunnel_host' => $tunnelHost,
    'tunnel_updated_at' => $tunnelUpdatedAt,
    'tunnel_age_seconds' => $tunnelAgeSeconds,
    'tunnel_is_fresh' => $tunnelUrl !== null,
    'tunnel_is_alive' => $tunnelAlive,
    'best_host' => $tunnelHost ?: ($candidates[0] ?? ($_SERVER['HTTP_HOST'] ?? '')),
    'candidates' => $candidates,
]);
