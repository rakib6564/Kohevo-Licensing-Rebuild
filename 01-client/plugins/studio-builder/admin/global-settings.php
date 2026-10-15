<?php
/**
 * Kohevo Studio — moved.
 *
 * Layout widths and the other global design settings now live in the visual builder under Settings > Site, which is the only place the page renderer reads
 * them from. This address stays so old links and bookmarks still land somewhere useful: it opens the builder on the
 * homepage (or the first page).
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';

Auth::require();

$target = null;
try {
    $row = Database::row(
        "SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND page_type IN ('page','landing') AND status <> 'archived' ORDER BY (route_mode = 'homepage') DESC, id ASC LIMIT 1",
        [current_tenant_id()]
    );
    if (is_array($row) && !empty($row['id'])) {
        $target = plugin_url('studio-builder', 'admin/builder.php') . '?page=' . (int) $row['id'];
    }
} catch (\Throwable $ignored) {
    // fall through to the pages list
}

header('Location: ' . ($target ?? plugin_url('studio-builder', 'admin/pages.php')), true, 302);
exit;
