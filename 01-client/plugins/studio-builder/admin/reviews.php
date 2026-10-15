<?php
/**
 * Kohevo Studio — Reviews & Revisions Audit.
 *
 * Dedicated admin page for reviewing AI drafts, inspection diffs, revision history,
 * and safe rollbacks to immutable historical snapshots.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_reviews', 'Reviews');
$currentNav = 'studio-reviews';

$flash = null;

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage revisions.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'rollback_revision') {
            $revId = (int) ($_POST['revision_id'] ?? 0);
            $pageId = (int) ($_POST['page_id'] ?? 0);

            if ($revId > 0 && $pageId > 0) {
                try {
                    $targetRev = Database::row("SELECT * FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ? AND page_id = ? LIMIT 1", [$tenantId, $revId, $pageId]);

                    if (!$targetRev) {
                        $flash = ['type' => 'error', 'msg' => 'Revision not found or belongs to another tenant.'];
                    } else {
                        // Find current max revision number for this page
                        $maxRev = (int) Database::value("SELECT MAX(revision_number) FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id = ?", [$tenantId, $pageId]);
                        $nextRevNum = $maxRev + 1;

                        // Insert new rollback revision
                        Database::insert('studiobuilder_revisions', [
                            'tenant_id' => $tenantId,
                            'page_id' => $pageId,
                            'revision_number' => $nextRevNum,
                            'revision_kind' => 'rollback',
                            'schema_version' => (int) ($targetRev['schema_version'] ?? 1),
                            'document_json' => $targetRev['document_json'],
                            'summary' => "Rollback to revision #{$targetRev['revision_number']} (from ID #{$revId})",
                            'parent_revision_id' => $revId,
                            'created_by' => (int) (Auth::id() ?? 1),
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);

                        // Update page updated_at
                        Database::query("UPDATE studiobuilder_pages SET updated_at = NOW(), updated_by = ? WHERE tenant_id = ? AND id = ?", [(int) (Auth::id() ?? 1), $tenantId, $pageId]);

                        $flash = ['type' => 'success', 'msg' => "Page rolled back to snapshot #{$targetRev['revision_number']} (New revision #{$nextRevNum} created)."];
                    }
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Rollback failed: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Filter
$kindFilter = trim((string) ($_GET['kind'] ?? 'all'));

$sql = "SELECT r.*, p.title as page_title, p.slug as page_slug
        FROM studiobuilder_revisions r
        INNER JOIN studiobuilder_pages p ON r.page_id = p.id
        WHERE r.tenant_id = ?";
$params = [$tenantId];

if ($kindFilter !== 'all' && in_array($kindFilter, ['ai', 'manual', 'autosave', 'rollback', 'publish'], true)) {
    $sql .= " AND r.revision_kind = ?";
    $params[] = $kindFilter;
}

$sql .= " ORDER BY r.id DESC LIMIT 50";

try {
    $revisions = Database::all($sql, $params);
} catch (\Throwable $e) {
    $revisions = [];
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('reviews', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" >
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Title & Action Filter -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Revision History & AI Audit Log</h2>
            <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Audit structural changes, review AI drafts before publishing, or perform one-click rollbacks.</p>
        </div>

        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= e(plugin_url('studio-builder', 'admin/reviews.php')) ?>" class="sb-pill-item <?= $kindFilter === 'all' ? 'active' : '' ?>">
                All Revisions (<?= count($revisions) ?>)
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/reviews.php?kind=ai')) ?>" class="sb-pill-item <?= $kindFilter === 'ai' ? 'active' : '' ?>">
                <?= sb_svg('sparkles', 14) ?> AI Drafts
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/reviews.php?kind=manual')) ?>" class="sb-pill-item <?= $kindFilter === 'manual' ? 'active' : '' ?>">
                <?= sb_svg('edit', 14) ?> Manual Saves
            </a>
            <a href="<?= e(plugin_url('studio-builder', 'admin/reviews.php?kind=rollback')) ?>" class="sb-pill-item <?= $kindFilter === 'rollback' ? 'active' : '' ?>">
                <?= sb_svg('refresh', 14) ?> Rollbacks
            </a>
        </div>
    </div>

    <!-- Revisions list (shared Kohevo expandable data rows) -->
    <?php if (empty($revisions)): ?>
        <div class="card" style="padding: 36px; text-align: center; color: var(--sb-muted); margin-bottom: 32px;">
            No revisions recorded yet. Save edits in the Canvas Builder to start recording immutable snapshots.
        </div>
    <?php else: ?>
        <div class="data-list is-columnar" data-single-open style="margin-bottom: 32px;">
            <?php
            slate_data_list_head(['main' => 'Page', 'value' => 'Rev #', 'badge' => 'Kind']);
            foreach ($revisions as $rev) {
                $rId = (int) $rev['id'];
                $pId = (int) $rev['page_id'];
                $pTitle = (string) ($rev['page_title'] ?? "Page #{$pId}");
                $pSlug = (string) ($rev['page_slug'] ?? '');
                $revNum = (int) ($rev['revision_number'] ?? 1);
                $kind = (string) ($rev['revision_kind'] ?? 'manual');
                $summary = (string) ($rev['summary'] ?? 'Document update');
                $createdAt = (string) ($rev['created_at'] ?? '');
                $kindBadge = match ($kind) {
                    'ai' => 'info',
                    'publish' => 'active',
                    'rollback' => 'warning',
                    default => 'muted',
                };

                $actions = '<a href="' . e(plugin_url('studio-builder', 'admin/builder.php?page=' . $pId)) . '" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">'
                    . sb_svg('eye', 12) . ' Open in Builder</a>';
                if ($canEdit) {
                    $actions .= ' <form method="POST" action="' . e(plugin_url('studio-builder', 'admin/reviews.php')) . '" onsubmit="return confirm(\'Restore page to snapshot #' . $revNum . '?\');" style="margin: 0; display: inline;">'
                        . csrf_field()
                        . '<input type="hidden" name="_action" value="rollback_revision">'
                        . '<input type="hidden" name="revision_id" value="' . $rId . '">'
                        . '<input type="hidden" name="page_id" value="' . $pId . '">'
                        . '<button type="submit" class="btn btn-sm btn-primary" title="Rollback to this snapshot">' . sb_svg('refresh', 12) . ' Restore</button>'
                        . '</form>';
                }

                slate_data_row([
                    'avatar'       => $pTitle,
                    'avatar_color' => $kindBadge === 'muted' ? 'muted' : ($kindBadge === 'active' ? 'success' : $kindBadge),
                    'title'        => $pTitle,
                    'meta'         => '/' . $pSlug,
                    'value'        => '#' . $revNum,
                    'badge'        => [strtoupper($kind), $kindBadge],
                    'detail'       => [
                        'summary' => ['label' => 'Summary & Notes', 'value' => $summary],
                        'when'    => ['label' => 'Timestamp', 'value' => $createdAt !== '' ? date('M j, Y H:i', strtotime($createdAt)) : '—'],
                    ],
                    'actions'      => $actions,
                ]);
            }
            ?>
        </div>
        <?php slate_data_list_script(); ?>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
