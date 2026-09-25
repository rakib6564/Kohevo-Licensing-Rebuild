<?php
/**
 * Media library — JSON API for the picker modal.
 *
 * GET ?action=list             → all media as JSON
 * (no POST endpoints here — the picker is read-only; upload + delete
 * happen on the main admin page)
 *
 * Auth: same as the main admin page (media.view). The picker is
 * intended for use from other admin pages (product editor, variant
 * editor), so anyone authorised to be in admin can see it. CSRF is
 * not required for the GET — it's a read-only endpoint and serves
 * the same data the main page already exposes to anyone with
 * media.view.
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('media.view');

require_once dirname(__DIR__) . '/MediaLibrary.php';

header('Content-Type: application/json; charset=UTF-8');

$action = (string)($_GET['action'] ?? 'list');

if ($action === 'list') {
    // Served by the core Media service now. Honour the legacy ?filter=
    // (in_use|unused) plus an optional ?type= (image|document) so the
    // picker can scope to a media kind.
    $filter = (string)($_GET['filter'] ?? 'all');
    $type   = (string)($_GET['type'] ?? 'all');
    $usage  = in_array($filter, ['in_use', 'unused'], true) ? $filter : 'all';
    if (!in_array($type, ['image', 'document'], true)) $type = 'all';

    // The picker filters client-side and the dataset is small, so pull a
    // large single page.
    $items = Media::listAll([
        'type'     => $type,
        'usage'    => $usage,
        'per_page' => 100000,
    ])['items'];

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
