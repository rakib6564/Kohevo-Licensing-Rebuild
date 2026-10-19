<?php
/**
 * Kohevo Studio — the selection bar's Delete for a list of Studio pages.
 *
 * Shared by the Pages screen and the Overview's "Recent Studio Pages", which
 * render the same PageListView inside a CSRF-protected POST form. Each screen
 * checks the CSRF token, calls `sb_archive_pages()`, and redirects (303) with
 * the two counts; `sb_bulk_delete_flash()` turns them back into the message.
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioException;

if (!function_exists('sb_archive_pages')) {
    /**
     * Deleting a page is archiving it: it leaves the site and the list, and its history stays. Every id goes through
     * `archivePage()`, which checks the actor's Edit permission and the tenant, so a forged id or a viewer deletes
     * nothing. A page that cannot go (a Global Component other pages still use) is skipped and counted, not allowed
     * to stop the rest. At most 200 ids are taken from one request.
     *
     * @param object $app        the Studio application service (anything with `archivePage(StudioActor, int)`)
     * @param mixed  $postedIds  `$_POST['ids']` as posted
     * @return array{deleted: int, failed: int}
     */
    function sb_archive_pages(object $app, StudioActor $actor, mixed $postedIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $postedIds), static fn(int $i): bool => $i > 0)));
        $deleted = 0;
        $failed = 0;
        foreach (array_slice($ids, 0, 200) as $pageId) {
            try {
                $app->archivePage($actor, $pageId);
                $deleted++;
            } catch (StudioException $e) {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /**
     * The message for `?deleted=N&failed=M` after the redirect, or null when the request carries neither.
     *
     * @param array<string, mixed> $query `$_GET`
     * @return array{type: string, msg: string}|null
     */
    function sb_bulk_delete_flash(array $query): ?array
    {
        if (!isset($query['deleted']) && !isset($query['failed'])) {
            return null;
        }
        $nDeleted = (int) ($query['deleted'] ?? 0);
        $nFailed  = (int) ($query['failed'] ?? 0);
        return $nFailed > 0
            ? ['type' => 'error', 'msg' => sprintf(__('studio_pages_deleted_some', '%1$d page(s) deleted, %2$d could not be (a shared component that other pages still use).'), $nDeleted, $nFailed)]
            : ['type' => 'success', 'msg' => sprintf(__('studio_pages_deleted', '%d page(s) deleted.'), $nDeleted)];
    }
}
