<?php
/**
 * Kohevo Studio (studio-builder) — Template Lifecycle Service.
 *
 * Manages `studiobuilder_templates` rows (`StudioTemplate`) and applies a
 * template's canonical document onto a page as a new draft revision. A
 * template's document is independently re-validated and re-normalized on
 * every save and every apply — a template is just another canonical document
 * with catalog metadata, never a second content model (target architecture §9,
 * §21.9 "No parallel CMS tables without a documented lineage decision").
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Domain\StudioTemplate;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Tenancy\TenantContext;

final class StudioTemplateService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TemplateRepository $templates,
        private readonly PageRepository $pages,
        private readonly StudioRevisionService $revisions,
        private readonly BlockRegistry $registry,
    ) {}

    /**
     * Create or update a tenant-owned template. Validates and normalizes the
     * supplied document before persisting it. System templates (`is_system = 1`)
     * can never be overwritten through this path.
     *
     * @param array<string, mixed> $document Raw or already-normalized canonical document.
     * @return array<string, mixed>
     */
    public function saveTemplate(
        string $templateKey,
        string $templateType,
        string $category,
        string $name,
        ?string $description,
        array $document,
        int $actorId,
    ): array {
        $this->requireTenantId();

        if (!in_array($templateType, StudioTemplate::ALLOWED_TEMPLATE_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.template_type', 'code' => 'invalid_template_type', 'message' => 'template_type must be one of: ' . implode(', ', StudioTemplate::ALLOWED_TEMPLATE_TYPES) . '.'],
            ]);
        }

        $normalizedDocument = DocumentNormalizer::validateAndNormalize($document, $this->registry);

        $existing = $this->templates->findByKey($templateKey);
        if ($existing !== null && (bool) $existing['is_system']) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'system_template_immutable', 'message' => "System template '{$templateKey}' cannot be overwritten."],
            ]);
        }

        $data = [
            'template_key'   => $templateKey,
            'template_type'  => $templateType,
            'category'       => $category,
            'name'           => $name,
            'description'    => $description,
            'schema_version' => CanonicalDocumentSchema::SCHEMA_VERSION,
            'document_json'  => CanonicalJson::encode($normalizedDocument),
            'is_system'      => 0,
        ];

        if ($existing !== null) {
            $this->templates->update((int) $existing['id'], $data);
            return $this->templates->find((int) $existing['id']) ?? [];
        }

        $data['uuid']       = self::newUuidV4();
        $data['created_by'] = $actorId > 0 ? $actorId : null;
        $templateId = $this->templates->insert($data);

        return $this->templates->find($templateId) ?? [];
    }

    /**
     * Apply a template's canonical document onto a page as a new draft
     * revision. The template document is decoded, re-validated, and
     * re-normalized before being written — the same fail-closed guarantee a
     * hand-authored draft gets.
     *
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string}
     */
    public function applyTemplate(string $templateKey, int $pageId, int $actorId): array
    {
        $this->requireTenantId();

        $templateRow = $this->templates->findByKey($templateKey);
        if ($templateRow === null) {
            throw new StudioNotFoundException("Studio template '{$templateKey}' was not found in the active tenant.", ['template_key' => $templateKey]);
        }
        $template = StudioTemplate::fromRow($templateRow);

        $page = $this->pages->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }

        $normalizedDocument = DocumentNormalizer::validateAndNormalize($template->document, $this->registry);
        // A template only supplies content structure; the target page keeps its own address identity.
        $normalizedDocument['document_type'] = (string) $page['page_type'];

        $expectedRevisionId = isset($page['active_draft_revision_id']) && $page['active_draft_revision_id'] !== null
            ? (int) $page['active_draft_revision_id']
            : null;

        return $this->revisions->createDraftRevision(
            $pageId,
            $normalizedDocument,
            $expectedRevisionId,
            $actorId,
            'manual',
            "Applied template '{$templateKey}'",
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(?string $templateType = null, ?string $category = null): array
    {
        $where = [];
        if ($templateType !== null) {
            $where['template_type'] = $templateType;
        }
        if ($category !== null) {
            $where['category'] = $category;
        }
        return $this->templates->all($where, 'name ASC');
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }

    private static function newUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
