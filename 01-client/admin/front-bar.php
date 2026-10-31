<?php
/**
 * Slate — the admin bar for a public page, as JSON.
 *
 * Fetched by assets/js/front-admin-bar.js when the hint cookie says it is worth
 * asking. The cookie decides nothing here: the answer comes from the real
 * session, and every item is permission-checked in FrontAdminBar. The response
 * holds the admin's name and a logout token, so it is never stored.
 */
require_once dirname(__DIR__) . '/config.php';

use Slate\Services\Admin\FrontAdminBar;
use Slate\Services\Auth\AdminHint;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
header('Vary: Cookie');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

// Only this site's own pages ask. Older browsers send no such header; a cross-site reader could not read this anyway.
if (!in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? 'same-origin', ['same-origin', 'none'], true)) {
    http_response_code(403);
    exit;
}

if (!Auth::check()) {
    AdminHint::clear();
    http_response_code(401);
    echo '{"html":""}';
    exit;
}

$pathname = is_string($_GET['path'] ?? null) ? $_GET['path'] : '';
$html = FrontAdminBar::forCurrentUser(FrontAdminBar::localPath($pathname, (string) SLATE_URL));
session_write_close();

echo json_encode(['html' => $html], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
