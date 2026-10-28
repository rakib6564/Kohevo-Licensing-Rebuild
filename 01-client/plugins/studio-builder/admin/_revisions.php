<?php
/**
 * Kohevo Studio — which revisions the Reviews screen never deletes.
 *
 * A page's published revision (the live page reads it) and current draft (the editor reads it) are kept, and so is the
 * draft's recent lineage: the review diff compares the draft with its parent by default, and the builder's Undo restores
 * the revisions before the current one, which are the draft's parent, its parent, and so on (a save's parent is the draft
 * it replaced; an undo's parent is the revision it went back to). `SB_REVISIONS_BUILDER_HISTORY` steps are kept, the depth of the
 * builder's Undo.
 */

declare(strict_types=1);

if (!defined('SB_REVISIONS_BUILDER_HISTORY')) {
    /** How many steps of a draft's lineage are kept: the builder's Undo depth (`MAX_HISTORY` in ui/src/core/sync.mjs). */
    define('SB_REVISIONS_BUILDER_HISTORY', 50);
}

if (!function_exists('sb_revisions_kept')) {
    /**
     * The revisions that must stay, and why.
     *
     * @param list<array{d: mixed, p: mixed}> $pages     each page's current draft (`d`) and published (`p`) revision ids
     * @param array<int, int>                 $parentOf  revision id => its parent revision id, for the pages' revisions
     * @return array<int, string> revision id => 'published' | 'current draft' | 'history'
     */
    function sb_revisions_kept(array $pages, array $parentOf, int $history = SB_REVISIONS_BUILDER_HISTORY): array
    {
        $kept = [];
        foreach ($pages as $pg) {
            $published = (int) ($pg['p'] ?? 0);
            if ($published > 0) {
                $kept[$published] = 'published';
            }
        }
        foreach ($pages as $pg) {
            $draft = (int) ($pg['d'] ?? 0);
            if ($draft <= 0) {
                continue;
            }
            $kept[$draft] = 'current draft';
            $rev = $draft;
            for ($i = 0; $i < $history; $i++) {
                $parent = (int) ($parentOf[$rev] ?? 0);
                if ($parent <= 0 || $parent === $draft) {
                    break;
                }
                $kept[$parent] ??= 'history';
                $rev = $parent;
            }
        }
        return $kept;
    }

    /**
     * `sb_revisions_kept()` from the database: every page's published revision and current draft, and the lineage of the
     * drafts of the pages named (the pages whose revisions are listed or about to be deleted).
     *
     * @param list<int> $pageIds
     * @return array<int, string>
     */
    function sb_revisions_kept_for(int $tenantId, array $pageIds): array
    {
        $pages = \Database::all('SELECT id, active_draft_revision_id AS d, published_revision_id AS p FROM studiobuilder_pages WHERE tenant_id = ?', [$tenantId]);
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn(int $i): bool => $i > 0)));
        $parentOf = [];
        if ($pageIds !== []) {
            $marks = implode(',', array_fill(0, count($pageIds), '?'));
            foreach (\Database::all("SELECT id, parent_revision_id FROM studiobuilder_revisions WHERE tenant_id = ? AND page_id IN ($marks)", array_merge([$tenantId], $pageIds)) as $row) {
                $parentOf[(int) $row['id']] = (int) ($row['parent_revision_id'] ?? 0);
            }
        }
        $inScope = array_flip($pageIds);
        $lineagePages = [];
        $livePages = [];
        foreach ($pages as $pg) {
            if (isset($inScope[(int) $pg['id']])) {
                $lineagePages[] = $pg;
            } else {
                $livePages[] = $pg;
            }
        }
        // Pages outside the scope: only their published revision and current draft (their lineage is not listed here).
        return sb_revisions_kept($lineagePages, $parentOf) + sb_revisions_kept($livePages, []);
    }
}
