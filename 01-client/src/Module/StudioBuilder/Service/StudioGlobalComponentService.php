<?php
/**
 * Kohevo Studio (studio-builder) — Global Component domain rules (Phase 6).
 *
 * A Global Component IS a Studio page of type `section_preset`
 * (CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE) — nothing new is stored:
 * identity = page uuid, content = its revisions, publish state = its
 * `published_revision_id`, concurrency = `expected_revision_id`, editing = the
 * ordinary builder. A consuming page holds `section.global_ref = <uuid>` and
 * owns no copy of the content ("Global reference"). This service holds the
 * rules around that relationship; orchestration (transactions, authorization,
 * audit) stays in StudioApplicationService.
 *
 *   Local content     section.global_ref = null, blocks owned by the page
 *   Reusable preset   studiobuilder_templates row; insertion COPIES (DocumentCopier)
 *   Global reference  section.global_ref = component uuid; blocks = []; live
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentCopier;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Tenancy\TenantContext;

final class StudioGlobalComponentService
{
    public const MAX_LISTED_COMPONENTS = 200;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly RevisionRepository $revisions,
        private readonly DependencyRepository $dependencies,
    ) {}

    /**
     * The tenant's components with the number of pages whose CURRENT draft or
     * published revision references each.
     *
     * @return list<array{row: array<string, mixed>, usage_count: int}>
     */
    public function listComponents(): array
    {
        $this->requireTenantId();
        $out = [];
        foreach ($this->pages->allOfType(CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, self::MAX_LISTED_COMPONENTS) as $row) {
            $out[] = ['row' => $row, 'usage_count' => count($this->dependentPageIds((string) $row['uuid']))];
        }
        return $out;
    }

    /** @return list<int> pages whose current draft/published revision references the component */
    public function dependentPageIds(string $componentRef): array
    {
        $this->requireTenantId();
        return $this->dependencies->currentDependentPageIds('partial', $componentRef);
    }

    /**
     * Archive protection: a component that a page still references (in its
     * current draft or its published revision) cannot be archived.
     *
     * @param array<string, mixed> $pageRow
     */
    public function assertNotReferenced(array $pageRow): void
    {
        if (($pageRow['page_type'] ?? null) !== CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE) {
            return;
        }
        $dependents = $this->dependentPageIds((string) $pageRow['uuid']);
        if ($dependents !== []) {
            throw new StudioValidationException([
                ['path' => '$.page_id', 'code' => 'component_in_use', 'message' => 'This global component is still used by ' . count($dependents) . ' page(s). Detach or remove those references first.'],
            ]);
        }
    }

    /**
     * The component's content as sections — its PUBLISHED revision when it has
     * one (what every consumer renders), otherwise its working draft.
     *
     * @param array<string, mixed> $componentRow
     * @return list<array<string, mixed>>
     */
    public function contentSections(array $componentRow): array
    {
        $pageId = (int) $componentRow['id'];
        $revisionId = !empty($componentRow['published_revision_id'])
            ? (int) $componentRow['published_revision_id']
            : (int) ($componentRow['active_draft_revision_id'] ?? 0);
        $revision = $revisionId > 0 ? $this->revisions->findByIdForPage($pageId, $revisionId) : null;
        if ($revision === null) {
            return [];
        }
        $document = CanonicalJson::decode((string) $revision['document_json']);
        $sections = [];
        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (is_array($section) && empty($section['global_ref'])) {
                $sections[] = $section;
            }
        }
        return $sections;
    }

    /**
     * The section a consuming page keeps in place of the content: same
     * label/visibility/layout as the section it replaces, no blocks, a live
     * reference.
     *
     * @param array<string, mixed> $section
     * @return array<string, mixed>
     */
    public static function referenceSectionFor(array $section, string $componentRef, ?string $label = null): array
    {
        return [
            'blocks'     => [],
            'global_ref' => $componentRef,
            'label'      => $label ?? (string) ($section['label'] ?? ''),
            'layout'     => is_array($section['layout'] ?? null) ? $section['layout'] : CanonicalDocumentSchema::defaultSectionLayout(),
            'visibility' => is_array($section['visibility'] ?? null) ? $section['visibility'] : CanonicalDocumentSchema::defaultVisibility(),
        ];
    }

    /**
     * Canonical operations that replace section `$sectionId` of `$document`
     * (a live reference) with an owned copy of the referenced component's
     * content — the "detach" of a global reference. Fails closed when the
     * section is not a reference or the component is missing in this tenant.
     *
     * @param array<string, mixed> $document the page's current working document
     * @return array{operations: list<DocumentOperation>, component: array<string, mixed>}
     */
    public function detachOperations(array $document, string $sectionId): array
    {
        $this->requireTenantId();
        $found = DocumentCopier::findNode($document, $sectionId);
        if ($found === null || $found['kind'] !== 'section') {
            throw new StudioNotFoundException("Section '{$sectionId}' was not found in the page.", ['section_id' => $sectionId]);
        }
        $section = $found['node'];
        $ref = $section['global_ref'] ?? null;
        if (!is_string($ref) || $ref === '') {
            throw new StudioValidationException([
                ['path' => '$.section_id', 'code' => 'not_a_global_reference', 'message' => 'This section does not reference a global component.'],
            ]);
        }
        $component = $this->pages->findComponentByRef($ref);
        if ($component === null) {
            throw new StudioNotFoundException('The referenced global component was not found in the active tenant.', ['global_ref' => $ref]);
        }

        $operations = [new DocumentOperation(DocumentOperation::OP_REMOVE_SECTION, ['section_id' => $sectionId])];
        $copies = array_map([DocumentCopier::class, 'copySection'], $this->contentSections($component));
        if ($copies === []) {
            // An empty component detaches to one empty, owned section.
            $copies = [DocumentCopier::copySection(['label' => (string) ($section['label'] ?? ''), 'layout' => $section['layout'] ?? null, 'visibility' => $section['visibility'] ?? null, 'blocks' => []])];
        }
        foreach ($copies as $i => $copy) {
            $copy['visibility'] = is_array($section['visibility'] ?? null) ? $section['visibility'] : CanonicalDocumentSchema::defaultVisibility();
            if (!is_array($copy['layout'] ?? null)) {
                $copy['layout'] = CanonicalDocumentSchema::defaultSectionLayout();
            }
            if (($copy['label'] ?? '') === '') {
                $copy['label'] = (string) ($section['label'] ?? $component['title']);
            }
            $operations[] = new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => $found['index'] + $i, 'section' => $copy]);
        }
        return ['operations' => $operations, 'component' => $component];
    }

    /**
     * The documents and operations for "make this section a global component":
     * the new component's first document (an owned copy of the section) and
     * the operations that replace the section in the source page by a live
     * reference. The uuid is only known once the component page exists, so
     * the reference operations are built by `referenceOperations()`.
     *
     * @param array<string, mixed> $document
     * @return array{section: array<string, mixed>, index: int, component_document: array<string, mixed>}
     */
    public function extractionFor(array $document, string $sectionId, string $componentTitle): array
    {
        $found = DocumentCopier::findNode($document, $sectionId);
        if ($found === null || $found['kind'] !== 'section') {
            throw new StudioNotFoundException("Section '{$sectionId}' was not found in the page.", ['section_id' => $sectionId]);
        }
        $section = $found['node'];
        if (!empty($section['global_ref'])) {
            throw new StudioValidationException([
                ['path' => '$.section_id', 'code' => 'already_a_global_reference', 'message' => 'This section already references a global component.'],
            ]);
        }
        $componentDocument = CanonicalDocumentSchema::emptyDocument(CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'default', $componentTitle);
        $componentDocument['sections'] = [DocumentCopier::copySection($section)];
        return ['section' => $section, 'index' => (int) $found['index'], 'component_document' => $componentDocument];
    }

    /**
     * @param array<string, mixed> $section the section being replaced
     * @return list<DocumentOperation>
     */
    public static function referenceOperations(array $section, int $index, string $componentRef): array
    {
        return [
            new DocumentOperation(DocumentOperation::OP_REMOVE_SECTION, ['section_id' => (string) $section['id']]),
            new DocumentOperation(DocumentOperation::OP_INSERT_SECTION, ['index' => $index, 'section' => self::referenceSectionFor($section, $componentRef)]),
        ];
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }
}
