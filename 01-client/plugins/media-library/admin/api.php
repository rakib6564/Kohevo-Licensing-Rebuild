<?php
/**
 * Media library — JSON API for the picker modal.
 *
 * Supported Actions:
 *   GET  ?action=list             → all media as JSON
 *   POST ?action=upload           → upload file into library and return decorated record
 *   POST ?action=update_meta      → update alt_text, title, description on a media item
 */

$root = realpath(__DIR__ . '/../../..');
require $root . '/config.php';

Auth::require();
Auth::requirePerm('media.view');

require_once dirname(__DIR__) . '/MediaLibrary.php';

header('Content-Type: application/json; charset=UTF-8');

$action = (string)($_GET['action'] ?? ($_POST['action'] ?? 'list'));

// 1. List Media
if ($action === 'list') {
    $filter = (string)($_GET['filter'] ?? 'all');
    $type   = (string)($_GET['type'] ?? 'all');
    $usage  = in_array($filter, ['in_use', 'unused'], true) ? $filter : 'all';
    if (!in_array($type, ['image', 'document'], true)) $type = 'all';

    $items = Media::listAll([
        'type'     => $type,
        'usage'    => $usage,
        'per_page' => 100000,
    ])['items'];

    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. Upload from Picker
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $canUpload = Auth::can('media.upload') || Auth::isSuperAdmin();
    if (!$canUpload) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => __('no_permission', 'You do not have permission to upload.')]);
        exit;
    }

    $uploadField = null;
    if (!empty($_FILES['file']['name'])) {
        $uploadField = 'file';
    } elseif (!empty($_FILES['files']['name'])) {
        if (is_array($_FILES['files']['name'])) {
            $_FILES['__one_file'] = [
                'name'     => $_FILES['files']['name'][0],
                'type'     => $_FILES['files']['type'][0],
                'tmp_name' => $_FILES['files']['tmp_name'][0],
                'error'    => $_FILES['files']['error'][0],
                'size'     => $_FILES['files']['size'][0],
            ];
            $uploadField = '__one_file';
        } else {
            $uploadField = 'files';
        }
    }

    if (!$uploadField) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => __('no_file_uploaded', 'No file was uploaded.')]);
        exit;
    }

    try {
        $res = Media::upload($uploadField);
        if (empty($res['id'])) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Upload failed']);
            exit;
        }

        // Apply any initial metadata if passed
        $meta = [];
        if (isset($_POST['alt_text']))   $meta['alt_text'] = (string)$_POST['alt_text'];
        if (isset($_POST['title']))      $meta['title'] = (string)$_POST['title'];
        if (isset($_POST['description'])) $meta['description'] = (string)$_POST['description'];

        if ($meta !== []) {
            $updated = Media::updateMeta((int)$res['id'], $meta);
            if (!empty($updated['ok']) && !empty($updated['item'])) {
                $res = $updated['item'];
            }
        }

        echo json_encode(['ok' => true, 'item' => $res], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// 3. Update Metadata (Alt text, Title, Description)
if ($action === 'update_meta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid media ID.']);
        exit;
    }

    $meta = [];
    if (isset($_POST['alt_text']))    $meta['alt_text'] = (string)$_POST['alt_text'];
    if (isset($_POST['title']))       $meta['title'] = (string)$_POST['title'];
    if (isset($_POST['description'])) $meta['description'] = (string)$_POST['description'];

    $res = Media::updateMeta($id, $meta);
    if (!empty($res['ok'])) {
        echo json_encode(['ok' => true, 'item' => $res['item']], JSON_UNESCAPED_SLASHES);
    } else {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $res['error'] ?? 'Update failed.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Unknown action.']);
