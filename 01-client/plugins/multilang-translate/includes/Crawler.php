<?php
/**
 * Slate Multi Language Visual Translation — crawler.
 * Discovery and processing are separate so the admin can scan through short
 * AJAX requests without one long request timing out.
 */
class MLT_Crawler {
    // Pages whose body is a rendered transcript of ephemeral, per-admin
    // dynamic content (chat history, live report output) rather than static
    // UI copy. Scanning them harvests whatever conversation happened to be
    // in the initiating admin's session at scan time — including AI replies
    // in whatever language they were generated in — as bogus "source"
    // strings. Matched as a substring against the URL path.
    const EPHEMERAL_URL_FRAGMENTS = ['/mcp-gateway/admin/chat.php'];

    private static function isEphemeralUrl(string $url): bool {
        $path = (string) parse_url($url, PHP_URL_PATH);
        foreach (self::EPHEMERAL_URL_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) return true;
        }
        return false;
    }

    public static function discoverUrls(int $tid = 0): array {
        $urls = ['admin' => [], 'customer' => [], 'public' => []];
        $base = rtrim(SLATE_URL, '/');
        foreach (Hook::applyFilters('admin_nav_items', []) as $item) {
            if (!empty($item['href'])) $urls['admin'][] = self::normalizeUrl((string)$item['href']);
        }
        foreach (Hook::applyFilters('customer_nav_items', []) as $item) {
            if (!empty($item['href'])) $urls['customer'][] = self::normalizeUrl((string)$item['href']);
        }
        foreach (Hook::applyFilters('public_routes', []) as $prefix => $handler) {
            $urls['public'][] = $base . '/' . ltrim((string)$prefix, '/');
        }
        $urls['public'][] = $base . '/';
        $urls['admin'][] = $base . '/admin/index.php';
        $urls['customer'][] = $base . '/customer/index.php';
        if ($tid > 0) {
            foreach (self::manualUrls($tid) as $url) $urls[self::guessArea($url)][] = $url;
        }
        foreach ($urls as $area => $list) {
            $valid = [];
            foreach ($list as $url) {
                if ($url !== null && self::sameHost($url, SLATE_URL) && !self::isEphemeralUrl($url)) $valid[$url] = true;
            }
            $urls[$area] = array_keys($valid);
        }
        return $urls;
    }

    public static function discoveryQueue(int $tid): array {
        $queue = [];
        foreach (self::discoverUrls($tid) as $area => $urls) {
            foreach ($urls as $url) $queue[] = ['url' => $url, 'area' => $area];
        }
        return $queue;
    }

    public static function manualUrls(int $tid): array {
        $raw = (string)(Database::setting('multilang-translate.scan_manual_urls') ?? '');
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $urls = [];
        foreach ($lines as $line) {
            $url = self::normalizeUrl(trim($line));
            if ($url !== null && self::sameHost($url, SLATE_URL)) $urls[$url] = true;
        }
        return array_keys($urls);
    }

    public static function saveManualUrls(int $tid, string $raw): void {
        Database::setSetting('multilang-translate.scan_manual_urls', $raw);
    }

    private static function guessArea(string $url): string {
        $path = (string)parse_url($url, PHP_URL_PATH);
        if (strpos($path, '/admin/') !== false) return 'admin';
        if (strpos($path, '/customer/') !== false) return 'customer';
        return 'public';
    }

    public static function scanBatch(int $tid, array $items): array {
        // Release the session lock before fetching anything.
        //
        // session.save_handler is `files`, so PHP holds an EXCLUSIVE lock on
        // the session file for the whole request. fetch() forwards the caller's
        // cookies — including SLATE_SID — so each loopback request tries to
        // open the same session, blocks in session_start() waiting for a lock
        // this very request is holding, and dies at CURLOPT_TIMEOUT.
        //
        // Observed before this line existed: every URL failed at 7002–7005 ms
        // with 0 bytes, and the translations table had never held a single row.
        // Uniform failure is the tell — every page starts a session here, public
        // ones included (a bare GET of /slate/ returns set-cookie: SLATE_SID),
        // so all 63 URLs deadlock, not just the authenticated ones.
        //
        // Closing is safe for reads: $_SESSION stays populated in memory, so
        // Auth::userId() and friends keep working. Only writes stop persisting,
        // and nothing between here and the end of the request writes to the
        // session — the caller (admin/api.php) only records an audit row and
        // emits JSON. If that ever changes, re-open with session_start() AFTER
        // the loop, never before it.
        //
        // Guarded on session_status() so a CLI caller, which has no session, is
        // unaffected rather than warned at.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $result = ['processed' => 0, 'pages' => 0, 'new_strings' => 0, 'errors' => []];
        $cookieHeader = self::currentCookieHeader();
        foreach (array_slice($items, 0, 2) as $item) {
            $result['processed']++;
            $url = is_array($item) ? trim((string)($item['url'] ?? '')) : trim((string)$item);
            $area = is_array($item) ? (string)($item['area'] ?? 'public') : self::guessArea($url);
            if (!in_array($area, ['admin', 'customer', 'public'], true)) $area = 'public';
            $url = self::normalizeUrl($url);
            if ($url === null || !self::sameHost($url, SLATE_URL)) {
                $result['errors'][] = ($url ?: 'Invalid URL') . ': external or invalid URL skipped';
                continue;
            }
            $html = self::fetch($url, $cookieHeader, $result['errors']);
            if ($html === null) continue;
            $result['pages']++;
            try {
                $result['new_strings'] += MLT_Harvester::harvest($tid, $html, $area);
            } catch (Throwable $e) {
                $result['errors'][] = $url . ': harvest failed: ' . $e->getMessage();
            }
        }
        return $result;
    }

    public static function run(int $tid): array {
        $result = ['pages' => 0, 'new_strings' => 0, 'errors' => []];
        foreach (array_chunk(self::discoveryQueue($tid), 2) as $batch) {
            $part = self::scanBatch($tid, $batch);
            $result['pages'] += $part['pages'];
            $result['new_strings'] += $part['new_strings'];
            $result['errors'] = array_merge($result['errors'], $part['errors']);
        }
        return $result;
    }

    private static function currentCookieHeader(): string {
        $pairs = [];
        foreach ($_COOKIE as $key => $value) {
            if ($key === '' || is_array($value)) continue;
            $key = str_replace(["\r", "\n", ';'], '', (string)$key);
            $value = str_replace(["\r", "\n", ';'], '', (string)$value);
            $pairs[] = $key . '=' . $value;
        }
        return implode('; ', $pairs);
    }

    private static function fetch(string $url, string $cookieHeader, array &$errors): ?string {
        if (!function_exists('curl_init')) { $errors[] = $url . ': curl unavailable'; return null; }
        $headers = [
            'X-MLT-Scan: 1',
            'X-Requested-With: XMLHttpRequest',
            'Accept: text/html,application/xhtml+xml',
            'Referer: ' . rtrim(SLATE_URL, '/') . '/admin/index.php',
            // Forces every crawled page to render in the source (English)
            // locale regardless of the initiating admin's own language —
            // otherwise a scan run while French is active on the switcher
            // harvests French page text as bogus "English" source strings.
            // Generic, core-level mechanism (not MLT-specific) — see
            // I18n::currentLocale().
            'X-Slate-Force-Locale: en',
        ];
        if (!empty($_SERVER['HTTP_USER_AGENT'])) $headers[] = 'User-Agent: ' . $_SERVER['HTTP_USER_AGENT'];
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) $headers[] = 'Authorization: ' . $_SERVER['HTTP_AUTHORIZATION'];
        if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 7,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($error !== '') { $errors[] = $url . ': ' . $error; return null; }
        if ($code >= 300 && $code < 400) { $errors[] = $url . ': HTTP ' . $code . ' redirect; authentication may be required'; return null; }
        if ($code < 200 || $code >= 400) { $errors[] = $url . ': HTTP ' . ($code ?: 'no response'); return null; }
        return is_string($body) ? $body : null;
    }

    private static function normalizeUrl(string $url): ?string {
        $url = trim($url);
        if ($url === '' || strpos($url, '#') === 0) return null;
        if (strpos($url, '//') === 0) $url = (parse_url(SLATE_URL, PHP_URL_SCHEME) ?: 'https') . ':' . $url;
        elseif (isset($url[0]) && $url[0] === '/') $url = rtrim(SLATE_URL, '/') . $url;
        elseif (!preg_match('#^https?://#i', $url)) return null;
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return null;
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;
        $out = strtolower($parts['scheme']) . '://' . $parts['host'];
        if (!empty($parts['port'])) $out .= ':' . (int)$parts['port'];
        $out .= $parts['path'] ?? '/';
        if (!empty($parts['query'])) $out .= '?' . $parts['query'];
        return strlen($out) <= 2048 ? $out : null;
    }

    /** Port to compare with, defaulting per scheme when the URL has none explicit. */
    private static function effectivePort(string $url): int {
        $port = parse_url($url, PHP_URL_PORT);
        if (is_int($port)) return $port;
        return strtolower((string)parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
    }

    private static function sameHost(string $url, string $base): bool {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $baseHost = strtolower((string)parse_url($base, PHP_URL_HOST));
        return $host !== '' && $host === $baseHost && self::effectivePort($url) === self::effectivePort($base);
    }
}
