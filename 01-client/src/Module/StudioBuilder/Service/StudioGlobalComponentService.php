<?php
/**
 * Kohevo Studio (studio-builder) — Global Component domain rules (Phase 6).
 *
 * A Global Component IS a Studio page of type `container_preset`
 * (CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE) — nothing new is stored:
 * identity = page uuid, content = its revisions, publish state = its
 * `published_revision_id`, concurrency = `expected_revision_id`, editing = the
 * ordinary builder. A consuming page's top-level Container holds `global_ref = <uuid>` and
 * owns no copy of the content ("Global reference"). This service holds the
 * rules around that relationship; orchestration (transactions, authorization,
 * audit) stays in StudioApplicationService.
 *
 *   Local content     no global_ref, children owned by the page
 *   Reusable preset   studiobuilder_templates row; insertion COPIES (DocumentCopier)
 *   Global reference  global_ref = component uuid; children = []; live
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
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
        private readonly ?BlockRegistry $registry = null,
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
     * The component's content as Containers — its PUBLISHED revision when it has
     * one (what every consumer renders), otherwise its working draft.
     *
     * @param array<string, mixed> $componentRow
     * @return list<array<string, mixed>>
     */
    public function contentContainers(array $componentRow): array
    {
        $pageId = (int) $componentRow['id'];
        $revisionId = !empty($componentRow['published_revision_id'])
            ? (int) $componentRow['published_revision_id']
            : (int) ($componentRow['active_draft_revision_id'] ?? 0);
        $revision = $revisionId > 0 ? $this->revisions->findByIdForPage($pageId, $revisionId) : null;
        if ($revision === null) {
            return [];
        }
        $document = DocumentUpgrader::structure(CanonicalJson::decode((string) $revision['document_json']));
        if ($this->registry !== null) {
            // Detached copies are written into a page: older block versions are upgraded first.
            $document = DocumentUpgrader::upgrade($document, $this->registry)['document'];
        }
        $containers = [];
        foreach ((array) ($document['containers'] ?? []) as $container) {
            if (is_array($container) && empty($container['global_ref'])) {
                $containers[] = $container;
            }
        }
        return $containers;
    }

    /**
     * The Container a consuming page keeps in place of the content: same
     * label/visibility/style as the Container it replaces, no children, a live
     * reference.
     *
     * @param array<string, mixed> $container
     * @return array<string, mixed>
     */
    public static function referenceContainerFor(array $container, string $componentRef, ?string $label = null): array
    {
        $reference = CanonicalDocumentSchema::newContainer(['global_ref' => $componentRef]);
        foreach (['style', 'visibility'] as $key) {
            if (is_array($container[$key] ?? null)) {
                $reference[$key] = $container[$key];
            }
        }
        $label ??= (string) ($container['metadata']['label'] ?? '');
        if ($label !== '') {
            $reference['metadata'] = ['label' => mb_substr($label, 0, CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH, 'UTF-8')];
        }
        return $reference;
    }

    /**
     * Canonical operations that replace top-level Container `$containerId` of `$document`
     * (a live reference) with an owned copy of the referenced component's
     * content — the "detach" of a global reference. Fails closed when the
     * Container is not a reference or the component is missing in this tenant.
     *
     * @param array<string, mixed> $document the page's current working document
     * @return array{operations: list<DocumentOperation>, component: array<string, mixed>}
     */
    public function detachOperations(array $document, string $containerId): array
    {
        $this->requireTenantId();
        $found = self::topLevelContainer($document, $containerId);
        $container = $found['node'];
        $ref = $container['global_ref'] ?? null;
        if (!is_string($ref) || $ref === '') {
            throw new StudioValidationException([
                ['path' => '$.container_id', 'code' => 'not_a_global_reference', 'message' => 'This Container does not reference a global component.'],
            ]);
        }
        $component = $this->pages->findComponentByRef($ref);
        if ($component === null) {
            throw new StudioNotFoundException('The referenced global component was not found in the active tenant.', ['global_ref' => $ref]);
        }

        $operations = [new DocumentOperation(DocumentOperation::OP_REMOVE_BLOCK, ['block_id' => $containerId])];
        $copies = DocumentCopier::copyBlocks($this->contentContainers($component));
        if ($copies === []) {
            // An empty component detaches to one empty, owned Container.
            $copies = [DocumentCopier::copyBlock(array_merge($container, ['children' => []]))];
        }
        foreach ($copies as $i => $copy) {
            $copy['visibility'] = is_array($container['visibility'] ?? null) ? $container['visibility'] : CanonicalDocumentSchema::defaultVisibility();
            if ((string) ($copy['metadata']['label'] ?? '') === '') {
                $copy['metadata']['label'] = mb_substr((string) ($container['metadata']['label'] ?? $component['title']), 0, CanonicalDocumentSchema::BLOCK_LABEL_MAX_LENGTH, 'UTF-8');
            }
            $operations[] = new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => null, 'index' => $found['index'] + $i, 'block' => $copy]);
        }
        return ['operations' => $operations, 'component' => $component];
    }

    /**
     * The documents and operations for "make this Container a global component":
     * the new component's first document (an owned copy of the Container) and
     * the operations that replace it in the source page by a live
     * reference. The uuid is only known once the component page exists, so
     * the reference operations are built by `referenceOperations()`.
     *
     * @param array<string, mixed> $document
     * @return array{container: array<string, mixed>, index: int, component_document: array<string, mixed>}
     */
    public function extractionFor(array $document, string $containerId, string $componentTitle): array
    {
        $found = self::topLevelContainer($document, $containerId);
        $container = $found['node'];
        if (!empty($container['global_ref'])) {
            throw new StudioValidationException([
                ['path' => '$.container_id', 'code' => 'already_a_global_reference', 'message' => 'This Container already references a global component.'],
            ]);
        }
        $componentDocument = CanonicalDocumentSchema::emptyDocument(CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'default', $componentTitle);
        $componentDocument['containers'] = [DocumentCopier::copyBlock($container)];
        return ['container' => $container, 'index' => (int) $found['index'], 'component_document' => $componentDocument];
    }

    /**
     * @param array<string, mixed> $container the Container being replaced
     * @return list<DocumentOperation>
     */
    public static function referenceOperations(array $container, int $index, string $componentRef): array
    {
        return [
            new DocumentOperation(DocumentOperation::OP_REMOVE_BLOCK, ['block_id' => (string) $container['id']]),
            new DocumentOperation(DocumentOperation::OP_INSERT_BLOCK, ['parent_id' => null, 'index' => $index, 'block' => self::referenceContainerFor($container, $componentRef)]),
        ];
    }

    /**
     * A Container at the top level of the document, the only place a global reference can sit.
     *
     * @param array<string, mixed> $document
     * @return array{node: array<string, mixed>, index: int}
     */
    private static function topLevelContainer(array $document, string $containerId): array
    {
        $found = DocumentCopier::findNode($document, $containerId);
        if ($found === null || ($found['node']['id'] ?? null) !== ($document['containers'][$found['index']]['id'] ?? null)) {
            throw new StudioNotFoundException("Container '{$containerId}' was not found at the top level of the page.", ['container_id' => $containerId]);
        }
        return $found;
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }
}
