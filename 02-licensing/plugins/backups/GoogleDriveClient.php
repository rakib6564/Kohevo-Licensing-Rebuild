<?php
/**
 * Backups — thin Google Drive v3 REST client.
 *
 * Mirrors plugins/booking/GoogleCalendarSync.php's house style exactly:
 * client_id in plaintext, client_secret/tokens via slate_encrypt_secret()/
 * slate_decrypt_secret() (AES-256-GCM keyed off APP_SECRET), a `state` CSRF
 * nonce on the OAuth callback, and the same curl() helper shape.
 *
 * Scope is the narrow `drive.file` — this app can only see/manage files IT
 * creates in the user's Drive, never their whole Drive. Deliberately not
 * `drive`/`drive.readonly`.
 *
 * Uploads use Drive's *resumable* upload protocol so a multi-hundred-MB
 * backup can be sent in ~8 MB chunks across several frequent_cron ticks
 * instead of needing one long-running HTTP request.
 */

class GoogleDriveClient
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const API_BASE    = 'https://www.googleapis.com/drive/v3';
    private const UPLOAD_BASE = 'https://www.googleapis.com/upload/drive/v3/files';
    private const SCOPE       = 'https://www.googleapis.com/auth/drive.file';

    // Must be a multiple of 256 KiB per Google's resumable-upload spec.
    public const CHUNK_SIZE = 8 * 1024 * 1024;

    // ── Configuration ────────────────────────────────────────────

    public static function isConfigured(): bool {
        return (string)(Database::setting('backups.google_client_id') ?? '') !== ''
            && (string)(Database::setting('backups.google_client_secret') ?? '') !== '';
    }

    public static function isConnected(): bool {
        return (string)(Database::setting('backups.google_refresh_token') ?? '') !== '';
    }

    private static function clientId(): string {
        return (string)(Database::setting('backups.google_client_id') ?? '');
    }

    private static function clientSecret(): ?string {
        $enc = (string)(Database::setting('backups.google_client_secret') ?? '');
        if ($enc === '') return null;
        return slate_decrypt_secret($enc);
    }

    public static function redirectUri(): string {
        return rtrim(SLATE_URL, '/') . '/plugins/backups/admin/gdrive-callback.php';
    }

    // ── OAuth: connect / disconnect ──────────────────────────────

    public static function authUrl(string $csrfNonce): string {
        $params = [
            'client_id'              => self::clientId(),
            'redirect_uri'           => self::redirectUri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'prompt'                 => 'consent', // guarantees a refresh_token even on reconnect
            'include_granted_scopes' => 'true',
            'state'                  => $csrfNonce,
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /** Exchange an OAuth `code` for tokens and store them. */
    public static function connect(string $code): array {
        if (!self::isConfigured()) {
            return ['ok' => false, 'error' => 'Google Drive isn\'t configured yet — add a Client ID/Secret first.'];
        }
        $resp = self::httpPost(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => (string) self::clientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        if (!$resp['ok'] || empty($resp['body']['access_token'])) {
            slate_log('GoogleDriveClient: token exchange failed: ' . json_encode($resp['body'] ?? $resp['error']), 'error');
            return ['ok' => false, 'error' => 'Google didn\'t confirm the connection. Please try again.'];
        }
        $tokens = $resp['body'];
        if (empty($tokens['refresh_token'])) {
            return ['ok' => false, 'error' => 'Google didn\'t grant a long-lived connection. In your Google Account → Security → Third-party access, remove any existing access for this app, then try connecting again.'];
        }

        Database::setSetting('backups.google_access_token', slate_encrypt_secret((string)$tokens['access_token']));
        Database::setSetting('backups.google_refresh_token', slate_encrypt_secret((string)$tokens['refresh_token']));
        Database::setSetting('backups.google_token_expires_at', date('Y-m-d H:i:s', time() + (int)($tokens['expires_in'] ?? 3600)));

        // Best-effort: surface the connected account's email for the settings page.
        $token = (string)$tokens['access_token'];
        $about = self::curl(self::API_BASE . '/about?fields=user(emailAddress)', 'GET', null, ['Authorization: Bearer ' . $token]);
        $email = $about['ok'] ? (string)($about['body']['user']['emailAddress'] ?? '') : '';
        if ($email !== '') Database::setSetting('backups.google_connected_email', $email);

        AuditLog::record('backups.google_connected', '', ['email' => $email]);
        return ['ok' => true, 'email' => $email];
    }

    public static function disconnect(): void {
        $enc = (string)(Database::setting('backups.google_refresh_token') ?? '');
        if ($enc !== '') {
            $token = slate_decrypt_secret($enc);
            if ($token) self::httpPost('https://oauth2.googleapis.com/revoke', ['token' => $token]);
        }
        foreach (['google_access_token', 'google_refresh_token', 'google_token_expires_at', 'google_connected_email', 'google_folder_id'] as $k) {
            Database::setSetting('backups.' . $k, null);
        }
        AuditLog::record('backups.google_disconnected');
    }

    /** A valid (non-expired) access token, refreshing it first if needed. Null if not connected or refresh fails. */
    public static function accessToken(): ?string {
        $encRefresh = (string)(Database::setting('backups.google_refresh_token') ?? '');
        if ($encRefresh === '') return null;

        $expiresAt = strtotime((string)(Database::setting('backups.google_token_expires_at') ?? ''));
        $encAccess = (string)(Database::setting('backups.google_access_token') ?? '');
        // 2-minute safety margin so a chunk upload never starts with a token that expires mid-request.
        if ($expiresAt !== false && $expiresAt > time() + 120 && $encAccess !== '') {
            return slate_decrypt_secret($encAccess);
        }

        $refreshToken = slate_decrypt_secret($encRefresh);
        if (!$refreshToken || !self::isConfigured()) return null;

        $resp = self::httpPost(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => self::clientId(),
            'client_secret' => (string) self::clientSecret(),
            'grant_type'    => 'refresh_token',
        ]);
        if (!$resp['ok'] || empty($resp['body']['access_token'])) {
            slate_log('GoogleDriveClient: access-token refresh failed: ' . json_encode($resp['body'] ?? $resp['error']), 'warning');
            return null;
        }
        $accessToken = (string)$resp['body']['access_token'];
        Database::setSetting('backups.google_access_token', slate_encrypt_secret($accessToken));
        Database::setSetting('backups.google_token_expires_at', date('Y-m-d H:i:s', time() + (int)($resp['body']['expires_in'] ?? 3600)));
        return $accessToken;
    }

    // ── Destination folder ───────────────────────────────────────

    /** Finds (or creates) the "Kohevo Backups" folder, caching its id in settings. */
    public static function ensureFolder(string $token, string $name = 'Kohevo Backups'): ?string {
        $cached = (string)(Database::setting('backups.google_folder_id') ?? '');
        if ($cached !== '') return $cached;

        $q = "mimeType='application/vnd.google-apps.folder' and name='" . str_replace("'", "\\'", $name) . "' and trashed=false";
        $found = self::curl(self::API_BASE . '/files?' . http_build_query(['q' => $q, 'fields' => 'files(id)']), 'GET', null, ['Authorization: Bearer ' . $token]);
        if ($found['ok'] && !empty($found['body']['files'][0]['id'])) {
            $id = (string)$found['body']['files'][0]['id'];
            Database::setSetting('backups.google_folder_id', $id);
            return $id;
        }

        $created = self::curl(self::API_BASE . '/files', 'POST', json_encode([
            'name'     => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
        ]), ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        if ($created['ok'] && !empty($created['body']['id'])) {
            $id = (string)$created['body']['id'];
            Database::setSetting('backups.google_folder_id', $id);
            return $id;
        }
        slate_log('GoogleDriveClient: could not find/create backups folder: ' . json_encode($found['body'] ?? $created['body'] ?? null), 'error');
        return null;
    }

    // ── Resumable upload ──────────────────────────────────────────

    /** Opens a resumable-upload session. Returns the session URI, or null on failure. */
    public static function startResumableUpload(string $token, string $folderId, string $filename, int $totalBytes): ?string {
        $resp = self::curl(self::UPLOAD_BASE . '?uploadType=resumable', 'POST', json_encode([
            'name'    => $filename,
            'parents' => [$folderId],
        ]), [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Type: application/zip',
            'X-Upload-Content-Length: ' . $totalBytes,
        ], true);
        if (!$resp['ok'] || empty($resp['location'])) {
            slate_log('GoogleDriveClient: could not start resumable upload: ' . json_encode($resp['body'] ?? $resp['error']), 'error');
            return null;
        }
        return $resp['location'];
    }

    /**
     * PUTs one chunk (bytes $offset..$offset+$length-1 of $totalBytes read from $filePath).
     * @return array{done:bool, next_offset:int, file_id:?string, error:?string}
     */
    public static function uploadChunk(string $uploadUrl, string $filePath, int $offset, int $length, int $totalBytes): array {
        $fh = fopen($filePath, 'rb');
        if ($fh === false) return ['done' => false, 'next_offset' => $offset, 'file_id' => null, 'error' => 'Could not open local file for reading.'];
        fseek($fh, $offset);
        $chunk = fread($fh, $length);
        fclose($fh);
        if ($chunk === false) return ['done' => false, 'next_offset' => $offset, 'file_id' => null, 'error' => 'Could not read chunk from local file.'];

        $end = $offset + strlen($chunk) - 1;
        $resp = self::curl($uploadUrl, 'PUT', $chunk, [
            'Content-Length: ' . strlen($chunk),
            'Content-Range: bytes ' . $offset . '-' . $end . '/' . $totalBytes,
        ]);

        if ($resp['status'] === 200 || $resp['status'] === 201) {
            return ['done' => true, 'next_offset' => $totalBytes, 'file_id' => (string)($resp['body']['id'] ?? ''), 'error' => null];
        }
        if ($resp['status'] === 308) {
            // Incomplete — Google confirms how much it actually has via the Range header.
            $range = $resp['range'] ?? '';
            $confirmedEnd = 0;
            if (preg_match('/bytes=0-(\d+)/', (string)$range, $m)) $confirmedEnd = (int)$m[1];
            return ['done' => false, 'next_offset' => $confirmedEnd > 0 ? $confirmedEnd + 1 : $end + 1, 'file_id' => null, 'error' => null];
        }
        return ['done' => false, 'next_offset' => $offset, 'file_id' => null, 'error' => 'Upload chunk failed (HTTP ' . $resp['status'] . '): ' . json_encode($resp['body'] ?? $resp['error'])];
    }

    // ── Retention ─────────────────────────────────────────────────

    /** @return array<int, array{id:string,name:string,createdTime:string}> oldest-first */
    public static function listFilesInFolder(string $token, string $folderId): array {
        $q = "'" . $folderId . "' in parents and trashed=false";
        $resp = self::curl(self::API_BASE . '/files?' . http_build_query([
            'q'        => $q,
            'fields'   => 'files(id,name,createdTime)',
            'orderBy'  => 'createdTime',
            'pageSize' => 200,
        ]), 'GET', null, ['Authorization: Bearer ' . $token]);
        if (!$resp['ok']) return [];
        return $resp['body']['files'] ?? [];
    }

    public static function deleteFile(string $token, string $fileId): bool {
        $resp = self::curl(self::API_BASE . '/files/' . rawurlencode($fileId), 'DELETE', null, ['Authorization: Bearer ' . $token]);
        return $resp['ok'] || $resp['status'] === 404; // already gone counts as success
    }

    // ── HTTP helpers (same cURL pattern as GoogleCalendarSync::curl()) ───

    private static function httpPost(string $url, array $fields): array {
        return self::curl($url, 'POST', http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
    }

    /** @return array{ok:bool, status:int, body:mixed, location?:string, range?:string, error:?string} */
    private static function curl(string $url, string $method, ?string $body, array $headers, bool $captureLocation = false): array {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'body' => null, 'error' => 'curl extension unavailable'];
        }
        $responseHeaders = [];
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                return strlen($line);
            },
        ];
        if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
        curl_setopt_array($ch, $opts);
        $raw   = curl_exec($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch) ?: null;
        curl_close($ch);

        $decoded = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) $decoded = $raw;
        }
        $result = ['ok' => $error === null && $code >= 200 && $code < 300, 'status' => $code, 'body' => $decoded, 'error' => $error];
        if ($captureLocation) $result['location'] = $responseHeaders['location'] ?? null;
        $result['range'] = $responseHeaders['range'] ?? null;
        return $result;
    }
}
