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

$canDelete = Auth::can('studio-builder.admin') || Auth::isSuperAdmin();

// POST Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage revisions.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');
        $bulk = (string) ($_POST['bulk_action'] ?? '');
        $rowAction = (string) ($_POST['row_action'] ?? '');

        // A row's Restore button names its revision (`restore:<id>`); the page is read from the revision itself.
        if (str_starts_with($rowAction, 'restore:')) {
            $action = 'rollback_revision';
            $_POST['revision_id'] = (int) substr($rowAction, 8);
            $_POST['page_id'] = (int) Database::value('SELECT page_id FROM studiobuilder_revisions WHERE tenant_id = ? AND id = ?', [$tenantId, (int) $_POST['revision_id']]);
        }

        // Deleting history is permanent, so it is administrators only. A page's published revision and its current draft are
        // never deleted (the live page and the editor read them), whatever was ticked; they are counted as skipped.
        if ($bulk === 'delete_revisions' || str_starts_with($rowAction, 'delete:')) {
            if (!$canDelete) {
                $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage revisions.')];
            } else {
                $ids = $bulk === 'delete_revisions' ? array_map('intval', (array) ($_POST['ids'] ?? [])) : [(int) substr($rowAction, 7)];
                $ids = array_slice(array_values(array_unique(array_filter($ids, static fn(int $i): bool => $i > 0))), 0, 200);
                $deleted = 0;
                if ($ids !== []) {
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $inUse = [];
                    foreach (Database::all('SELECT active_draft_revision_id AS d, published_revision_id AS p FROM studiobuilder_pages WHERE tenant_id = ?', [$tenantId]) as $pg) {
                        $inUse[(int) $pg['d']] = true;
                        $inUse[(int) $pg['p']] = true;
                    }
                    $mine = array_map('intval', array_column(Database::all("SELECT id FROM studiobuilder_revisions WHERE tenant_id = ? AND id IN ($marks)", array_merge([$tenantId], $ids)), 'id'));
                    $deletable = array_values(array_filter($mine, static fn(int $i): bool => !isset($inUse[$i])));
                    if ($deletable !== []) {
                        $dm = implode(',', array_fill(0, count($deletable), '?'));
                        $args = array_merge([$tenantId], $deletable);
                        Database::query("DELETE FROM studiobuilder_compilations WHERE tenant_id = ? AND revision_id IN ($dm)", $args);
                        Database::query("DELETE FROM studiobuilder_dependencies WHERE tenant_id = ? AND revision_id IN ($dm)", $args);
                        Database::query("DELETE FROM studiobuilder_revisions WHERE tenant_id = ? AND id IN ($dm)", $args);
                        $deleted = count($deletable);
                        AuditLog::record('studio.revisions_deleted', (string) $tenantId, ['deleted' => $deleted, 'revision_ids' => array_slice($deletable, 0, 50)]);
                    }
                }
                $skipped = count($ids) - $deleted;
                header('Location: reviews.php?' . http_build_query([
                    'kind' => (string) ($_POST['kind'] ?? 'all'), 'per' => (int) ($_POST['per'] ?? 25), 'p' => (int) ($_POST['p'] ?? 1),
                    'deleted' => $deleted, 'skipped' => $skipped,
                ]), true, 303);
                exit;
            }
        }

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

                        // The restored copy is a new revision, and it becomes the page's current draft so the builder opens it.
                        // The live page is untouched until that draft is published.
                        $newId = Database::insert('studiobuilder_revisions', [
                            'tenant_id' => $tenantId,
                            'page_id' => $pageId,
                            'revision_number' => $nextRevNum,
                            'revision_kind' => 'rollback',
                            'schema_version' => (string) ($targetRev['schema_version'] ?? '1.0'),
                            'document_json' => $targetRev['document_json'],
                            'summary' => "Rollback to revision #{$targetRev['revision_number']} (from ID #{$revId})",
                            'parent_revision_id' => $revId,
                            'created_by' => (int) (Auth::userId() ?? 1),
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);

                        Database::query('UPDATE studiobuilder_pages SET active_draft_revision_id = ?, updated_at = NOW(), updated_by = ? WHERE tenant_id = ? AND id = ?', [$newId, (int) (Auth::userId() ?? 1), $tenantId, $pageId]);

                        $flash = ['type' => 'success', 'msg' => "Page restored to snapshot #{$targetRev['revision_number']} as a new draft (revision #{$nextRevNum}). Open it in the builder to review and publish."];
                    }
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => 'Rollback failed: ' . $e->getMessage()];
                }
            }
        }
    }
}

