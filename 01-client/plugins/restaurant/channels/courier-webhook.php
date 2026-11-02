<?php
/**
 * Public route POST /courier-webhook/{provider}/{token} — courier status
 * webhooks (DoorDash Drive; Uber Direct next).
 *
 * The token in the URL is per install (made on the Couriers page); a wrong
 * one is refused before anything is read. Drive webhooks carry no
 * signature, so the body is only a hint naming the delivery: the job is
 * re-read from the provider's API and only that answer is applied
 * (RestaurantDelivery::webhook). Unknown deliveries are acknowledged.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
ModuleGuard::requirePublic('restaurant');
require_once dirname(__DIR__) . '/includes/delivery.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['ok' => false]); exit; }

$parts = array_values(array_filter(explode('/', trim((string)($_GET['_route_path'] ?? ''), '/')), 'strlen'));
[$provider, $token] = [(string)($parts[0] ?? ''), (string)($parts[1] ?? '')];
$raw = (string) file_get_contents('php://input', false, null, 0, 262144);
try {
    [$code, $body] = RestaurantDelivery::webhook($provider, $token, $raw);
} catch (\Throwable $e) {
    slate_log('Restaurant courier webhook failed: ' . $e->getMessage(), 'error');
    [$code, $body] = [500, ['ok' => false]];
}
http_response_code($code);
echo json_encode($body);
