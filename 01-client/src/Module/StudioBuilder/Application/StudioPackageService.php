<?php
/**
 * Kohevo Studio (studio-builder) — Phase 8A JSON package export / import.
 *
 * An application-layer helper OWNED by StudioApplicationService: it is only
 * ever called from `StudioApplicationService::exportPackage()` /
 * `importPackage()`, after those have run the full enforcement pipeline
 * (tenant -> authentication -> entitlement -> permission). It performs no
 * authorization or audit of its own, never opens a transaction, and never
 * writes a `studiobuilder_*` table itself — every write goes through the
 * existing canonical services:
 *
 *   tokens              StudioThemeService::saveTokens()
 *   global components   StudioPageAddressService::createPage(section_preset, kind 'import')
 *                       + StudioRevisionService::createDraftRevision(kind 'import')
 *   templates           StudioTemplateService::saveTemplate()
 *   pages (create)      StudioPageAddressService::createPage(kind 'import')
 *                       + StudioRevisionService::createDraftRevision(kind 'import')
 *   pages (replace)     StudioRevisionService::createDraftRevision(kind 'import', expected_revision_id)
 *
 * Nothing here publishes, touches `published_revision_id`, or makes any
 * network request. Every document is re-validated by `ValidatedDocument::from()`
 * (DocumentValidator + DocumentNormalizer) both in the plan and again at
 * commit, with the real (tenant-scoped) cross-reference checks.
 *
 * Import rules (see architecture/KOHEVO-STUDIO-PHASE8A-PACKAGES.md):
 *   - identities: new pages and components get fresh uuids from createPage();
 *     a component's `source_ref` is package-local and is rewritten through an
 *     explicit source -> target map; section/block/child ids are re-minted.
 *   - media: never a binary, never a foreign id. A reference resolves through
 *     (1) the caller's explicit `media_map` (source path -> target media id,
 *     verified to be an image of THIS tenant), else (2) a tenant-local managed
 *     media row with the same /uploads/ path and mime, else it is downgraded
 *     (PackageDocumentMapper) with an `unresolved_media` warning.
 *   - global references: to a component in the package, or through the
 *     caller's explicit `component_map` (verified tenant-local), else the
 *     section becomes an empty owned section (`unresolved_global_component`).
 *     Imported components stay DRAFTS; pages referencing them render the
 *     reference inert until a person publishes the component.
 *   - templates: kept key only when free in this tenant (collision = error;
 *     a system template = `system_template_protected`); studio-builder.admin.
 *   - tokens: skipped unless explicitly included; then studio-builder.tokens,
 *     and they apply LIVE (tokens have no draft state) — reported as such.
 *   - collisions: create mode never overwrites (existing slug = error);
 *     reserved public routes are refused for public page types.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Exception\StudioConcurrencyException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Package\PackageDocumentMapper;
use Slate\Module\StudioBuilder\Package\StudioImportReport;
use Slate\Module\StudioBuilder\Package\StudioPackageFormat;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Services\Media\Media;

final class StudioPackageService
{
    public const MODE_CREATE  = 'create';
    public const MODE_REPLACE = 'replace_draft';
    public const MODES = [self::MODE_CREATE, self::MODE_REPLACE];

    public const MAX_MAP_ENTRIES = 250;

    private \Closure $mediaById;
    private \Closure $mediaByPath;

    public function __construct(
        private readonly PageRepository $pageRepo,
        private readonly RevisionRepository $revisionRepo,
        private readonly TemplateRepository $templateRepo,
        private readonly StudioPageAddressService $pages,
        private readonly StudioRevisionService $revisions,
        private readonly StudioTemplateService $templates,
        private readonly ?StudioThemeService $themes,
        private readonly BlockRegistry $registry,
        private readonly StudioReservedRoutes $reserved,
        ?\Closure $mediaById = null,
        ?\Closure $mediaByPath = null,
    ) {
        // Tenant-scoped media lookups (the platform Media service filters by the current tenant).
        $this->mediaById   = $mediaById ?? static fn(int $id): ?array => class_exists(Media::class) ? Media::get($id) : null;
        $this->mediaByPath = $mediaByPath ?? static fn(string $path): ?array => class_exists(Media::class) ? Media::findByPath($path) : null;
    }

    // ── Export ──────────────────────────────────────────────────────────────

    /**
     * A package holding one Studio page's CURRENT working document (the page
     * as the builder shows it), plus — only when asked — the Global Components
     * it references (their published revision, else their draft), the
     * non-default template it names, and the stored token overrides of its
     * token group. Media ids become package-local keys with {path, mime}
     * descriptors; no binary, database id, uuid of the page, revision id, user
     * id or tenant id is included.
     *
     * @return array{package: array<string, mixed>, package_hash: string, filename: string, summary: array<string, int>}
     */
    public function exportPage(int $pageId, bool $withComponents, bool $withTemplate, bool $withTokens): array
    {
        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $document = $this->workingDocument($page);
        $isComponent = (string) $page['page_type'] === CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE;

        $items = [];
        $items[] = $isComponent
            ? $this->componentItem('component-1', $page, $document)
            : $this->pageItem('page', $page, $document);

        if ($withComponents && !$isComponent) {
            $n = 1;
            foreach (PackageDocumentMapper::globalRefs($document) as $ref) {
                $component = $this->pageRepo->findComponentByRef($ref);
                if ($component === null) {
                    continue; // a dangling reference is exported as-is and reported on import
                }
                $items[] = $this->componentItem('component-' . $n++, $component, $this->componentDocument($component));
            }
        }
        $templateKey = (string) ($document['template_key'] ?? 'default');
        if ($withTemplate && $templateKey !== 'default') {
            $row = $this->templateRepo->findByKey($templateKey);
            if ($row !== null) {
                $items[] = $this->templateItem('template', $row);
            }
        }
        if ($withTokens && $this->themes !== null) {
            $group = (string) ($document['settings']['token_group'] ?? 'default');
            $stored = [];
            foreach ($this->themes->layers($group)['tokens'] as $token) {
                if ($token['stored'] !== null) {
                    $stored[(string) $token['ref']] = (string) $token['stored'];
                }
            }
            if ($stored !== []) {
                $items[] = self::withHash(['kind' => StudioPackageFormat::KIND_TOKENS, 'key' => 'tokens', 'token_group' => $group, 'tokens' => $stored]);
            }
        }

        $package = StudioPackageFormat::sortKeys([
            'package_format'  => StudioPackageFormat::FORMAT,
            'package_version' => StudioPackageFormat::VERSION,
            'exported_at'     => gmdate('Y-m-d\TH:i:s\Z'),
            'items'           => $items,
        ]);
        $summary = ['items' => count($items), 'pages' => 0, 'global_components' => 0, 'templates' => 0, 'tokens' => 0, 'media' => 0];
        foreach ($items as $item) {
            $bucket = ['page' => 'pages', 'global_component' => 'global_components', 'template' => 'templates', 'tokens' => 'tokens'][$item['kind']];
            $summary[$bucket]++;
            $summary['media'] += count($item['media'] ?? []);
        }
        return [
            'package'      => $package,
            'package_hash' => StudioPackageFormat::packageHash($package),
            'filename'     => 'kohevo-studio-' . (string) $page['slug'] . '.json',
            'summary'      => $summary,
        ];
    }

    /** @param array<string, mixed> $page @param array<string, mixed> $document @return array<string, mixed> */
    private function pageItem(string $key, array $page, array $document): array
    {
        [$doc, $media] = $this->exportMedia($document);
        return self::withHash([
            'kind' => StudioPackageFormat::KIND_PAGE, 'key' => $key,
            'title' => (string) $page['title'], 'slug' => (string) $page['slug'],
            'page_type' => (string) $page['page_type'], 'route_mode' => (string) $page['route_mode'],
            'document' => $doc, 'media' => $media,
        ]);
    }

    /** @param array<string, mixed> $component @param array<string, mixed> $document @return array<string, mixed> */
    private function componentItem(string $key, array $component, array $document): array
    {
        [$doc, $media] = $this->exportMedia($document);
        return self::withHash([
            'kind' => StudioPackageFormat::KIND_COMPONENT, 'key' => $key,
            'source_ref' => (string) $component['uuid'],
            'title' => (string) $component['title'], 'slug' => (string) $component['slug'],
            'document' => $doc, 'media' => $media,
        ]);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function templateItem(string $key, array $row): array
    {
        [$doc, $media] = $this->exportMedia(CanonicalJson::decode((string) $row['document_json']));
        return self::withHash([
            'kind' => StudioPackageFormat::KIND_TEMPLATE, 'key' => $key,
            'template_key' => (string) $row['template_key'], 'template_type' => (string) $row['template_type'],
            'category' => (string) ($row['category'] ?? 'general'), 'name' => (string) $row['name'],
            'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
            'document' => $doc, 'media' => $media,
        ]);
    }

    /**
     * Source media ids -> package-local keys 1..n with {path, mime} descriptors.
     *
     * @param array<string, mixed> $document
     * @return array{0: array<string, mixed>, 1: list<array{key: int, path: string, mime: string}>}
     */
    private function exportMedia(array $document): array
    {
        $keys  = [];
        $media = [];
        foreach (PackageDocumentMapper::mediaIds($document, $this->registry) as $i => $id) {
            $keys[$id] = $i + 1;
            $row  = ($this->mediaById)($id);
            $path = is_array($row) ? (string) ($row['path'] ?? '') : '';
            $mime = is_array($row) ? (string) ($row['mime'] ?? '') : '';
            $media[] = [
                'key'  => $i + 1,
                'mime' => preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', $mime) === 1 ? $mime : '',
                'path' => $path !== '' && Media::isManagedPath($path) ? $path : '',
            ];
        }
        $mapped = PackageDocumentMapper::rewriteMedia($document, $this->registry, static fn(int $id): ?int => $keys[$id] ?? null);
        return [$mapped['document'], $media];
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private static function withHash(array $item): array
    {
        $item = StudioPackageFormat::sortKeys($item);
        $item['content_hash'] = StudioPackageFormat::itemHash($item);
        ksort($item, SORT_STRING);
        return $item;
    }

    // ── Import: plan (the dry run, and the first half of every commit) ──────

    /**
     * Validate and plan an import WITHOUT writing anything. For a commit
     * (`$dryRun = false`) the same plan is built with real id minting and a
     * stale `expected_revision_id` is a StudioConcurrencyException (409).
     *
     * @param array{mode?: string, target_page_id?: ?int, expected_revision_id?: ?int, media_map?: array<string, int>, component_map?: array<string, string>, include_tokens?: bool} $options
     * @param callable(string): bool $can the actor's permission check
     * @param array<string, mixed> $validationOptions the application layer's tenant-scoped validation callbacks
     * @param ?StudioImportReport $report a report that already carries a converted source's findings
     *        (Phase 8B HTML/CSS: the package was synthesized by the converter); null = a new report
     * @return array{report: StudioImportReport, work: list<array<string, mixed>>}
     */
    public function plan(mixed $package, array $options, callable $can, array $validationOptions, bool $dryRun, ?StudioImportReport $report = null): array
    {
        $mode = (string) ($options['mode'] ?? self::MODE_CREATE);
        $packageHash = is_array($package) ? StudioPackageFormat::packageHash($package) : hash('sha256', '');
        $report ??= new StudioImportReport(in_array($mode, self::MODES, true) ? $mode : self::MODE_CREATE, $dryRun, $packageHash);

        foreach (StudioPackageFormat::validate($package) as $issue) {
            $report->addIssue($issue);
        }
        if (!in_array($mode, self::MODES, true)) {
            $report->error('invalid_package', null, '$.mode', 'mode must be create or replace_draft.');
        }
        if ($report->hasErrors()) {
            return ['report' => $report, 'work' => []];
        }

        /** @var list<array<string, mixed>> $items */
        $items = $package['items'];
        $byKind = [StudioPackageFormat::KIND_TOKENS => [], StudioPackageFormat::KIND_COMPONENT => [], StudioPackageFormat::KIND_TEMPLATE => [], StudioPackageFormat::KIND_PAGE => []];
        foreach ($items as $i => $item) {
            $report->setItemOrder((string) $item['key'], $i);
            $report->countItem((string) $item['kind']);
            $byKind[(string) $item['kind']][] = ['index' => $i, 'item' => $item];
        }

        [$mintSection, $mintBlock] = $dryRun ? PackageDocumentMapper::deterministicMinters($packageHash) : [null, null];
        $work = [];

        // ── Replace-mode target (tenant-scoped, explicit page id + expected revision) ──
        $target = null;
        if ($mode === self::MODE_REPLACE) {
            $target = $this->resolveReplaceTarget($byKind[StudioPackageFormat::KIND_PAGE], $options, $report, $dryRun);
        } elseif (($options['target_page_id'] ?? null) !== null) {
            $report->error('invalid_package', null, '$.target_page_id', 'target_page_id is only used by replace_draft.');
        }

        // ── Explicit component map (source uuid -> THIS tenant's component) ──
        $componentMap = [];
        foreach ((array) ($options['component_map'] ?? []) as $source => $targetRef) {
            $source = (string) $source;
            if (!is_string($targetRef) || $this->pageRepo->findComponentByRef($targetRef) === null) {
                $report->error('unresolved_global_component', null, '$.component_map.' . substr($source, 0, 36), 'The mapped target is not a global component of this site.');
                continue;
            }
            $componentMap[$source] = $targetRef;
        }

        // ── Components in the package: planned identities (placeholders until created) ──
        $planned = [];
        $componentSlugs = [];
        foreach ($byKind[StudioPackageFormat::KIND_COMPONENT] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $source = (string) $item['source_ref'];
            if (isset($planned[$source]) || isset($componentMap[$source])) {
                $report->error('invalid_package', $key, "\$.items[{$i}].source_ref", 'A component source_ref may appear once, and not also in component_map.');
                continue;
            }
            $planned[$source] = PackageDocumentMapper::placeholderUuid($packageHash . '|' . $key);
            $slug = (string) $item['slug'];
            if (isset($componentSlugs[$slug]) || $this->pageRepo->findBySlug($slug, CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE) !== null) {
                $report->error('route_collision', $key, "\$.items[{$i}].slug", "A global component with slug '{$slug}' already exists; nothing is overwritten.");
            }
            $componentSlugs[$slug] = true;
        }
        $plannedRefs = array_values($planned);
        $refResolver = static fn(string $ref): ?string => $planned[$ref] ?? $componentMap[$ref] ?? null;

        // ── Templates: permission, collisions ──
        $packageTemplates = [];
        foreach ($byKind[StudioPackageFormat::KIND_TEMPLATE] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $tk = (string) $item['template_key'];
            if (!$can(StudioPermissions::ADMIN)) {
                $report->error('permission_denied', $key, "\$.items[{$i}]", 'Importing templates requires studio-builder.admin (the existing template boundary).');
            }
            if (isset($packageTemplates[$tk])) {
                $report->error('invalid_package', $key, "\$.items[{$i}].template_key", 'A template_key may appear once per package.');
                continue;
            }
            $packageTemplates[$tk] = true;
            $existing = $this->templateRepo->findByKey($tk);
            if ($existing !== null) {
                (bool) $existing['is_system']
                    ? $report->error('system_template_protected', $key, "\$.items[{$i}].template_key", "'{$tk}' is a system template and can never be overwritten.")
                    : $report->error('template_collision', $key, "\$.items[{$i}].template_key", "A template '{$tk}' already exists here; nothing is overwritten.");
            }
        }

        $options2 = $validationOptions;
        $baseTemplate = $validationOptions['template_exists'] ?? null;
        $basePartial  = $validationOptions['partial_exists'] ?? null;
        $options2['template_exists'] = static fn(string $k): bool => isset($packageTemplates[$k]) || (is_callable($baseTemplate) && $baseTemplate($k));
        $options2['partial_exists']  = static fn(string $r): bool => in_array($r, $plannedRefs, true) || (is_callable($basePartial) && $basePartial($r));

        $stats = ['media' => ['mapped' => 0, 'resolved_locally' => 0, 'unresolved' => 0], 'components' => [], 'templates' => [], 'modules' => [], 'token_groups' => []];
        $componentUse = [];

        // ── Tokens (explicit opt-in; live) ──
        $groups = [];
        foreach ($byKind[StudioPackageFormat::KIND_TOKENS] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $group = (string) $item['token_group'];
            if (($options['include_tokens'] ?? false) !== true) {
                $report->warning('tokens_skipped', $key, "\$.items[{$i}]", 'Design tokens were not imported (enable "include tokens" to replace this site\'s token overrides).');
                continue;
            }
            if (!$can(StudioPermissions::TOKENS)) {
                $report->error('permission_denied', $key, "\$.items[{$i}]", 'Importing design tokens requires studio-builder.tokens.');
                continue;
            }
            if (isset($groups[$group]) || $this->themes === null) {
                $report->error('invalid_package', $key, "\$.items[{$i}].token_group", 'A token group may appear once per package.');
                continue;
            }
            $groups[$group] = true;
            try {
                $clean = $this->themes->validateTokens($group, (array) $item['tokens']);
            } catch (StudioValidationException $e) {
                foreach ($e->errors() as $err) {
                    $report->error('invalid_tokens', $key, "\$.items[{$i}]" . substr((string) ($err['path'] ?? '$'), 1), (string) ($err['message'] ?? ''), (string) ($err['code'] ?? ''));
                }
                continue;
            }
            $report->warning('tokens_replace_live', $key, "\$.items[{$i}]", "Design tokens have no draft: importing replaces the '{$group}' token overrides and changes the live site immediately.");
            $report->plan(['action' => 'replace_token_group', 'item' => $key, 'token_group' => $group, 'tokens_count' => count($clean), 'publishes' => false]);
            $work[] = ['kind' => StudioPackageFormat::KIND_TOKENS, 'item' => $key, 'token_group' => $group, 'tokens' => $clean];
            $stats['token_groups'][] = $group;
        }

        // ── Components ──
        foreach ($byKind[StudioPackageFormat::KIND_COMPONENT] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $source = (string) $item['source_ref'];
            if (!isset($planned[$source])) {
                continue;
            }
            if (PackageDocumentMapper::globalRefs((array) $item['document']) !== []) {
                $report->error('invalid_document', $key, "\$.items[{$i}].document.sections", 'A global component cannot contain another global component (references are one level deep).', 'global_ref_not_allowed');
                continue;
            }
            // A component names no package-only template: its template_key must already exist here (else 'default').
            $doc = $this->prepareDocument($item, $i, CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, $report, $options, $refResolver, $validationOptions, $mintSection, $mintBlock, $stats, $componentUse);
            if ($doc === null) {
                continue;
            }
            $report->warning('component_unpublished', $key, "\$.items[{$i}]", 'Imported global components are created as unpublished drafts; pages show them only after a person publishes the component.');
            $report->plan(['action' => 'create_global_component', 'item' => $key, 'slug' => (string) $item['slug'], 'title' => (string) $item['title'], 'revision_kind' => 'import', 'publishes' => false]);
            $work[] = ['kind' => StudioPackageFormat::KIND_COMPONENT, 'item' => $key, 'title' => (string) $item['title'], 'slug' => (string) $item['slug'], 'placeholder' => $planned[$source], 'source_ref' => $source, 'document' => $doc];
        }

        // ── Templates ──
        foreach ($byKind[StudioPackageFormat::KIND_TEMPLATE] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $doc = $this->prepareDocument($item, $i, null, $report, $options, $refResolver, $options2, $mintSection, $mintBlock, $stats, $componentUse, $packageTemplates);
            if ($doc === null) {
                continue;
            }
            try {
                $clean = $this->templates->validateTemplate((string) $item['template_key'], (string) $item['template_type'], (string) $item['category'], (string) $item['name'], $item['description'] === null ? null : (string) $item['description'], $doc, $options2);
            } catch (StudioValidationException $e) {
                $report->validatorErrors($key, "\$.items[{$i}]", $e->errors());
                continue;
            }
            $report->preview($key, StudioPackageFormat::KIND_TEMPLATE, $clean['document']);
            $this->collectModules($clean['document'], $stats);
            $report->plan(['action' => 'create_template', 'item' => $key, 'template_key' => (string) $item['template_key'], 'template_type' => (string) $item['template_type'], 'publishes' => false]);
            $work[] = ['kind' => StudioPackageFormat::KIND_TEMPLATE, 'item' => $key, 'template_key' => (string) $item['template_key'], 'template_type' => (string) $item['template_type'], 'category' => $clean['category'], 'name' => $clean['name'], 'description' => $clean['description'], 'document' => $clean['document']];
        }

        // ── Pages ──
        $pageSlugs = [];
        foreach ($byKind[StudioPackageFormat::KIND_PAGE] as ['index' => $i, 'item' => $item]) {
            $key = (string) $item['key'];
            $pageType = $target !== null ? (string) $target['page_type'] : (string) $item['page_type'];
            if ($mode === self::MODE_CREATE) {
                $slug = (string) $item['slug'];
                $sig = $pageType . '|' . $slug;
                if (in_array((string) $item['page_type'], StudioRenderService::PUBLIC_PAGE_TYPES, true) && $this->reserved->isReserved($slug)) {
                    $report->error('reserved_route', $key, "\$.items[{$i}].slug", "'/{$slug}' is reserved by the platform and cannot be a Studio page address.");
                }
                if (isset($pageSlugs[$sig]) || $this->pageRepo->findBySlug($slug, $pageType) !== null) {
                    $report->error('route_collision', $key, "\$.items[{$i}].slug", "A {$pageType} with slug '{$slug}' already exists; import never overwrites in create mode.");
                }
                $pageSlugs[$sig] = true;
            }
            $doc = $this->prepareDocument($item, $i, $pageType, $report, $options, $refResolver, $options2, $mintSection, $mintBlock, $stats, $componentUse, $packageTemplates);
            if ($doc === null) {
                continue;
            }
            if ($mode === self::MODE_REPLACE && $target !== null) {
                $report->plan(['action' => 'replace_draft', 'item' => $key, 'target_page_id' => (int) $target['id'], 'expected_revision_id' => (int) ($options['expected_revision_id'] ?? 0), 'revision_kind' => 'import', 'publishes' => false]);
                $work[] = ['kind' => StudioPackageFormat::KIND_PAGE, 'item' => $key, 'mode' => self::MODE_REPLACE, 'page_id' => (int) $target['id'], 'expected_revision_id' => (int) ($options['expected_revision_id'] ?? 0), 'document' => $doc];
            } else {
                $report->plan(['action' => 'create_page', 'item' => $key, 'slug' => (string) $item['slug'], 'page_type' => $pageType, 'route_mode' => (string) $item['route_mode'], 'revision_kind' => 'import', 'publishes' => false]);
                $work[] = ['kind' => StudioPackageFormat::KIND_PAGE, 'item' => $key, 'mode' => self::MODE_CREATE, 'title' => (string) $item['title'], 'slug' => (string) $item['slug'], 'page_type' => $pageType, 'route_mode' => (string) $item['route_mode'], 'document' => $doc];
            }
        }

        foreach ($componentUse as $source => $users) {
            if (isset($planned[$source])) {
                $stats['components'][] = ['source_ref' => $source, 'resolution' => 'package', 'used_by' => array_values(array_unique($users))];
            } elseif (isset($componentMap[$source])) {
                $stats['components'][] = ['source_ref' => $source, 'resolution' => 'mapped', 'used_by' => array_values(array_unique($users))];
            } else {
                $stats['components'][] = ['source_ref' => $source, 'resolution' => 'unresolved', 'used_by' => array_values(array_unique($users))];
            }
        }
        usort($stats['components'], static fn(array $a, array $b): int => strcmp($a['source_ref'], $b['source_ref']));
        ksort($stats['templates'], SORT_STRING);
        $modules = array_values(array_unique($stats['modules']));
        sort($modules, SORT_STRING);
        $groupsOut = array_values(array_unique($stats['token_groups']));
        sort($groupsOut, SORT_STRING);
        $report->setDependencies([
            'global_components' => $stats['components'],
            'media'             => $stats['media'],
            'modules'           => $modules,
            'templates'         => array_map(static fn(string $k, string $r): array => ['template_key' => $k, 'resolution' => $r], array_keys($stats['templates']), array_values($stats['templates'])),
            'token_groups'      => $groupsOut,
        ]);

        return ['report' => $report, 'work' => $report->hasErrors() ? [] : $work];
    }

    /**
     * @param list<array{index: int, item: array<string, mixed>}> $pageItems
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    private function resolveReplaceTarget(array $pageItems, array $options, StudioImportReport $report, bool $dryRun): ?array
    {
        if (count($pageItems) !== 1) {
            $report->error('invalid_package', null, '$.items', 'replace_draft imports exactly one page item into the target page.');
        }
        $targetId = $options['target_page_id'] ?? null;
        // Tenant-scoped: another tenant's page id is simply "not found".
        $row = is_int($targetId) && $targetId > 0 ? $this->pageRepo->find($targetId) : null;
        if ($row === null || ($row['status'] ?? null) === 'archived' || !in_array((string) $row['page_type'], StudioPackageFormat::PAGE_ITEM_TYPES, true)) {
            $report->error('target_not_found', null, '$.target_page_id', 'The target page was not found on this site.');
            return null;
        }
        $expected = $options['expected_revision_id'] ?? null;
        if (!is_int($expected) || $expected <= 0) {
            $report->error('invalid_package', null, '$.expected_revision_id', 'replace_draft requires the expected_revision_id of the draft being replaced.');
            return null;
        }
        $current = isset($row['active_draft_revision_id']) ? (int) $row['active_draft_revision_id'] : 0;
        if ($current !== $expected) {
            if (!$dryRun) {
                throw new StudioConcurrencyException(
                    'The target page draft changed since it was loaded.',
                    ['page_id' => (int) $row['id'], 'current_revision_id' => $current > 0 ? $current : null, 'expected_revision_id' => $expected],
                );
            }
            $report->error('concurrency_conflict', null, '$.expected_revision_id', 'The target page was changed since you loaded it. Reload and analyse again.');
        }
        foreach ($pageItems as ['index' => $i, 'item' => $item]) {
            if ((string) $item['page_type'] !== (string) $row['page_type']) {
                $report->error('page_type_mismatch', (string) $item['key'], "\$.items[{$i}].page_type", "The package holds a {$item['page_type']}; the target page is a {$row['page_type']}.");
            }
        }
        return $row;
    }

    /**
     * Map one item's document into this tenant: global references, template
     * key, media, fresh ids — then full canonical validation/normalization.
     * Returns the normalized document (with planned component placeholders),
     * or null when it cannot be imported (issues recorded).
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $options
     * @param array<string, mixed> $validationOptions
     * @param array<string, mixed> $stats
     * @param array<string, list<string>> $componentUse
     * @param array<string, bool> $packageTemplates
     * @return array<string, mixed>|null
     */
    private function prepareDocument(
        array $item,
        int $i,
        ?string $expectedType,
        StudioImportReport $report,
        array $options,
        callable $refResolver,
        array $validationOptions,
        ?callable $mintSection,
        ?callable $mintBlock,
        array &$stats,
        array &$componentUse,
        array $packageTemplates = [],
    ): ?array {
        $key  = (string) $item['key'];
        $kind = (string) $item['kind'];
        $base = "\$.items[{$i}].document";
        $doc  = (array) $item['document'];

        if ($expectedType !== null && ($doc['document_type'] ?? null) !== $expectedType) {
            $report->error('page_type_mismatch', $key, "{$base}.document_type", "The document must be a '{$expectedType}' document.");
            return null;
        }

        // Global references -> package components / explicit map, else an empty owned section.
        foreach (PackageDocumentMapper::globalRefs($doc) as $ref) {
            $componentUse[$ref][] = $key;
        }
        $refs = PackageDocumentMapper::rewriteGlobalRefs($doc, $refResolver);
        $doc = $refs['document'];
        foreach ($refs['unresolved'] as $u) {
            $report->warning('unresolved_global_component', $key, $base . substr($u['path'], 1), 'This section referenced a global component that is not in the package or mapped; it was imported as an empty section.');
        }

        // Template key: a package template, an existing template of this site, else 'default'.
        $tk = $doc['template_key'] ?? 'default';
        if (is_string($tk) && $tk !== 'default') {
            $inPackage = isset($packageTemplates[$tk]) && $kind !== StudioPackageFormat::KIND_COMPONENT;
            if ($inPackage) {
                $stats['templates'][$tk] = 'package';
            } elseif ($this->templateRepo->findByKey($tk) !== null) {
                $stats['templates'][$tk] = 'existing';
            } else {
                $stats['templates'][$tk] = 'unresolved';
                $report->warning('unresolved_template', $key, "{$base}.template_key", "Template '{$tk}' is not available on this site; the document uses the default template.");
                $doc['template_key'] = 'default';
            }
        }

        // Media: explicit map -> tenant-local managed media -> downgrade.
        $descriptors = [];
        foreach ((array) $item['media'] as $descriptor) {
            $descriptors[(int) $descriptor['key']] = $descriptor;
        }
        $mediaMap = (array) ($options['media_map'] ?? []);
        $erroredKeys = [];
        $resolve = function (int $mediaKey) use ($descriptors, $mediaMap, $key, $i, $report, &$erroredKeys, &$stats): ?int {
            $descriptor = $descriptors[$mediaKey] ?? null;
            if ($descriptor === null) {
                $erroredKeys[$mediaKey] = true;
                $report->error('invalid_package', $key, "\$.items[{$i}].media", "The document references media key {$mediaKey}, which has no media descriptor.");
                return null;
            }
            $path = (string) $descriptor['path'];
            if ($path !== '' && array_key_exists($path, $mediaMap)) {
                $row = ($this->mediaById)((int) $mediaMap[$path]);
                if (is_array($row) && ($row['kind'] ?? '') === 'image' && (int) ($row['id'] ?? 0) === (int) $mediaMap[$path]) {
                    $stats['media']['mapped']++;
                    return (int) $row['id'];
                }
                $erroredKeys[$mediaKey] = true;
                $report->error('unresolved_media', $key, '$.media_map', "The media mapped for {$path} is not an image of this site.");
                return null;
            }
            if ($path !== '' && !StudioPackageFormat::isExternalReference($path) && Media::isManagedPath($path)) {
                $row = ($this->mediaByPath)($path);
                $mime = (string) $descriptor['mime'];
                if (is_array($row) && ($row['kind'] ?? '') === 'image' && (string) ($row['path'] ?? '') === $path && ($mime === '' || (string) ($row['mime'] ?? '') === $mime)) {
                    $stats['media']['resolved_locally']++;
                    return (int) $row['id'];
                }
            }
            $stats['media']['unresolved']++;
            return null;
        };
        $media = PackageDocumentMapper::rewriteMedia($doc, $this->registry, $resolve);
        $doc = $media['document'];
        foreach ($media['unresolved'] as $u) {
            if (isset($erroredKeys[$u['media_key']])) {
                continue;
            }
            $what = $u['action'] === 'omit_block' ? 'the image block was left out' : 'the image was removed';
            $report->warning('unresolved_media', $key, $base . substr($u['path'], 1), "This image is not available on this site and no media mapping was given; {$what}. Binary files are never transferred.");
        }

        // Fresh ids everywhere (never trust the package's ids — they may even collide).
        $doc = PackageDocumentMapper::remintIds($doc, $mintSection, $mintBlock);

        if ($kind === StudioPackageFormat::KIND_TEMPLATE) {
            return $doc; // validated by StudioTemplateService::validateTemplate() (document + preset shape rules)
        }
        try {
            $normalized = ValidatedDocument::from($doc, $this->registry, $validationOptions)->toArray();
        } catch (StudioValidationException $e) {
            $report->validatorErrors($key, $base, $e->errors());
            return null;
        }
        $report->preview($key, $kind, $normalized);
        $this->collectModules($normalized, $stats);
        return $normalized;
    }

    /** @param array<string, mixed> $document @param array<string, mixed> $stats */
    private function collectModules(array $document, array &$stats): void
    {
        try {
            foreach (DependencyExtractor::extract($document, $this->registry) as $record) {
                if ($record->dependencyType === 'module') {
                    $stats['modules'][] = $record->dependencyKey;
                }
            }
        } catch (\Throwable $ignored) {
        }
        $group = $document['settings']['token_group'] ?? null;
        if (is_string($group) && $group !== '') {
            $stats['token_groups'][] = $group;
        }
    }

    // ── Import: commit (called by the application layer inside ITS transaction) ──

    /**
     * Persist a plan through the canonical services, in dependency order:
     * tokens -> global components (fresh uuids) -> templates -> pages. Every
     * document is re-validated with the REAL tenant-scoped checks after the
     * placeholder component refs are replaced by the created uuids. Draft
     * revisions only (kind `import`); nothing is published.
     *
     * @param list<array<string, mixed>> $work
     * @param array<string, mixed> $validationOptions
     * @return array{pages: list<array<string, mixed>>, global_components: list<array<string, mixed>>, templates: list<array<string, mixed>>, tokens: list<array<string, mixed>>, target: ?array<string, mixed>}
     */
    public function commit(array $work, int $actorId, array $validationOptions, string $revisionKind, string $summary): array
    {
        $out = ['pages' => [], 'global_components' => [], 'templates' => [], 'tokens' => [], 'target' => null];
        $refMap = [];
        $order = [StudioPackageFormat::KIND_TOKENS, StudioPackageFormat::KIND_COMPONENT, StudioPackageFormat::KIND_TEMPLATE, StudioPackageFormat::KIND_PAGE];

        foreach ($order as $kind) {
            foreach ($work as $unit) {
                if ($unit['kind'] !== $kind) {
                    continue;
                }
                if ($kind === StudioPackageFormat::KIND_TOKENS) {
                    $this->themes?->saveTokens((string) $unit['token_group'], (array) $unit['tokens'], $actorId);
                    $out['tokens'][] = ['item' => $unit['item'], 'token_group' => $unit['token_group']];
                    continue;
                }
                if ($kind === StudioPackageFormat::KIND_COMPONENT) {
                    $created = $this->pages->createPage((string) $unit['title'], (string) $unit['slug'], CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'standalone', $actorId, $validationOptions, $revisionKind);
                    $rev = $this->revisions->createDraftRevision(
                        (int) $created['page']['id'],
                        ValidatedDocument::from((array) $unit['document'], $this->registry, $validationOptions),
                        (int) $created['revision']['id'],
                        $actorId,
                        $revisionKind,
                        $summary,
                    );
                    $refMap[(string) $unit['placeholder']] = (string) $created['page']['uuid'];
                    $out['global_components'][] = ['item' => $unit['item'], 'page_id' => (int) $created['page']['id'], 'ref' => (string) $created['page']['uuid'], 'slug' => (string) $unit['slug'], 'revision_id' => (int) ($rev['revision']['id'] ?? 0), 'published' => false];
                    continue;
                }
                $document = self::replaceRefs((array) $unit['document'], $refMap);
                if ($kind === StudioPackageFormat::KIND_TEMPLATE) {
                    if ($this->templateRepo->findByKey((string) $unit['template_key']) !== null) {
                        throw new StudioValidationException([['path' => '$.template_key', 'code' => 'template_collision', 'message' => "A template '{$unit['template_key']}' was created meanwhile; nothing is overwritten."]]);
                    }
                    $this->templates->saveTemplate((string) $unit['template_key'], (string) $unit['template_type'], (string) $unit['category'], (string) $unit['name'], $unit['description'] === null ? null : (string) $unit['description'], $document, $actorId, $validationOptions);
                    $out['templates'][] = ['item' => $unit['item'], 'template_key' => $unit['template_key']];
                    continue;
                }
                $validated = ValidatedDocument::from($document, $this->registry, $validationOptions);
                if ($unit['mode'] === self::MODE_REPLACE) {
                    $rev = $this->revisions->createDraftRevision((int) $unit['page_id'], $validated, (int) $unit['expected_revision_id'], $actorId, $revisionKind, $summary);
                    $out['target'] = $rev;
                    $out['pages'][] = ['item' => $unit['item'], 'mode' => 'replaced', 'page_id' => (int) $rev['page']['id'], 'slug' => (string) $rev['page']['slug'], 'page_type' => (string) $rev['page']['page_type'], 'revision_id' => (int) ($rev['revision']['id'] ?? 0), 'published' => false];
                    continue;
                }
                $created = $this->pages->createPage((string) $unit['title'], (string) $unit['slug'], (string) $unit['page_type'], (string) $unit['route_mode'], $actorId, $validationOptions, $revisionKind);
                $rev = $this->revisions->createDraftRevision((int) $created['page']['id'], $validated, (int) $created['revision']['id'], $actorId, $revisionKind, $summary);
                $out['pages'][] = ['item' => $unit['item'], 'mode' => 'created', 'page_id' => (int) $created['page']['id'], 'slug' => (string) $unit['slug'], 'page_type' => (string) $unit['page_type'], 'revision_id' => (int) ($rev['revision']['id'] ?? 0), 'published' => false];
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $document @param array<string, string> $refMap @return array<string, mixed> */
    private static function replaceRefs(array $document, array $refMap): array
    {
        return PackageDocumentMapper::rewriteGlobalRefs($document, static fn(string $ref): ?string => $refMap[$ref] ?? $ref)['document'];
    }

    // ── Reads ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $page @return array<string, mixed> */
    private function workingDocument(array $page): array
    {
        $draftId = isset($page['active_draft_revision_id']) ? (int) $page['active_draft_revision_id'] : 0;
        $revision = $draftId > 0 ? $this->revisionRepo->findByIdForPage((int) $page['id'], $draftId) : null;
        return $revision !== null
            ? CanonicalJson::decode((string) $revision['document_json'])
            : CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);
    }

    /** A component's content as consumers see it: its published revision, else its draft. @param array<string, mixed> $component @return array<string, mixed> */
    private function componentDocument(array $component): array
    {
        $publishedId = !empty($component['published_revision_id']) ? (int) $component['published_revision_id'] : 0;
        $revision = $publishedId > 0 ? $this->revisionRepo->findByIdForPage((int) $component['id'], $publishedId) : null;
        return $revision !== null ? CanonicalJson::decode((string) $revision['document_json']) : $this->workingDocument($component);
    }
}