if (isset($_GET['deleted']) || isset($_GET['skipped'])) {
    $nDeleted = (int) ($_GET['deleted'] ?? 0);
    $nSkipped = (int) ($_GET['skipped'] ?? 0);
    $flash = $nSkipped > 0
        ? ['type' => $nDeleted > 0 ? 'success' : 'error', 'msg' => sprintf(__('studio_revisions_deleted_some', '%1$d revision(s) deleted, %2$d kept (a page\'s published revision and current draft are never deleted).'), $nDeleted, $nSkipped)]
        : ['type' => 'success', 'msg' => sprintf(__('studio_revisions_deleted', '%d revision(s) deleted.'), $nDeleted)];
}

// Filter. The database stores AI work as `ai_operation`; the screen calls it "ai".
$kindFilter = trim((string) ($_GET['kind'] ?? 'all'));
$kinds = ['ai' => 'ai_operation', 'manual' => 'manual', 'autosave' => 'autosave', 'rollback' => 'rollback', 'publish' => 'publish', 'import' => 'import'];
if ($kindFilter !== 'all' && !isset($kinds[$kindFilter])) {
    $kindFilter = 'all';
}

$perRequested = (int) ($_GET['per'] ?? 25);
$perPage = in_array($perRequested, [25, 50, 100], true) ? $perRequested : 25;
$where = ' WHERE r.tenant_id = ?';
$params = [$tenantId];
if ($kindFilter !== 'all') {
    $where .= ' AND r.revision_kind = ?';
    $params[] = $kinds[$kindFilter];
}

$total = 0;
$revisions = [];
try {
    $total = (int) Database::value('SELECT COUNT(*) FROM studiobuilder_revisions r INNER JOIN studiobuilder_pages p ON r.page_id = p.id' . $where, $params);
    $pageNo = min(max(1, (int) ($_GET['p'] ?? 1)), max(1, (int) ceil($total / $perPage)));
    $revisions = Database::all(
        'SELECT r.*, p.title AS page_title, p.slug AS page_slug FROM studiobuilder_revisions r INNER JOIN studiobuilder_pages p ON r.page_id = p.id'
        . $where . ' ORDER BY r.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage),
        $params
    );
} catch (\Throwable $e) {
    $revisions = [];
    $pageNo = 1;
}

// Revisions the live page and the editor are using: shown as "In use" and never deleted.
$inUseIds = [];
try {
    foreach (Database::all('SELECT active_draft_revision_id AS d, published_revision_id AS p FROM studiobuilder_pages WHERE tenant_id = ?', [$tenantId]) as $pg) {
        $inUseIds[(int) $pg['d']] = 'current draft';
        $inUseIds[(int) $pg['p']] = 'published';
    }
} catch (\Throwable $e) {
}

