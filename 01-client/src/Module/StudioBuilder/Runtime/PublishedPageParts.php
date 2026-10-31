<?php
/**
 * Kohevo Studio (studio-builder) — the separately edited parts a published page shows.
 *
 * The header and footer, the Single template and the global components, worked out the way the compiler picks them for
 * the published revision. Only for a signed-in author (the admin bar's Edit menu), so it costs a visitor nothing and
 * nothing here reaches a public page.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Runtime;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeSource;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompiler;
use Slate\Module\StudioBuilder\Render\Component\GlobalComponentResolver;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Theme\ThemeTemplateResolver;

final class PublishedPageParts
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly RevisionRepository $revisions,
        private readonly ChromeResolver $chrome,
    ) {}

    /**
     * @return list<array{id: int, title: string, role: string}> role: header, footer, template or component;
     *         a part used twice is listed once
     */
    public function of(int $pageId, int $tenantId): array
    {
        $row = $this->pages->find($pageId);
        if ($row === null || empty($row['published_revision_id'])) {
            return [];
        }
        $revision = $this->revisions->findByIdForPage($pageId, (int) $row['published_revision_id']);
        $document = json_decode((string) ($revision['document_json'] ?? ''), true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
        if (!is_array($document)) {
            return [];
        }

        $settings = CanonicalDocumentSchema::defaultSettings();
        foreach ((array) ($document['settings'] ?? []) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $settings[$key] = $value;
            }
        }
        $page = PageAddress::fromRow($row);
        $context = $settings + ['category' => ''];
        $context['tags'] = StudioCompiler::tagsOf($document);

        $parts = [];
        $documents = [$document];
        foreach (['header', 'footer'] as $region) {
            $mode = (string) ($settings[$region . '_mode'] ?? 'inherit');
            $mode = in_array($mode, CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true) ? $mode : 'inherit';
            $source = $this->chrome->resolve($region, $mode, $page, $context);
            if ($source->kind === ChromeSource::PARTIAL && $source->pageId !== null) {
                $parts[] = $this->part($source->pageId, $region);
                $documents[] = json_decode((string) $source->documentJson, true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
            }
        }
        if (($settings['template_type'] ?? '') === '') {
            $single = $this->chrome->single($page, ThemeTemplateResolver::singleContext($settings, $tenantId, $pageId));
            if ($single !== null) {
                $parts[] = $this->part((int) $single['page']['id'], 'template');
                $documents[] = json_decode((string) $single['revision']['document_json'], true, CanonicalDocumentSchema::MAX_JSON_DEPTH);
            }
        }
        foreach ($documents as $source) {
            foreach (is_array($source) ? GlobalComponentResolver::referencesIn($source) : [] as $ref) {
                $component = $this->pages->findComponentByRef($ref);
                if ($component !== null) {
                    $parts[] = ['id' => (int) $component['id'], 'title' => (string) $component['title'], 'role' => 'component'];
                }
            }
        }

        $unique = [];
        foreach ($parts as $part) {
            if ($part !== null) {
                $unique[$part['id']] ??= $part;
            }
        }
        return array_values($unique);
    }

    /** @return ?array{id: int, title: string, role: string} */
    private function part(int $id, string $role): ?array
    {
        $row = $this->pages->find($id);
        return $row === null ? null : ['id' => $id, 'title' => (string) $row['title'], 'role' => $role];
    }
}
