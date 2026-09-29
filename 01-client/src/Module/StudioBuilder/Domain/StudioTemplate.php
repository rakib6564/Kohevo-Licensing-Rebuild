<?php
/**
 * Kohevo Studio (studio-builder) — Template Value Object.
 *
 * Wraps a `studiobuilder_templates` row (target architecture §9 "Template": a
 * complete page/document preset used to create a page). Carries the decoded
 * canonical document alongside its catalog metadata (key, type, category,
 * name). The document itself must still independently pass
 * `DocumentValidator`/`DocumentNormalizer` before it is trusted — this object
 * only decodes and shapes the row, it does not re-validate document content.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Domain;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;

final class StudioTemplate
{
    public const ALLOWED_TEMPLATE_TYPES = [
        'page_template',
        'section_preset',
        'header_preset',
        'footer_preset',
        'block_preset',
    ];

    /**
     * @param array<string, mixed> $document Decoded canonical document.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $uuid,
        public readonly string $templateKey,
        public readonly string $templateType,
        public readonly string $category,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?int $thumbnailMediaId,
        public readonly string $schemaVersion,
        public readonly array $document,
        public readonly bool $isSystem,
    ) {
        if (preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $this->templateKey) !== 1) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'invalid_template_key', 'message' => 'StudioTemplate template_key must be a valid slug string.'],
            ]);
        }
        if (!in_array($this->templateType, self::ALLOWED_TEMPLATE_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.template_type', 'code' => 'invalid_template_type', 'message' => 'StudioTemplate template_type must be one of: ' . implode(', ', self::ALLOWED_TEMPLATE_TYPES) . '.'],
            ]);
        }
        if (trim($this->name) === '' || mb_strlen($this->name, 'UTF-8') > 191) {
            throw new StudioValidationException([
                ['path' => '$.name', 'code' => 'invalid_name', 'message' => 'StudioTemplate name must be a non-empty string of at most 191 characters.'],
            ]);
        }
        if ($this->schemaVersion !== CanonicalDocumentSchema::SCHEMA_VERSION) {
            throw new StudioValidationException([
                ['path' => '$.schema_version', 'code' => 'unsupported_schema_version', 'message' => 'StudioTemplate schema_version must match the current canonical document schema.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row A `studiobuilder_templates` row (as returned by TemplateRepository).
     */
    public static function fromRow(array $row): self
    {
        $document = CanonicalJson::decode((string) $row['document_json']);

        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            uuid: (string) $row['uuid'],
            templateKey: (string) $row['template_key'],
            templateType: (string) $row['template_type'],
            category: (string) $row['category'],
            name: (string) $row['name'],
            description: isset($row['description']) && $row['description'] !== null ? (string) $row['description'] : null,
            thumbnailMediaId: isset($row['thumbnail_media_id']) && $row['thumbnail_media_id'] !== null ? (int) $row['thumbnail_media_id'] : null,
            schemaVersion: (string) ($row['schema_version'] ?? CanonicalDocumentSchema::SCHEMA_VERSION),
            document: $document,
            isSystem: (bool) ($row['is_system'] ?? false),
        );
    }

    /**
     * Row-shape suitable for `TemplateRepository::insert()`/`update()`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'uuid'               => $this->uuid,
            'template_key'       => $this->templateKey,
            'template_type'      => $this->templateType,
            'category'           => $this->category,
            'name'               => $this->name,
            'description'        => $this->description,
            'thumbnail_media_id' => $this->thumbnailMediaId,
            'schema_version'     => $this->schemaVersion,
            'document_json'      => CanonicalJson::encode($this->document),
            'is_system'          => $this->isSystem ? 1 : 0,
        ];
        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        return $data;
    }
}
