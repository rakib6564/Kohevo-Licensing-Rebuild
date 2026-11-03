<?php
/**
 * Public route GET /menu-feed — the menu as JSON for channels.
 *
 * Token-authenticated (Authorization: Bearer <token>, or ?token=); the token
 * is per install and rotated on the Channels screen. Answers 304 when the
 * client's If-None-Match already holds the current etag.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
ModuleGuard::requirePublic('restaurant');
require_once dirname(__DIR__) . '/includes/channels.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-cache');
$auth  = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string)($_GET['token'] ?? '');
if (!RestaurantChannels::feedAuthorised($token)) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Missing or wrong feed token.']); exit; }

$feed = RestaurantChannels::menuFeed();
$etag = '"' . $feed['etag'] . '"';
header('ETag: ' . $etag);
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
echo json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
