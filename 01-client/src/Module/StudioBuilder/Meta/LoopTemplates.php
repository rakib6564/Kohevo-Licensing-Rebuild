<?php
/**
 * Kohevo Studio — Loop Item templates: one card designed in the builder, drawn by a Query Loop once per item. A template
 * names its content type the way a Single template does; a loop set to Automatic takes the one for its content type.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Meta;

use Slate\Data\Database;

final class LoopTemplates
{
    /** A Query Loop's choice for its own built-in cards. */
    public const NONE = 'none';

    /** @return list<array{uuid: string, id: int, title: string, content_type: string, document_json: string}> */
    public static function published(int $tenantId): array
    {
        $rows = Database::all(
            "SELECT p.id, p.uuid, p.title, r.document_json FROM studiobuilder_pages p JOIN studiobuilder_revisions r ON r.id = p.published_revision_id"
            . " WHERE p.tenant_id = ? AND p.status = 'published' AND p.page_type = 'system' AND r.document_json LIKE ? ORDER BY p.id ASC",
            [$tenantId, '%"template_type":"loop"%']
        );
        $out = [];
        foreach ($rows as $row) {
            $document = json_decode((string) $row['document_json'], true);
            if (is_array($document) && ($document['settings']['template_type'] ?? '') === 'loop') {
                $out[] = ['uuid' => (string) $row['uuid'], 'id' => (int) $row['id'], 'title' => (string) $row['title'], 'content_type' => PageMeta::singleTemplateType($document), 'document_json' => (string) $row['document_json']];
            }
        }
        return $out;
    }

    /**
     * The template a loop uses: the chosen one, or on Automatic the one for its content type, then none.
     *
     * @param list<array{uuid: string, id: int, title: string, content_type: string, document_json: string}> $all
     * @return ?array{uuid: string, id: int, title: string, content_type: string, document_json: string}
     */
    public static function pick(array $all, string $choice, string $contentType): ?array
    {
        if ($choice === self::NONE) {
            return null;
        }
        foreach ($all as $template) {
            if ($choice !== '' ? $template['uuid'] === $choice : ($contentType !== '' && $template['content_type'] === $contentType)) {
                return $template;
            }
        }
        return null;
    }

    /**
     * What the blocks of a card read for one item (a row of the posts provider).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function attributes(array $row): array
    {
        return [
            'title' => (string) ($row['title'] ?? ''), 'post_title' => (string) ($row['title'] ?? ''), 'post_url' => (string) ($row['url'] ?? ''),
            'post_excerpt' => (string) ($row['excerpt'] ?? ''), 'post_image' => (string) ($row['featured_image'] ?? ''), 'post_date' => (string) ($row['date_formatted'] ?? ''),
            'post_id' => (string) ($row['id'] ?? ''), 'post_timestamp' => (string) (strtotime((string) ($row['published_at'] ?? '')) ?: ''), 'category' => (string) ($row['category'] ?? ''), 'author_name' => (string) ($row['author_name'] ?? ''),
        ];
    }
}
