<?php
/**
 * Kohevo Studio — the builder's image picker endpoint (JSON).
 *
 *   GET  /plugins/studio-builder/admin/media-api.php?q=<search>&page=<n>      images of this site
 *   GET  /plugins/studio-builder/admin/media-api.php?ids=3,7                  those images (previews)
 *   POST /plugins/studio-builder/admin/media-api.php        multipart `file`   upload one image
 *
 * A thin adapter over the core Media service, so the picker needs no other plugin. Same gates as the
 * authoring API: signed in, entitled to Studio, `studio-builder.edit`, the session CSRF token on a
 * POST, the per-session rate limit. Images only, 10 MB, SVG sanitised by Media::upload().
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Http\StudioApiRateLimiter;
use Slate\Module\StudioBuilder\Http\StudioMediaApi;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Media\Media;

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
$fail = static fn(int $status, string $code, string $message): never => $respond($status, ['ok' => false, 'error' => ['code' => $code, 'message' => $message]]);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET', 'POST'], true)) {
    $fail(405, 'method_not_allowed', 'This request method is not allowed here.');
}
if (!Auth::check()) {
    $fail(401, 'authentication_error', 'Your session has ended. Please sign in again.');
}
$actor = StudioActor::fromCurrentSession();
if (!EntitlementService::canAccess(current_tenant_id(), 'studio-builder')) {
    $fail(403, 'entitlement_error', 'Kohevo Studio is not available on this site.');
}
if (!$actor->can(StudioPermissions::EDIT)) {
    $fail(403, 'authorization_error', 'You do not have permission to do this.');
}
if ($method === 'POST' && !csrf_verify()) {
    $fail(403, 'csrf_error', 'The security check failed. Reload the builder and try again.');
}
$limiter = StudioApiRateLimiter::system();
if (!isset($_SESSION['studio_api_rate'])) {
    $_SESSION['studio_api_rate'] = null;
}
if (!$limiter->hit($_SESSION['studio_api_rate'], $method, 'media')) {
    $fail(429, 'rate_limited', 'Too many requests. Please wait a moment.');
}
if (!class_exists(Media::class)) {
    $fail(503, 'server_error', 'The server could not complete this request.');
}
Media::ensureSchema();

try {
    if ($method === 'GET') {
        $q = StudioMediaApi::listQuery($_GET);
        if ($q['ids'] !== []) {
            $items = [];
            foreach ($q['ids'] as $id) {
                $row = Media::get($id);
                $item = is_array($row) ? StudioMediaApi::shapeItem($row) : null;
                if ($item !== null) {
                    $items[] = $item;
                }
            }
            $respond(200, ['ok' => true, 'data' => ['items' => $items, 'total' => count($items), 'page' => 1, 'pages' => 1]]);
        }
        $result = Media::listAll(['type' => 'image', 'search' => $q['search'], 'page' => $q['page'], 'per_page' => StudioMediaApi::PER_PAGE]);
        $respond(200, ['ok' => true, 'data' => StudioMediaApi::shapeList($result)]);
    }

    if (empty($_FILES['file']) || !is_array($_FILES['file']) || is_array($_FILES['file']['name'] ?? null)) {
        $fail(400, 'validation_error', 'Choose one image to upload.');
    }
    $res = Media::upload('file', [
        'allowed_mimes' => Media::imageMimes(),
        'allowed_exts'  => Media::IMAGE_EXTS,
        'max_bytes'     => StudioMediaApi::MAX_UPLOAD_BYTES,
    ]);
    $item = is_array($res) && !empty($res['id']) ? StudioMediaApi::shapeItem($res) : null;
    if ($item === null) {
        $fail(422, 'validation_error', is_array($res) && !empty($res['error']) && is_string($res['error']) ? $res['error'] : 'That file could not be uploaded as an image.');
    }
    $respond(201, ['ok' => true, 'data' => ['item' => $item]]);
} catch (\Throwable $e) {
    if (function_exists('slate_log')) {
        slate_log('studio media-api: ' . $e::class, 'error');
    }
    $fail(500, 'server_error', 'The server could not complete this request.');
}