$self = plugin_url('studio-builder', 'admin/reviews.php');
$kindUrl = static fn(string $k): string => e($self . ($k === 'all' ? '' : '?kind=' . $k));

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('reviews', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> sb-alert">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div class="sb-screen-head">
        <div>
            <h2>Revision history &amp; AI audit log</h2>
            <p>Audit structural changes, review AI drafts before publishing, restore a page to an earlier snapshot, or clear out old history.</p>
        </div>
        <div class="sb-pill-filter" style="margin-bottom: 0;">
            <a href="<?= $kindUrl('all') ?>" class="sb-pill-item <?= $kindFilter === 'all' ? 'active' : '' ?>">All revisions<?= $kindFilter === 'all' ? ' (' . $total . ')' : '' ?></a>
            <a href="<?= $kindUrl('ai') ?>" class="sb-pill-item <?= $kindFilter === 'ai' ? 'active' : '' ?>"><?= sb_svg('sparkles', 14) ?> AI drafts<?= $kindFilter === 'ai' ? ' (' . $total . ')' : '' ?></a>
            <a href="<?= $kindUrl('manual') ?>" class="sb-pill-item <?= $kindFilter === 'manual' ? 'active' : '' ?>"><?= sb_svg('edit', 14) ?> Manual saves<?= $kindFilter === 'manual' ? ' (' . $total . ')' : '' ?></a>
            <a href="<?= $kindUrl('rollback') ?>" class="sb-pill-item <?= $kindFilter === 'rollback' ? 'active' : '' ?>"><?= sb_svg('refresh', 14) ?> Rollbacks<?= $kindFilter === 'rollback' ? ' (' . $total . ')' : '' ?></a>
        </div>
    </div>

    <?php if (empty($revisions)): ?>
        <div class="sb-card" style="text-align: center; color: var(--sb-muted);">
            No revisions recorded yet. Save edits in the Canvas Builder to start recording immutable snapshots.
        </div>
    <?php else: ?>
        <form method="POST" action="<?= e($self) ?>" id="studio-revision-form">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" value="<?= e($kindFilter) ?>">
            <input type="hidden" name="per" value="<?= (int) $perPage ?>">
            <input type="hidden" name="p" value="<?= (int) $pageNo ?>">
            <div class="data-list is-columnar" data-single-open id="studio-revision-list">
                <?php
                echo \Slate\Module\StudioBuilder\Admin\PageListView::selectBar(
                    $canDelete,
                    'delete_revisions',
                    'Delete',
                    'Delete %d selected revision(s) for good? A page\'s published revision and current draft are kept.'
                );
                slate_data_list_head(['main' => 'Page', 'value' => 'Rev #', 'badge' => 'Kind'], ['select_all' => 'Select all revisions on this page']);
                foreach ($revisions as $rev) {
                    $rId = (int) $rev['id'];
                    $pId = (int) $rev['page_id'];
                    $pTitle = (string) ($rev['page_title'] ?? "Page #{$pId}");
                    $pSlug = (string) ($rev['page_slug'] ?? '');
                    $revNum = (int) ($rev['revision_number'] ?? 1);
                    $kind = (string) ($rev['revision_kind'] ?? 'manual');
                    $kindLabel = $kind === 'ai_operation' ? 'ai' : $kind;
                    $summary = (string) ($rev['summary'] ?? 'Document update');
                    $createdAt = (string) ($rev['created_at'] ?? '');
                    $kindBadge = match ($kind) {
                        'ai_operation' => 'info',
                        'publish' => 'active',
                        'rollback' => 'warning',
                        default => 'muted',
                    };
                    $use = $inUseIds[$rId] ?? null;

                    $actions = '<a href="' . e(plugin_url('studio-builder', 'admin/builder.php?page=' . $pId)) . '" target="_blank" rel="noopener" class="btn btn-sm btn-ghost">'
                        . sb_svg('eye', 12) . ' Open in Builder</a>';
                    if ($canEdit) {
                        $actions .= ' <button type="submit" name="row_action" value="restore:' . $rId . '" class="btn btn-sm btn-primary" data-row-confirm="Restore page to snapshot #' . $revNum . '?" title="Roll the page back to this snapshot">'
                            . sb_svg('refresh', 12) . ' Restore</button>';
                    }
                    if ($canDelete && $use === null) {
                        $actions .= ' <button type="submit" name="row_action" value="delete:' . $rId . '" class="btn btn-sm btn-outline" style="color: #ef4444;" data-row-confirm="Delete snapshot #' . $revNum . ' for good?" title="Delete this snapshot">'
                            . sb_svg('trash', 12) . ' Delete</button>';
                    }

                    $detail = [
                        'summary' => ['label' => 'Summary & notes', 'value' => $summary],
                        'when'    => ['label' => 'Timestamp', 'value' => $createdAt !== '' ? date('M j, Y H:i', strtotime($createdAt)) : '—'],
                    ];
                    if ($use !== null) {
                        $detail['use'] = ['label' => 'In use', 'value' => 'This is the page\'s ' . $use . ' revision, so it cannot be deleted.'];
                    }

                    slate_data_row([
                        'select'       => ['name' => 'ids[]', 'value' => (string) $rId, 'label' => 'Select snapshot #' . $revNum . ' of ' . $pTitle],
                        'avatar'       => $pTitle,
                        'avatar_color' => $kindBadge === 'muted' ? 'muted' : ($kindBadge === 'active' ? 'success' : $kindBadge),
                        'title'        => $pTitle,
                        'meta'         => '/' . $pSlug,
                        'value'        => '#' . $revNum,
                        'badge'        => [strtoupper($kindLabel), $kindBadge],
                        'detail'       => $detail,
                        'actions'      => $actions,
                    ]);
                }
                ?>
            </div>
        </form>
        <?php sb_render_pagination($self, ['kind' => $kindFilter], $pageNo, $perPage, $total); ?>
        <?php slate_data_list_script(); ?>
        <?= \Slate\Module\StudioBuilder\Admin\PageListView::selectionScript('studio-revision-list') ?>
        <script>
        // A row's own Restore / Delete button asks first; the bulk Delete button asks in the selection script.
        document.getElementById('studio-revision-form').addEventListener('click', function (e) {
            var b = e.target.closest('[data-row-confirm]');
            if (b && !window.confirm(b.getAttribute('data-row-confirm'))) e.preventDefault();
        });
        </script>
    <?php endif; ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
