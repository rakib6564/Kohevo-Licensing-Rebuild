<?php
/**
 * Mark all of the current tenant's notifications as read.
 * POST-only, CSRF-protected. Returns JSON {ok:true}.
 */
require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Bad request.']);
    exit;
}

Notifications::markAllRead();
echo json_encode(['ok' => true]);
