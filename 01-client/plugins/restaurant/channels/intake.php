<?php
/**
 * Public route POST /order-channel/{slug} — canonical order intake.
 *
 * Authenticated by an HMAC of the raw body in a header (X-Channel-Signature
 * for canonical channels; the adapter's own header, such as Deliverect's,
 * otherwise), never by a session. Verify first, then act:
 * RestaurantChannels::intake() refuses an unverifiable delivery before
 * anything is read or written. The shape and the answers are documented in
 * includes/channels.php.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
ModuleGuard::requirePublic('restaurant');
require_once dirname(__DIR__) . '/includes/channels.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['ok' => false, 'error' => 'POST only.']); exit; }

$slug = strtolower(trim((string)($_GET['_route_path'] ?? ''), '/'));
if (!RestaurantChannels::validSlug($slug)) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Unknown channel.']); exit; }

$raw = (string) file_get_contents('php://input', false, null, 0, 1048576);   // 1 MB is far beyond any real order
try {
    $headers = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, 'HTTP_')) $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string)$v;
    }
    [$code, $body] = RestaurantChannels::intake($slug, $raw, $headers);
} catch (\Throwable $e) {
    slate_log('Restaurant channel intake failed: ' . $e->getMessage(), 'error');
    [$code, $body] = [500, ['ok' => false, 'error' => 'The order could not be processed. Send it again.']];
}
http_response_code($code);
echo json_encode($body, JSON_UNESCAPED_SLASHES);
