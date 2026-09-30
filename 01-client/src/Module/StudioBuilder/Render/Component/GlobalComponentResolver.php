<?php
/**
 * Kohevo Studio (studio-builder) — Global Component reference resolution.
 *
 * Phase 6 live references. A Global Component is a Studio page of type
 * `section_preset` (CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE): it has
 * a stable identity (page uuid), its own immutable revisions, its own
 * publish state, the same optimistic concurrency as any page, and is edited
 * in the same builder. A page section references it by uuid in
 * `section.global_ref` and owns no content of its own.
 *
 * Resolution rules (identical to the Phase 4 chrome rules for partials):
 *
 *   - ONLY the component's PUBLISHED revision is ever rendered into another
 *     document — in public output AND in preview/editor — so a component
 *     draft can never leak into any page.
 *   - The lookup is tenant-scoped (PageRepository / RevisionRepository): a
 *     uuid from another tenant is simply "missing".
 *   - A missing or unpublished component renders as an inert fallback
 *     (nothing in public output, a labelled notice when authoring).
 *   - References are one level deep: a component document cannot itself
 *     hold a `global_ref` (enforced by DocumentValidator), so resolution never
 *     recurses and cannot cycle.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Component;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;

final class GlobalComponentResolver
{
    /**
     * Repositories are optional only so the renderer can run in the
     * dependency-free unit harness; without them every reference is missing.
     */
    public function __construct(
        private readonly ?PageRepository $pages = null,
        private readonly ?RevisionRepository $revisions = null,
    ) {}

    public function resolve(string $ref): ComponentSource
    {
        if ($this->pages === null || $this->revisions === null || preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, $ref) !== 1) {
            return ComponentSource::missing($ref);
        }
        $row = $this->pages->findComponentByRef($ref);
        if ($row === null) {
            return ComponentSource::missing($ref);
        }
        $pageId = (int) $row['id'];
        $title  = (string) $row['title'];
        if (($row['status'] ?? null) !== 'published' || empty($row['published_revision_id'])) {
            return ComponentSource::unpublished($ref, $pageId, $title);
        }
        $revision = $this->revisions->findByIdForPage($pageId, (int) $row['published_revision_id']);
        if ($revision === null) {
            return ComponentSource::unpublished($ref, $pageId, $title);
        }
        return ComponentSource::published($ref, $pageId, (int) $revision['id'], (string) $revision['document_json'], $title);
    }

    /**
     * Resolve every distinct reference a (decoded) document's sections carry.
     *
     * @param array<string, mixed> $document
     * @return array<string, ComponentSource> ref => source (sorted by ref)
     */
    public function resolveDocument(array $document): array
    {
        $out = [];
        foreach (self::referencesIn($document) as $ref) {
            $out[$ref] = $this->resolve($ref);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string> distinct, well-formed global refs in section order
     */
    public static function referencesIn(array $document): array
    {
        $refs = [];
        foreach ((array) ($document['sections'] ?? []) as $section) {
            $ref = is_array($section) ? ($section['global_ref'] ?? null) : null;
            if (is_string($ref) && $ref !== '' && preg_match(CanonicalDocumentSchema::GLOBAL_REF_PATTERN, $ref) === 1 && !in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }
        return $refs;
    }
}
