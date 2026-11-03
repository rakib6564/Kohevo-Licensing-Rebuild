<?php
/**
 * Slate — media library uploads in pieces (JSON). Used by the upload bar on admin/media.php so a file larger than
 * the server's upload limit (a long video, a podcast episode) still gets through. See ChunkedUpload.
 *
 *   POST op=start   name, size            → {ok, token, chunk_size}
 *   POST op=chunk   token, offset, file   → {ok, received, size}
 *   POST op=finish  token, folder_id      → {ok, item, duplicate}
 *   POST op=abort   token                 → {ok}
 *
 * Signed in, media.upload, the session CSRF token (X-CSRF-Token header or _csrf).
 */

declare(strict_types=1);

require __DIR__ . '/../config.php';

use Slate\Services\Media\ChunkedUpload;

$respond = static function (int $status, array $payload): never {
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
$fail = static fn(int $status, string $message): never => $respond($status, ['ok' => false, 'error' => $message]);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    $fail(405, 'POST only.');
}
if (!Auth::check()) {
    $fail(401, __('session_ended', 'Your session has ended. Please sign in again.'));
}
if (!(Auth::can('media.upload') || Auth::isSuperAdmin())) {
    $fail(403, __('no_permission', 'You do not have permission to upload.'));
}
if (!csrf_verify()) {
    $fail(403, __('csrf_failed', 'Security check failed.'));
}

Media::ensureSchema();
$userId = (int)(Auth::userId() ?? 0);
$token  = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';

try {
    switch ((string)($_POST['op'] ?? '')) {
        case 'start':
            $res = ChunkedUpload::start((string)($_POST['name'] ?? ''), (int)($_POST['size'] ?? 0), $userId);
            $respond(empty($res['ok']) ? 422 : 200, $res);

        case 'chunk':
            $f = $_FILES['file'] ?? null;
            if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) {
                $fail(400, 'The piece did not arrive. Please try again.');
            }
            $res = ChunkedUpload::append($token, $userId, (int)($_POST['offset'] ?? -1), (string)$f['tmp_name']);
            $respond(empty($res['ok']) ? 409 : 200, $res);

        case 'finish':
            $item = ChunkedUpload::finish($token, $userId, ['folder_id' => max(0, (int)($_POST['folder_id'] ?? 0))]);
            if (empty($item['id'])) {
                $fail(422, (string)($item['error'] ?? __('uploads_failed', 'No files were uploaded.')));
            }
            $respond(200, ['ok' => true, 'duplicate' => !empty($item['duplicate']), 'item' => ['id' => (int)$item['id'], 'url' => (string)($item['url'] ?? ''), 'original_name' => (string)($item['original_name'] ?? '')]]);

        case 'abort':
            ChunkedUpload::abort($token, $userId);
            $respond(200, ['ok' => true]);
    }
    $fail(400, 'Unknown operation.');
} catch (\Throwable $e) {
    if (function_exists('slate_log')) slate_log('media-upload: ' . $e::class . ': ' . $e->getMessage(), 'error');
    $fail(500, __('uploads_failed', 'No files were uploaded.'));
}
