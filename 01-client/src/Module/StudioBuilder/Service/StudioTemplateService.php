<?php
/**
 * Kohevo Studio (studio-builder) — Template Lifecycle Service.
 *
 * Manages `studiobuilder_templates` rows (`StudioTemplate`) — the "Reusable
 * preset" concept of target architecture §9: authored canonical content with
 * catalog metadata that a page receives as an OWNED COPY. A template's
 * document is independently re-validated and re-normalized on every save and
 * every apply — a template is just another canonical document with catalog
 * metadata, never a second content model (§21.9 "No parallel CMS tables
 * without a documented lineage decision").
 *
 * Phase 6:
 *   - `applyTemplate()` takes the caller's `expected_revision_id` and hands it
 *     to the ONE optimistic-concurrency check every draft write uses
 *     (StudioRevisionService::createDraftRevision). It no longer reads the
 *     page's current draft pointer itself — that silently made "apply" the
 *     only mutation able to overwrite a newer draft.
 *   - `insertOperations()` turns a section/header/footer/block preset into
 *     canonical `insert_section` / `insert_block` operations (fresh ids via
 *     DocumentCopier) so a preset lands in a page through the same operation
 *     pipeline as any other edit. Copy semantics: no reference is recorded.
 *   - `extractTemplateDocument()` builds a template from a page's current
 *     document (whole page, one section, or one block) server-side.
 *   - `deleteTemplate()` with system-template and in-use protection.
 *   - optional tenant-owned thumbnail (`thumbnail_media_id`, validated by the
 *     same `media_exists` check as authored media references).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Domain\StudioTemplate;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Tenancy\TenantContext;

final class StudioTemplateService
{
    /** Template types that are inserted INTO a document (copy) rather than applied to it. */
    public const INSERTABLE_TYPES = ['section_preset', 'header_preset', 'footer_preset', 'block_preset'];

    /** Preset types whose document is a synthetic `section_preset` document wrapping the content. */
    private const WRAPPED_TYPES = ['section_preset', 'block_preset'];

    public const MAX_CATEGORY_LENGTH    = 64;
    public const MAX_DESCRIPTION_LENGTH = 1000;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TemplateRepository $templates,
        private readonly PageRepository $pages,
        private readonly StudioRevisionService $revisions,
        private readonly BlockRegistry $registry,
        private readonly ?DependencyRepository $dependencies = null,
    ) {}

    /**
     * Create or update a tenant-owned template. Validates and normalizes the
     * supplied document before persisting it. System templates (`is_system = 1`)
     * can never be overwritten through this path.
     *
     * @param array<string, mixed> $document Raw or already-normalized canonical document.
     * @param array<string, mixed> $validationOptions Forwarded to `ValidatedDocument::from()`.
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
        array $validationOptions = [],
        ?int $thumbnailMediaId = null,
    ): array {
        $this->requireTenantId();

        $errors = [];
        if (preg_match(CanonicalDocumentSchema::TEMPLATE_KEY_PATTERN, $templateKey) !== 1) {
            $errors[] = ['path' => '$.template_key', 'code' => 'invalid_template_key', 'message' => 'template_key must be a valid slug string.'];
        }
        if (!in_array($templateType, StudioTemplate::ALLOWED_TEMPLATE_TYPES, true)) {
            $errors[] = ['path' => '$.template_type', 'code' => 'invalid_template_type', 'message' => 'template_type must be one of: ' . implode(', ', StudioTemplate::ALLOWED_TEMPLATE_TYPES) . '.'];
        }
        $category = trim($category) === '' ? 'general' : trim($category);
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $category) !== 1) {
            $errors[] = ['path' => '$.category', 'code' => 'invalid_category', 'message' => 'category must be a short slug.'];
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 191 || preg_match('/[<>]/', $name) === 1) {
            $errors[] = ['path' => '$.name', 'code' => 'invalid_name', 'message' => 'name must be a plain string of 1..191 characters.'];
        }
        if ($description !== null) {
            $description = trim($description);
            if ($description === '') {
                $description = null;
            } elseif (mb_strlen($description, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH || preg_match('/[<>]/', $description) === 1) {
                $errors[] = ['path' => '$.description', 'code' => 'invalid_description', 'message' => 'description must be plain text of at most ' . self::MAX_DESCRIPTION_LENGTH . ' characters.'];
            }
        }
        if ($thumbnailMediaId !== null) {
            $mediaExists = $validationOptions['media_exists'] ?? null;
            if ($thumbnailMediaId <= 0 || (is_callable($mediaExists) && !$mediaExists($thumbnailMediaId))) {
                $errors[] = ['path' => '$.thumbnail_media_id', 'code' => 'cross_tenant_or_missing_media', "message" => "Referenced media_id {$thumbnailMediaId} does not exist in the active tenant."];
            }
        }
        if ($errors !== []) {
            throw new StudioValidationException($errors);
        }

        $normalizedDocument = ValidatedDocument::from($document, $this->registry, $validationOptions)->toArray();
        self::assertDocumentFitsType($normalizedDocument, $templateType);

        $existing = $this->templates->findByKey($templateKey);
        if ($existing !== null && (bool) $existing['is_system']) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'system_template_immutable', 'message' => "System template '{$templateKey}' cannot be overwritten."],
            ]);
        }

        $data = [
            'template_key'       => $templateKey,
            'template_type'      => $templateType,
            'category'           => $category,
            'name'               => $name,
            'description'        => $description,
            'thumbnail_media_id' => $thumbnailMediaId,
            'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
            'document_json'      => CanonicalJson::encode($normalizedDocument),
            'is_system'          => 0,
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
     * Delete a tenant-owned template. System templates are protected, and so
     * is a template some page's current draft or published document still
     * names as its `template_key` (the document would stop validating).
     *
     * @return array<string, mixed> the deleted row
     */
    public function deleteTemplate(string $templateKey): array
    {
        $this->requireTenantId();
        $row = $this->templates->findByKey($templateKey);
        if ($row === null) {
            throw new StudioNotFoundException("Studio template '{$templateKey}' was not found in the active tenant.", ['template_key' => $templateKey]);
        }
        if ((bool) $row['is_system']) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'system_template_immutable', 'message' => "System template '{$templateKey}' cannot be deleted."],
            ]);
        }
        if ($this->dependencies !== null) {
            $users = $this->dependencies->currentDependentPageIds('partial', 'template:' . $templateKey);
            if ($users !== []) {
                throw new StudioValidationException([
                    ['path' => '$.template_key', 'code' => 'template_in_use', 'message' => 'This template is still named by ' . count($users) . ' page document(s).'],
                ]);
            }
        }
        $this->templates->delete((int) $row['id']);
        return $row;
    }

    /**
     * Apply a page template's canonical document onto a page as a new draft
     * revision, replacing the page's current working document.
     *
     * Concurrency (Phase 6 preflight #1): `$expectedRevisionId` is the
     * client's belief about the page's current draft revision and is verified
     * under the page row lock by StudioRevisionService exactly like every
     * other draft write. A stale or missing value is a
     * StudioConcurrencyException — nothing is overwritten, nothing is merged,
     * no revision is created. (Every page has a draft from creation on, so a
     * null here is always a conflict; it is accepted as a type only so the
     * revision service remains the single place that decides.)
     *
     * Phase 2 finding F2 fix: `document_type` is overridden on the RAW
     * template document *before* it is validated/normalized — the persisted
     * revision is guaranteed to be the output of a single, complete pass
     * through `ValidatedDocument::from()`.
     *
     * @param array<string, mixed> $validationOptions Forwarded to `ValidatedDocument::from()`.
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function applyTemplate(string $templateKey, int $pageId, ?int $expectedRevisionId, int $actorId, array $validationOptions = []): array
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

        // A template only supplies content structure; the target page keeps its own
        // address identity. Set BEFORE validation, not after — see F2 fix above.
        $rawDocument = $template->document;
        $rawDocument['document_type'] = (string) $page['page_type'];
        $validated = ValidatedDocument::from($rawDocument, $this->registry, $validationOptions);

        return $this->revisions->createDraftRevision(
            $pageId,
            $validated,
            $expectedRevisionId,
            $actorId,
            'manual',
            "Applied template '{$templateKey}'",
        );
    }

    /**
     * The canonical operations that insert a reusable preset into a document
     * as an OWNED COPY (fresh section/block ids; never a `global_ref`):
     *
     *   section_preset / header_preset / footer_preset  → one `insert_section` per
     *       template section, at `$index`, `$index + 1`, …
     *   block_preset  → one `insert_block` of the preset's block into
     *       `$parentId` (a section id or a child-capable block id) at `$index`
     *
     * The result still goes through the full operation pipeline (applier →
     * validate → normalize → dependency extraction → revision), so an
     * unentitled block or an over-limit document is rejected there.
     *
     * @return list<DocumentOperation>
     */
    public function insertOperations(string $templateKey, int $index, ?string $parentId): array
    {
        $this->requireTenantId();
        $row = $this->templates->findByKey($templateKey);
        if ($row === null) {
            throw new StudioNotFoundException("Studio template '{$templateKey}' was not found in the active tenant.", ['template_key' => $templateKey]);
        }
        $template = StudioTemplate::fromRow($row);
        if (!in_array($template->templateType, self::INSERTABLE_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'template_not_insertable', 'message' => "Template '{$templateKey}' is a page template; apply it to the page instead of inserting it."],
            ]);
        }
        $sections = [];
        foreach ((array) ($template->document['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections[] = DocumentCopier::copySection($section);
            }
        }
        if ($sections === []) {
            throw new StudioValidationException([
                ['path' => '$.template_key', 'code' => 'template_empty', 'message' => "Template '{$templateKey}' has no content to insert."],
            ]);
        }

        if ($template->templateType === 'block_preset') {
            $block = $sections[0]['blocks'][0] ?? null;
            if (!is_array($block) || $parentId === null || $parentId === '') {
                throw new StudioValidationException([
                    ['path' => '$.parent_id', 'code' => 'required_field', 'message' => 'A block preset needs a parent section or container to be inserted into.'],
                ]);
            }
            return [new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => $parentId, 'index' => $index, 'block' => $block])];
        }

        $operations = [];
        foreach ($sections as $i => $section) {
            $operations[] = new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => $index + $i, 'section' => $section]);
        }
        return $operations;
    }

    /**
     * Build the canonical document a template of `$templateType` stores, from
     * a page's current working document: the whole document (page / header /
     * footer templates), one section (section_preset) or one block
     * (block_preset). Content is copied with fresh ids; a section that is a
     * live global reference cannot become a preset (the component itself is
     * the reusable object).
     *
     * @param array<string, mixed> $pageDocument
     * @return array<string, mixed>
     */
    public static function extractTemplateDocument(array $pageDocument, ?string $nodeId, string $templateType): array
    {
        if (!in_array($templateType, StudioTemplate::ALLOWED_TEMPLATE_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.template_type', 'code' => 'invalid_template_type', 'message' => 'template_type must be one of: ' . implode(', ', StudioTemplate::ALLOWED_TEMPLATE_TYPES) . '.'],
            ]);
        }

        if (!in_array($templateType, self::WRAPPED_TYPES, true)) {
            $document = $pageDocument;
            $document['sections'] = [];
            foreach ((array) ($pageDocument['sections'] ?? []) as $section) {
                if (is_array($section)) {
                    $document['sections'][] = empty($section['global_ref']) ? DocumentCopier::copySection($section) : $section;
                }
            }
            return $document;
        }

        if ($nodeId === null || $nodeId === '') {
            throw new StudioValidationException([
                ['path' => '$.node_id', 'code' => 'required_field', 'message' => 'A section or block id is required for this template type.'],
            ]);
        }
        $found = DocumentCopier::findNode($pageDocument, $nodeId);
        if ($found === null) {
            throw new StudioNotFoundException("Node '{$nodeId}' was not found in the page.", ['node_id' => $nodeId]);
        }

        $wrapper = CanonicalDocumentSchema::emptyDocument(CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE);
        if ($templateType === 'section_preset') {
            if ($found['kind'] !== 'section') {
                throw new StudioValidationException([
                    ['path' => '$.node_id', 'code' => 'node_kind_mismatch', 'message' => 'A section preset must be saved from a section.'],
                ]);
            }
            if (!empty($found['node']['global_ref'])) {
                throw new StudioValidationException([
                    ['path' => '$.node_id', 'code' => 'cannot_preset_reference', 'message' => 'A section that references a global component cannot be saved as a preset; edit the component instead.'],
                ]);
            }
            $wrapper['sections'] = [DocumentCopier::copySection($found['node'])];
            return $wrapper;
        }

        if ($found['kind'] !== 'block') {
            throw new StudioValidationException([
                ['path' => '$.node_id', 'code' => 'node_kind_mismatch', 'message' => 'A block preset must be saved from a block.'],
            ]);
        }
        $section = DocumentCopier::copySection([
            'label'      => 'Block preset',
            'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => [$found['node']],
        ]);
        $wrapper['sections'] = [$section];
        return $wrapper;
    }

    /**
     * A transport-safe structural summary of a template document (counts and
     * block labels) — the "safe preview" the library shows without ever
     * sending the document body to the browser.
     *
     * @param array<string, mixed> $document
     * @return array{sections: int, blocks: int, block_labels: list<string>}
     */
    public function summarize(array $document): array
    {
        $sections = 0;
        $blocks   = 0;
        $labels   = [];
        $walk = function (array $list) use (&$walk, &$blocks, &$labels): void {
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $blocks++;
                $type = (string) ($block['type'] ?? '');
                $def  = $this->registry->get($type);
                $label = $def !== null ? $def->label() : $type;
                if ($label !== '' && !in_array($label, $labels, true) && count($labels) < 12) {
                    $labels[] = $label;
                }
                $walk(is_array($block['children'] ?? null) ? $block['children'] : []);
            }
        };
        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (is_array($section)) {
                $sections++;
                $walk(is_array($section['blocks'] ?? null) ? $section['blocks'] : []);
            }
        }
        return ['sections' => $sections, 'blocks' => $blocks, 'block_labels' => $labels];
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

    public function findTemplate(string $templateKey): ?array
    {
        $this->requireTenantId();
        return $this->templates->findByKey($templateKey);
    }

    /**
     * A preset document must be shaped like the thing it stands for.
     *
     * @param array<string, mixed> $document
     */
    private static function assertDocumentFitsType(array $document, string $templateType): void
    {
        $sections = (array) ($document['sections'] ?? []);
        if ($templateType === 'block_preset') {
            $blocks = is_array($sections[0]['blocks'] ?? null) ? $sections[0]['blocks'] : [];
            if (count($sections) !== 1 || count($blocks) !== 1) {
                throw new StudioValidationException([
                    ['path' => '$.document', 'code' => 'block_preset_shape', 'message' => 'A block preset holds exactly one section with exactly one block.'],
                ]);
            }
        }
        foreach ($sections as $i => $section) {
            if (in_array($templateType, self::INSERTABLE_TYPES, true) && is_array($section) && !empty($section['global_ref'])) {
                throw new StudioValidationException([
                    ['path' => "\$.document.sections[{$i}].global_ref", 'code' => 'cannot_preset_reference', 'message' => 'A reusable preset holds owned content, never a live global reference.'],
                ]);
            }
        }
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
