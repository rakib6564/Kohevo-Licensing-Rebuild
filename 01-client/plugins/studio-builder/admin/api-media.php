<?php
/**
 * Kohevo Studio — Media Picker JSON API Endpoint.
 *
 * Provides media listings, search, and instant uploads for the Studio Media Picker.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

use Slate\Data\Database;
use Slate\Services\Media\Media;

Auth::require();
Auth::requirePerm('studio-builder.view');

header('Content-Type: application/json; charset=UTF-8');

$tenantId = current_tenant_id();
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list');

if ($action === 'list') {
    if (class_exists('Media')) {
        Media::ensureSchema();
    }

    $q = trim((string) ($_GET['q'] ?? ''));
    $kind = trim((string) ($_GET['kind'] ?? 'image'));

    $sql = "SELECT * FROM media_files WHERE tenant_id = ?";
    $params = [$tenantId];

    if ($kind !== 'all' && $kind !== '') {
        $sql .= " AND kind = ?";
        $params[] = $kind;
    }

    if ($q !== '') {
        $sql .= " AND (original_name LIKE ? OR path LIKE ?)";
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $sql .= " ORDER BY uploaded_at DESC, id DESC LIMIT 200";

    $rows = [];
    try {
        $rows = Database::rows($sql, $params);
    } catch (\Throwable $e) {
        $rows = [];
    }

    $items = [];
    foreach ($rows as $row) {
        $path = (string) ($row['path'] ?? '');
        $url = class_exists('Media') ? Media::url($path) : '/' . ltrim($path, '/');
        $sizeBytes = (int) ($row['size_bytes'] ?? 0);
        $sizeFmt = $sizeBytes > 1048576 
            ? number_format($sizeBytes / 1048576, 1) . ' MB' 
            : number_format(max(1, $sizeBytes / 1024), 0) . ' KB';

        $items[] = [
            'id'            => (int) ($row['id'] ?? 0),
            'name'          => (string) ($row['original_name'] ?? basename($path)),
            'path'          => $path,
            'url'           => $url,
            'kind'          => (string) ($row['kind'] ?? 'image'),
            'mime'          => (string) ($row['mime'] ?? 'image/jpeg'),
            'width'         => isset($row['width']) ? (int) $row['width'] : null,
            'height'        => isset($row['height']) ? (int) $row['height'] : null,
            'size_bytes'    => $sizeBytes,
            'size_formatted'=> $sizeFmt,
            'uploaded_at'   => (string) ($row['uploaded_at'] ?? ''),
        ];
    }

    // Default Curated Stock / Showcase Assets for instant preview if tenant has few assets
    $curatedPresets = [
        [
            'id'            => -1,
            'name'          => 'solaya-analytics-platform.jpg',
            'path'          => 'presets/solaya-analytics.jpg',
            'url'           => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 1200,
            'height'        => 800,
            'size_bytes'    => 284000,
            'size_formatted'=> '284 KB',
            'is_preset'     => true,
            'category'      => 'Fintech / SaaS Analytics',
        ],
        [
            'id'            => -2,
            'name'          => 'apex-design-system.jpg',
            'path'          => 'presets/apex-design-system.jpg',
            'url'           => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=1200&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 1200,
            'height'        => 800,
            'size_bytes'    => 312000,
            'size_formatted'=> '312 KB',
            'is_preset'     => true,
            'category'      => 'Multi-Brand UI System',
        ],
        [
            'id'            => -3,
            'name'          => 'nexus-generative-workspace.jpg',
            'path'          => 'presets/nexus-generative.jpg',
            'url'           => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1200&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 1200,
            'height'        => 800,
            'size_bytes'    => 420000,
            'size_formatted'=> '420 KB',
            'is_preset'     => true,
            'category'      => 'Generative UI & Collaboration',
        ],
        [
            'id'            => -4,
            'name'          => 'chrono-practice-portal.jpg',
            'path'          => 'presets/chrono-practice.jpg',
            'url'           => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 1200,
            'height'        => 800,
            'size_bytes'    => 345000,
            'size_formatted'=> '345 KB',
            'is_preset'     => true,
            'category'      => 'SaaS Productivity',
        ],
        [
            'id'            => -5,
            'name'          => 'avatar-marcus-vance.jpg',
            'path'          => 'presets/avatar-marcus.jpg',
            'url'           => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=400&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 400,
            'height'        => 400,
            'size_bytes'    => 82000,
            'size_formatted'=> '82 KB',
            'is_preset'     => true,
            'category'      => 'Client Avatar',
        ],
        [
            'id'            => -6,
            'name'          => 'avatar-sarah-lin.jpg',
            'path'          => 'presets/avatar-sarah.jpg',
            'url'           => 'https://images.unsplash.com/photo-1580489944761-15a19d654956?auto=format&fit=crop&w=400&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 400,
            'height'        => 400,
            'size_bytes'    => 78000,
            'size_formatted'=> '78 KB',
            'is_preset'     => true,
            'category'      => 'Client Avatar',
        ],
        [
            'id'            => -7,
            'name'          => 'avatar-david-miller.jpg',
            'path'          => 'presets/avatar-david.jpg',
            'url'           => 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?auto=format&fit=crop&w=400&q=80',
            'kind'          => 'image',
            'mime'          => 'image/jpeg',
            'width'         => 400,
            'height'        => 400,
            'size_bytes'    => 85000,
            'size_formatted'=> '85 KB',
            'is_preset'     => true,
            'category'      => 'Client Avatar',
        ],
    ];

    if ($q !== '') {
        $curatedPresets = array_values(array_filter($curatedPresets, function ($p) use ($q) {
            return stripos($p['name'], $q) !== false || stripos($p['category'], $q) !== false;
        }));
    }

    echo json_encode([
        'ok'       => true,
        'items'    => $items,
        'presets'  => $curatedPresets,
        'total'    => count($items),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'upload') {
    if (!csrf_verify()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Security check failed.']);
        exit;
    }

    if (!Auth::can('studio-builder.edit') && !Auth::isSuperAdmin()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied.']);
        exit;
    }

    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'No file was uploaded.']);
        exit;
    }

    try {
        if (!class_exists('Media')) {
            throw new \RuntimeException('Core Media service is unavailable.');
        }

        Media::ensureSchema();
        $res = Media::upload('file');

        if (empty($res['id']) && empty($res['path'])) {
            throw new \RuntimeException($res['error'] ?? 'Upload failed.');
        }

        $path = (string) ($res['path'] ?? '');
        $url = Media::url($path);
        $sizeBytes = (int) ($res['size_bytes'] ?? 0);
        $sizeFmt = $sizeBytes > 1048576 
            ? number_format($sizeBytes / 1048576, 1) . ' MB' 
            : number_format(max(1, $sizeBytes / 1024), 0) . ' KB';

        $item = [
            'id'            => (int) ($res['id'] ?? 0),
            'name'          => (string) ($res['original_name'] ?? basename($path)),
            'path'          => $path,
            'url'           => $url,
            'kind'          => (string) ($res['kind'] ?? 'image'),
            'mime'          => (string) ($res['mime'] ?? 'image/jpeg'),
            'width'         => isset($res['width']) ? (int) $res['width'] : null,
            'height'        => isset($res['height']) ? (int) $res['height'] : null,
            'size_bytes'    => $sizeBytes,
            'size_formatted'=> $sizeFmt,
        ];

        echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_SLASHES);
        exit;
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Invalid action.']);
