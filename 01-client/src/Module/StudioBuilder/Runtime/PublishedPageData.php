<?php
/**
 * Kohevo Studio — the settings and SEO a page was PUBLISHED with.
 *
 * `studiobuilder_pages.settings_json` and `.seo_json` mirror the current
 * DRAFT: every draft save, rollback, import and template apply rewrites
 * them (admin screens rely on that). Anything a visitor sees — post lists,
 * category counts, whether an address is a page or a theme template — must
 * come from the published revision instead, or an unpublished edit (by
 * someone who may edit but not publish) changes the live site.
 *
 * When the working draft IS the published revision the columns are an exact
 * mirror and are used as they are; otherwise the published documents are
 * read in one query per 500 pages.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\CanonicalJson;

final class PublishedPageData
{
    /**
     * @param list<array<string, mixed>> $rows page rows with id, published_revision_id, active_draft_revision_id, settings_json, seo_json
     * @return array<int, array{settings: array<string, mixed>, seo: array<string, mixed>}> keyed by page id; a row with no
     *         readable published revision is left out
     */
    public static function forRows(int $tenantId, array $rows): array
    {
        $out = [];
        $needed = [];
        foreach ($rows as $row) {
            $pageId = (int) ($row['id'] ?? 0);
            $published = (int) ($row['published_revision_id'] ?? 0);
            if ($pageId <= 0 || $published <= 0) {
                continue;
            }
            $draft = (int) ($row['active_draft_revision_id'] ?? 0);
            if ($draft === 0 || $draft === $published) {
                $out[$pageId] = [
                    'settings' => self::object((string) ($row['settings_json'] ?? '')),
                    'seo'      => self::object((string) ($row['seo_json'] ?? '')),
                ];
            } else {
                $needed[$published] = $pageId;
            }
        }
        foreach (array_chunk(array_keys($needed), 500) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $revisions = Database::rows(
                "SELECT id, page_id, document_json FROM studiobuilder_revisions WHERE tenant_id = ? AND id IN ({$marks})",
                array_merge([$tenantId], $chunk)
            );
            foreach ($revisions as $revision) {
                $pageId = $needed[(int) $revision['id']] ?? 0;
                if ($pageId === 0 || (int) $revision['page_id'] !== $pageId) {
                    continue;
                }
                $document = self::object((string) ($revision['document_json'] ?? ''));
                $out[$pageId] = [
                    'settings' => is_array($document['settings'] ?? null) ? $document['settings'] : [],
                    'seo'      => is_array($document['seo'] ?? null) ? $document['seo'] : [],
                ];
            }
        }
        return $out;
    }

    /** The published settings of one page row ([] when it has no readable published revision). @return array<string, mixed> */
    public static function settingsOf(int $tenantId, array $row): array
    {
        return self::forRows($tenantId, [$row])[(int) ($row['id'] ?? 0)]['settings'] ?? [];
    }

    /** @return array<string, mixed> */
    private static function object(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = CanonicalJson::decode($json);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
