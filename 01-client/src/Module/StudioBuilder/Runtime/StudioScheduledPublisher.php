<?php
/**
 * Kohevo Studio — publishes the pages whose scheduled time has come.
 *
 * A scheduled page has status `scheduled` and a `scheduled_for`, on the database's clock like `published_at`. When the
 * time comes its working draft is published by the normal path, as the person who scheduled it, and it keeps the
 * scheduled time as its publish date.
 *
 * `run()` is the `frequent_cron` listener. A site without cron would never fire, so `runSoon()` also sweeps from public
 * requests, at most once a minute; a stamp file keeps that check off the database. A draft that no longer validates goes
 * back to draft and is logged, so it is not retried for ever; any other failure is tried again on the next sweep.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\StudioPermissions;

final class StudioScheduledPublisher
{
    public const SWEEP_SECONDS = 60;
    private const BATCH = 50;

    /** @return int how many pages were published */
    public static function run(): int
    {
        $rows = Database::all(
            "SELECT id, tenant_id, scheduled_for, updated_by, created_by FROM studiobuilder_pages WHERE status = 'scheduled' AND scheduled_for IS NOT NULL AND scheduled_for <= ? ORDER BY scheduled_for LIMIT " . self::BATCH,
            [\slate_db_now()],
        );
        if ($rows === []) {
            return 0;
        }
        $runtime = StudioRuntimeFactory::build();
        $done = 0;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $actor = StudioActor::authenticated(max(1, (int) ($row['updated_by'] ?: $row['created_by'])), StudioPermissions::ALL, true);
            try {
                $runtime->tenants->runAs((int) $row['tenant_id'], static function () use ($runtime, $actor, $id, $row): void {
                    $runtime->app->publish($actor, $id, null);
                    $runtime->app->setPublishDate($actor, $id, (string) $row['scheduled_for']);
                });
                $done++;
            } catch (StudioValidationException $e) {
                error_log('[studio-builder] scheduled page ' . $id . ' went back to draft: ' . $e->getMessage());
                Database::query("UPDATE studiobuilder_pages SET status = 'draft', scheduled_for = NULL WHERE id = ?", [$id]);
            } catch (\Throwable $e) {
                error_log('[studio-builder] scheduled page ' . $id . ' could not be published: ' . $e->getMessage());
            }
        }
        return $done;
    }

    /** A sweep from a public request, when none ran in the last minute. It never gets in the way of the page. */
    public static function runSoon(): void
    {
        $stamp = dirname(__DIR__, 4) . '/storage/.studio-schedule-sweep';
        $last = @filemtime($stamp);
        if ($last !== false && time() - $last < self::SWEEP_SECONDS) {
            return;
        }
        if (!@touch($stamp)) {
            return;
        }
        try {
            self::run();
        } catch (\Throwable $e) {
            error_log('[studio-builder] scheduled publishing: ' . $e->getMessage());
        }
    }
}
