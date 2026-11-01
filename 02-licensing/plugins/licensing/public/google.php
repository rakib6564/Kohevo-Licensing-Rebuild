<?php
/**
 * Licensing plugin — the Google broker's public route (/licensing/google/start|callback|claim|refresh).
 *
 * A thin shim: GoogleBroker holds the logic and LicensingAPI::verifiedInstallation() the licence check. The client lives in
 * Central's environment (GOOGLE_OAUTH_CLIENT_ID, GOOGLE_OAUTH_CLIENT_SECRET), never in the database or a site.
 */

if (!defined('SLATE_ROOT')) {
    require_once dirname(__DIR__, 3) . '/config.php';
}
slate_public_entry('licensing');

require_once dirname(__DIR__) . '/GoogleBroker.php';

/** Google's address, or a loopback stand-in in a sandbox (STUDIO_GOOGLE_FAKE_URL). */
$googleUrl = static function (string $url): string {
    $fake = (string) env('STUDIO_GOOGLE_FAKE_URL', '');
    return $fake !== '' && in_array(parse_url($fake, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? rtrim($fake, '/') . '/' . substr($url, strlen('https://')) : $url;
};
$json = static function (array $result): never {
    http_response_code((int) $result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $now = time();
    $broker = new GoogleBroker(
        (string) env('GOOGLE_OAUTH_CLIENT_ID', ''),
        (string) env('GOOGLE_OAUTH_CLIENT_SECRET', ''),
        rtrim(SLATE_URL, '/') . '/licensing/google/callback',
        'slate_sign',
        static function (string $url, array $fields): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($fields), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($ch);
            return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => is_string($body) ? $body : ''];
        },
        static function (string $hash, string $installationId, array $tokens, int $expires): void {
            Database::query('DELETE FROM licensing_google_handoffs WHERE expires_at < ?', [time()]);
            Database::insert('licensing_google_handoffs', ['handoff_hash' => $hash, 'installation_id' => $installationId, 'tokens_sealed' => slate_encrypt_secret((string) json_encode($tokens)), 'expires_at' => $expires]);
        },
        static function (string $hash, string $installationId, int $at): ?array {
            $row = Database::row('SELECT id, tokens_sealed FROM licensing_google_handoffs WHERE handoff_hash = ? AND installation_id = ? AND expires_at >= ?', [$hash, $installationId, $at]);
            // Whoever deletes the row owns it: two claims at once cannot both win.
            if ($row === null || Database::delete('licensing_google_handoffs', 'id = ?', [(int) $row['id']]) !== 1) {
                return null;
            }
            $tokens = json_decode((string) slate_decrypt_secret((string) $row['tokens_sealed']), true);
            return is_array($tokens) ? $tokens : null;
        },
        $googleUrl('https://accounts.google.com/o/oauth2/v2/auth'),
        $googleUrl('https://oauth2.googleapis.com/token'),
    );

    $action = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));

    if ($action === 'callback' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $result = $broker->callback($_GET, $now);
        if (isset($result['redirect'])) {
            header('Referrer-Policy: no-referrer');
            header('Location: ' . $result['redirect'], true, 303);
            exit;
        }
        http_response_code((int) $result['status']);
        header('Content-Type: text/plain; charset=utf-8');
        echo $result['message'];
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !in_array($action, ['start', 'claim', 'refresh'], true)) {
        $json(['status' => 404, 'body' => ['error' => 'not_found']]);
    }
    $input = json_decode((string) file_get_contents('php://input'), true);
    $site = is_array($input) ? LicensingAPI::verifiedInstallation($input) : null;
    if ($site === null) {
        $json(['status' => 403, 'body' => ['error' => 'invalid_request']]);
    }
    $json(match ($action) {
        'start' => $broker->start($site, (string) ($input['return_url'] ?? ''), (string) ($input['site_state'] ?? ''), $now),
        'claim' => $broker->claim($site, (string) ($input['handoff'] ?? ''), $now),
        'refresh' => $broker->refresh($site, (string) ($input['refresh_token'] ?? '')),
    });
} catch (\Throwable $e) {
    // The class only: a Google or database message could quote a request.
    slate_log('Licensing Google broker failed: ' . $e::class, 'error');
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'server_error']);
}
