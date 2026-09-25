<?php
/**
 * Slate — DocumentTemplateRepository: tenant-owned, reusable page templates
 * (migration 0020_document_templates) backing admin/templates.php's Template
 * Library — "Save as Template" from the editor, "Use Template" to seed a new
 * page, and JSON export/import.
 *
 * `document` is stored as the same schema-1 DocumentSchema shape every page
 * revision uses (see RevisionStore), never a bespoke template format, so a
 * template and a page's working draft round-trip through the same validator.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Data\Repository;

final class DocumentTemplateRepository extends Repository
{
    protected string $table = 'document_templates';

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->query()->orderBy('updated_at', 'desc')->get();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): array|null
    {
        $row = $this->query()->where('id', $id)->first();
        return $row === null ? null : $row;
    }

    /** @param array<string,mixed> $document a validated schema-1 document */
    public function create(string $name, string $type, array $document): int
    {
        return $this->insert([
            'name'     => $name,
            'type'     => $type,
            'document' => json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /** @return array<string,mixed>|null the decoded document, or null if the row/JSON is missing or invalid */
    public function documentOf(array $row): ?array
    {
        $decoded = json_decode((string) ($row['document'] ?? ''), true);
        return is_array($decoded) ? $decoded : null;
    }
}
