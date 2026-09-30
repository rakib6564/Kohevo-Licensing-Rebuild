<?php
/**
 * Kohevo Studio (studio-builder) — Application / Command Layer.
 *
 * THE single enforcement boundary for every Studio command. Nothing else —
 * a future HTTP controller, MCP tool, AI action, or importer — may call
 * `StudioPageAddressService` / `StudioRevisionService` / `StudioTemplateService`
 * or any Studio repository directly; every mutation and every dynamic data
 * fetch goes through one of the explicit command methods below, which always
 * run, in order:
 *
 *   TenantContext -> authentication -> studio-builder entitlement
 *     -> Studio RBAC permission -> (delegated: concurrency -> validation
 *     -> normalization -> dependency extraction -> transactional
 *     persistence) -> audit
 *
 * Commands are explicit and named (`createPage`, `saveDraft`,
 * `applyDocumentOperation`, `applyTemplate`, `publish`, `rollback`, …) — there
 * is deliberately no generic `execute($anything)` entry point.
 *
 * Phase 4 adds the authoring READ commands `renderPreview()` / `renderForEditor()`
 * (same enforcement pipeline, no mutation, no persistence) and makes
 * `publish()` compile the published artifact inside the publish transaction,
 * so a compilation failure can never leave a half-published page. Public
 * (anonymous) rendering deliberately does NOT pass through this class — it has
 * no actor to authorize; see `Runtime\StudioPublicRuntime`.
 *
 * Phase 5 (builder shell) adds the builder's read queries (`listPages`,
 * `loadEditorDocument`, `editorManifest`, `listRevisions`), the advisory
 * edit-lock commands, and an explicit `revision_kind` on
 * `applyDocumentOperation()` so debounced autosaves are recorded as
 * `autosave` revisions (and deduplicated by the revision service). The builder
 * UI reaches none of the services or repositories below except through these
 * methods.
 *
 * Phase 6 (templates, global components, design system) adds: template
 * library commands (`applyTemplate` now REQUIRES the client's expected
 * revision — preflight #1; `insertTemplate`, `saveTemplateFromPage`,
 * `deleteTemplate`), Global Component commands (`createGlobalComponent`,
 * `detachGlobalSection`, `listGlobalComponents`; a component is a
 * `section_preset` page referenced by uuid — see StudioGlobalComponentService),
 * chrome binding queries (`chromeBindings`), design-token commands
 * (`designTokens`, `saveDesignTokens` behind `studio-builder.tokens`), and the
 * dependency invalidation that publishing/archiving a component or a
 * header/footer partial triggers for its dependents.
 *
 * Cross-reference validation (`media_exists`/`template_exists`/`partial_exists`)
 * and block entitlement/permission enforcement
 * (`entitlement_check`/`permission_check`) are wired ONCE, here, in
 * `buildValidationOptions()` — never scattered into individual block classes.
 * `partial_exists` resolves a `section.global_ref` to a non-archived
 * `section_preset` page of the CURRENT tenant by uuid (Phase 6 live reference).
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Domain\StudioTemplate;
use Slate\Module\StudioBuilder\Exception\StudioAuthenticationException;
use Slate\Module\StudioBuilder\Exception\StudioAuthorizationException;
use Slate\Module\StudioBuilder\Exception\StudioEntitlementException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\Chrome\ChromeResolver;
use Slate\Module\StudioBuilder\Render\Compile\StudioCompilationInvalidator;
use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderResult;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Service\StudioEditLockService;
use Slate\Module\StudioBuilder\Service\StudioGlobalComponentService;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\Service\StudioThemeService;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Data\Database;
use Slate\Services\Audit\AuditLog;
use Slate\Services\Licensing\EntitlementService;
use Slate\Services\Media\Media;
use Slate\Tenancy\TenantContext;

final class StudioApplicationService
{
    public const ENTITLEMENT_KEY = 'studio-builder';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pageRepo,
        private readonly TemplateRepository $templateRepo,
        private readonly RevisionRepository $revisionRepo,
        private readonly StudioPageAddressService $pages,
        private readonly StudioRevisionService $revisions,
        private readonly StudioTemplateService $templates,
        private readonly DataProviderRegistry $providers,
        private readonly BlockRegistry $registry,
        private readonly ?StudioRenderService $renderer = null,
        private readonly ?StudioCompilationInvalidator $invalidator = null,
        private readonly ?ThemeResolver $themes = null,
        private readonly ?StudioEditLockService $locks = null,
        private readonly ?StudioThemeService $themeService = null,
        private readonly ?StudioGlobalComponentService $components = null,
        private readonly ?MediaResolverInterface $media = null,
    ) {}

    /** Builder queries return at most this many pages / revisions per call. */
    public const MAX_LISTED_PAGES     = 200;
    public const MAX_LISTED_REVISIONS = 50;

    /** Revision kinds a builder session may write through `applyDocumentOperation()`. */
    public const EDITOR_REVISION_KINDS = ['autosave', 'manual'];

    // ── Page address commands ───────────────────────────────────────────────

    /**
     * @return array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function createPage(StudioActor $actor, string $title, string $slug, string $pageType, string $routeMode): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->pages->createPage($title, $slug, $pageType, $routeMode, (int) $actor->userId, $this->buildValidationOptions($actor));
        AuditLog::record('studio.page.created', (string) $result['page']['id'], ['title' => $title, 'slug' => $slug]);
        return $result;
    }

    /**
     * @param array{title?: string, slug?: string, route_mode?: string} $changes
     * @return array<string, mixed>
     */
    public function updatePageAddress(StudioActor $actor, int $pageId, array $changes): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->pages->updateAddress($pageId, $changes, (int) $actor->userId);
        AuditLog::record('studio.page.address_updated', (string) $pageId, $changes);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function archivePage(StudioActor $actor, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $current = $this->pageRepo->find($pageId);
        if ($current === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        // Deletion/archive safety: a Global Component still referenced by a page's
        // current draft or published document cannot be archived.
        $this->components?->assertNotReferenced($current);
        $result = $this->pages->archivePage($pageId, (int) $actor->userId);
        $this->invalidator?->invalidatePage($pageId);
        $this->invalidateSharedContent($current);
        AuditLog::record('studio.page.archived', (string) $pageId);
        return $result;
    }

    public function findAddress(StudioActor $actor, int $pageId): ?PageAddress
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        return $this->pages->findAddress($pageId);
    }

    // ── Draft / operation commands ──────────────────────────────────────────

    /**
     * @param array<string, mixed> $rawDocument
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function saveDraft(
        StudioActor $actor,
        int $pageId,
        array $rawDocument,
        ?int $expectedRevisionId,
        string $revisionKind = 'manual',
        ?string $summary = null,
    ): array {
        $this->authorize($actor, StudioPermissions::EDIT);
        $validated = ValidatedDocument::from($rawDocument, $this->registry, $this->buildValidationOptions($actor));
        $result = $this->revisions->createDraftRevision($pageId, $validated, $expectedRevisionId, (int) $actor->userId, $revisionKind, $summary);
        AuditLog::record('studio.page.draft_saved', (string) $pageId, ['revision_kind' => $revisionKind, 'deduplicated' => $result['deduplicated']]);
        return $result;
    }

    /**
     * Loads the page's current working document, applies the given operations
     * to it, then runs the SAME full pipeline `saveDraft()` does (validate ->
     * normalize -> dependency extraction -> transactional persistence) —
     * an operation's result is never persisted without it.
     *
     * `$revisionKind` is `manual` (an explicit save) or `autosave` (the
     * builder's debounced flush); an autosave whose result is byte-identical to
     * the current working draft creates no new revision.
     *
     * @param list<DocumentOperation> $operations
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function applyDocumentOperation(
        StudioActor $actor,
        int $pageId,
        array $operations,
        ?int $expectedRevisionId,
        ?string $summary = null,
        string $revisionKind = 'manual',
    ): array {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->mutateDocument($actor, $pageId, $operations, $expectedRevisionId, $summary, $revisionKind);
        AuditLog::record('studio.page.operation_applied', (string) $pageId, [
            'operation_count' => count($operations),
            'revision_kind'   => $revisionKind,
            'deduplicated'    => $result['deduplicated'],
        ]);
        return $result;
    }

    /**
     * The operation pipeline without authorization or audit: load the current
     * working document, apply, validate, normalize, persist a revision.
     * Callers have already authorized; they audit AFTER any transaction they
     * own has committed (the platform's AuditLog resolves the session user,
     * whose lazy schema check implicitly commits an open MySQL transaction).
     *
     * @param list<DocumentOperation> $operations
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    private function mutateDocument(StudioActor $actor, int $pageId, array $operations, ?int $expectedRevisionId, ?string $summary, string $revisionKind): array
    {
        if (!in_array($revisionKind, self::EDITOR_REVISION_KINDS, true)) {
            throw new StudioValidationException([
                ['path' => '$.revision_kind', 'code' => 'invalid_revision_kind', 'message' => 'revision_kind must be autosave or manual.'],
            ]);
        }

        [$page, $currentDocument] = $this->currentDocument($pageId);
        $mutatedDocument = DocumentOperationApplier::apply($currentDocument, $operations, $this->registry);
        $validated = ValidatedDocument::from($mutatedDocument, $this->registry, $this->buildValidationOptions($actor));

        return $this->revisions->createDraftRevision($pageId, $validated, $expectedRevisionId, (int) $actor->userId, $revisionKind, $summary);
    }

    // ── Template commands (reusable presets: COPY semantics) ────────────────

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function saveTemplate(
        StudioActor $actor,
        string $templateKey,
        string $templateType,
        string $category,
        string $name,
        ?string $description,
        array $document,
        ?int $thumbnailMediaId = null,
    ): array {
        // Template/system administration — StudioPermissions::ADMIN, not EDIT
        // (target architecture example: "system/template administration -> studio-builder.admin").
        $this->authorize($actor, StudioPermissions::ADMIN);
        $result = $this->templates->saveTemplate($templateKey, $templateType, $category, $name, $description, $document, (int) $actor->userId, $this->buildValidationOptions($actor), $thumbnailMediaId);
        // Pages whose document names this template (template_key) recompile on next request.
        $this->invalidator?->invalidateTemplate($templateKey);
        AuditLog::record('studio.template.saved', $templateKey, ['template_type' => $templateType]);
        return $result;
    }

    /**
     * Save a template FROM a page's current working document: the whole page
     * (page/header/footer templates), one section (section_preset) or one
     * block (block_preset). The content is read from the stored revision,
     * never from the client.
     *
     * @return array<string, mixed>
     */
    public function saveTemplateFromPage(
        StudioActor $actor,
        int $pageId,
        ?string $nodeId,
        string $templateKey,
        string $templateType,
        string $category,
        string $name,
        ?string $description,
        ?int $thumbnailMediaId = null,
    ): array {
        $this->authorize($actor, StudioPermissions::ADMIN);
        [$page, $document] = $this->currentDocument($pageId);
        $pageType = (string) $page['page_type'];
        if (($templateType === 'header_preset' && $pageType !== 'header_partial') || ($templateType === 'footer_preset' && $pageType !== 'footer_partial')) {
            throw new StudioValidationException([
                ['path' => '$.template_type', 'code' => 'template_type_mismatch', 'message' => "A {$templateType} can only be saved from a matching partial page."],
            ]);
        }
        if ($templateType === 'page_template' && !in_array($pageType, CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.template_type', 'code' => 'template_type_mismatch', 'message' => 'A page template can only be saved from a page.'],
            ]);
        }
        $templateDocument = StudioTemplateService::extractTemplateDocument($document, $nodeId, $templateType);
        return $this->saveTemplate($actor, $templateKey, $templateType, $category, $name, $description, $templateDocument, $thumbnailMediaId);
    }

    /**
     * @return array<string, mixed> the deleted template row
     */
    public function deleteTemplate(StudioActor $actor, string $templateKey): array
    {
        $this->authorize($actor, StudioPermissions::ADMIN);
        $row = $this->templates->deleteTemplate($templateKey);
        AuditLog::record('studio.template.deleted', $templateKey);
        return $row;
    }

    /**
     * Replace a page's working document with a page template.
     *
     * `$expectedRevisionId` — the client's current draft revision id — is
     * mandatory in the same sense as for every other draft write: it is
     * verified under the page row lock by StudioRevisionService, and a stale
     * or missing value is a StudioConcurrencyException (409) that overwrites
     * nothing (Phase 6 preflight #1). No second concurrency model exists.
     *
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function applyTemplate(StudioActor $actor, string $templateKey, int $pageId, ?int $expectedRevisionId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->templates->applyTemplate($templateKey, $pageId, $expectedRevisionId, (int) $actor->userId, $this->buildValidationOptions($actor));
        AuditLog::record('studio.template.applied', (string) $pageId, ['template_key' => $templateKey]);
        return $result;
    }

    /**
     * Insert a reusable preset (section / header / footer / block preset) into
     * a page as an OWNED COPY, through the ordinary operation pipeline (same
     * validation, normalization, concurrency and revision as any edit). The
     * page records no reference to the template afterwards.
     *
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function insertTemplate(StudioActor $actor, int $pageId, string $templateKey, int $index, ?string $parentId, ?int $expectedRevisionId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $operations = $this->templates->insertOperations($templateKey, $index, $parentId);
        $result = $this->applyDocumentOperation($actor, $pageId, $operations, $expectedRevisionId, "Inserted preset '{$templateKey}'", 'manual');
        AuditLog::record('studio.template.inserted', (string) $pageId, ['template_key' => $templateKey]);
        return $result;
    }

    /**
     * Template library listing: transport-safe rows plus a structural summary
     * and the tenant-scoped thumbnail URL (never a document body).
     *
     * @return list<array<string, mixed>>
     */
    public function listTemplates(StudioActor $actor, ?string $templateType = null, ?string $category = null): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        if ($templateType !== null && !in_array($templateType, StudioTemplate::ALLOWED_TEMPLATE_TYPES, true)) {
            return [];
        }
        return $this->templates->listTemplates($templateType, $category);
    }

    /**
     * The library view of every template (or of one type), with summary and thumbnail.
     *
     * @return list<array<string, mixed>>
     */
    public function templateLibrary(StudioActor $actor, ?string $templateType = null): array
    {
        $out = [];
        foreach ($this->listTemplates($actor, $templateType) as $row) {
            $summary = null;
            try {
                $summary = $this->templates->summarize(CanonicalJson::decode((string) $row['document_json']));
            } catch (\Throwable $ignored) {
                $summary = null;
            }
            $out[] = StudioEditorViews::template($row, $this->thumbnailUrl($row), $summary);
        }
        return $out;
    }

    // ── Global Component commands (LIVE reference semantics) ────────────────

    /**
     * The tenant's Global Components (section_preset pages) with usage counts.
     *
     * @return list<array<string, mixed>>
     */
    public function listGlobalComponents(StudioActor $actor): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $out = [];
        foreach ($this->requireComponents()->listComponents() as $item) {
            $out[] = StudioEditorViews::component($item['row'], $item['usage_count']);
        }
        return $out;
    }

    /**
     * Create a Global Component: a new `section_preset` page. When a source
     * page + section are given, the component's first document is an owned
     * copy of that section and the source section is replaced, in the same
     * transaction, by a live reference to the new component — verified
     * against `$expectedRevisionId` like any other draft write.
     *
     * The component starts UNPUBLISHED; consumers render its published
     * revision only, so it must be published (studio-builder.publish) before
     * it appears in pages. Nothing here publishes implicitly.
     *
     * @return array{component: array<string, mixed>, page: ?array<string, mixed>, revision: ?array<string, mixed>}
     */
    public function createGlobalComponent(StudioActor $actor, string $title, string $slug, ?int $fromPageId = null, ?string $sectionId = null, ?int $expectedRevisionId = null): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $components = $this->requireComponents();

        $extraction = null;
        if ($fromPageId !== null) {
            if ($sectionId === null || $sectionId === '') {
                throw new StudioValidationException([
                    ['path' => '$.section_id', 'code' => 'required_field', 'message' => 'section_id is required when creating a component from a page section.'],
                ]);
            }
            [, $sourceDocument] = $this->currentDocument($fromPageId);
            $extraction = $components->extractionFor($sourceDocument, $sectionId, $title);
        }

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $created = $this->pages->createPage($title, $slug, CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'standalone', (int) $actor->userId, $this->buildValidationOptions($actor));
            $componentRow = $created['page'];
            $ref = (string) $componentRow['uuid'];
            $pageResult = null;

            if ($extraction !== null && $fromPageId !== null) {
                $validated = ValidatedDocument::from($extraction['component_document'], $this->registry, $this->buildValidationOptions($actor));
                $this->revisions->createDraftRevision((int) $componentRow['id'], $validated, (int) $created['revision']['id'], (int) $actor->userId, 'manual', "Created from page {$fromPageId}");
                $componentRow = $this->pageRepo->find((int) $componentRow['id']) ?? $componentRow;

                $operations = StudioGlobalComponentService::referenceOperations($extraction['section'], $extraction['index'], $ref);
                // No audit inside the transaction (see mutateDocument()); recorded below, after commit.
                $pageResult = $this->mutateDocument($actor, $fromPageId, $operations, $expectedRevisionId, "Replaced section with global component '{$title}'", 'manual');
            }
            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        if ($pageResult !== null && $fromPageId !== null) {
            AuditLog::record('studio.page.operation_applied', (string) $fromPageId, ['operation_count' => 2, 'revision_kind' => 'manual', 'deduplicated' => false]);
        }
        AuditLog::record('studio.component.created', (string) $componentRow['id'], ['from_page_id' => $fromPageId, 'section_id' => $sectionId]);
        return [
            'component' => StudioEditorViews::component($componentRow, $pageResult !== null ? 1 : 0),
            'page'      => $pageResult['page'] ?? null,
            'revision'  => $pageResult['revision'] ?? null,
        ];
    }

    /**
     * Turn a live reference back into owned content: the section is replaced
     * by a copy of the component's content (published revision when it has
     * one). Runs through the operation pipeline with `$expectedRevisionId`.
     *
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function detachGlobalSection(StudioActor $actor, int $pageId, string $sectionId, ?int $expectedRevisionId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        [, $document] = $this->currentDocument($pageId);
        $detach = $this->requireComponents()->detachOperations($document, $sectionId);
        $result = $this->applyDocumentOperation($actor, $pageId, $detach['operations'], $expectedRevisionId, "Detached global component '{$detach['component']['title']}'", 'manual');
        AuditLog::record('studio.component.detached', (string) $pageId, ['section_id' => $sectionId, 'component_id' => (int) $detach['component']['id']]);
        return $result;
    }

    // ── Chrome (header / footer) bindings ───────────────────────────────────

    /**
     * What a page's header/footer settings currently resolve to, following
     * exactly the Phase 4 ChromeResolver rules (published partials only):
     * the site partial (slug `default`) and the page-specific partial (slug =
     * the page's slug) of each region, and which one the mode picks.
     *
     * @return array<string, mixed>
     */
    public function chromeBindings(StudioActor $actor, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        [$page, $document] = $this->currentDocument($pageId);
        $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];
        $chromed  = in_array((string) $page['page_type'], ChromeResolver::CHROMED_PAGE_TYPES, true);

        $regions = [];
        foreach (['header' => 'header_partial', 'footer' => 'footer_partial'] as $region => $partialType) {
            $mode = (string) ($settings[$region . '_mode'] ?? 'inherit');
            if (!in_array($mode, CanonicalDocumentSchema::ALLOWED_CHROME_MODES, true)) {
                $mode = 'inherit';
            }
            $site   = $this->pageRepo->findBySlug(ChromeResolver::SITE_PARTIAL_SLUG, $partialType);
            $custom = (string) $page['slug'] !== ChromeResolver::SITE_PARTIAL_SLUG ? $this->pageRepo->findBySlug((string) $page['slug'], $partialType) : null;
            $isLive = static fn(?array $row): bool => $row !== null && ($row['status'] ?? null) === 'published' && !empty($row['published_revision_id']);

            if (!$chromed || $mode === 'hidden') {
                $resolved = 'hidden';
            } elseif ($mode === 'custom' && $isLive($custom)) {
                $resolved = 'custom';
            } elseif ($isLive($site)) {
                $resolved = 'site';
            } else {
                $resolved = 'builtin';
            }
            $regions[$region] = [
                'mode'     => $mode,
                'resolved' => $resolved,
                'site'     => $site !== null && ($site['status'] ?? null) !== 'archived' ? StudioEditorViews::page($site) : null,
                'custom'   => $custom !== null && ($custom['status'] ?? null) !== 'archived' ? StudioEditorViews::page($custom) : null,
            ];
        }
        return ['chromed' => $chromed, 'page_slug' => (string) $page['slug'], 'site_slug' => ChromeResolver::SITE_PARTIAL_SLUG, 'header' => $regions['header'], 'footer' => $regions['footer']];
    }

    // ── Design tokens ───────────────────────────────────────────────────────

    /**
     * The supported design tokens with their default / branding / stored
     * layers and effective values.
     *
     * @return array{group: string, tokens: list<array<string, mixed>>}
     */
    public function designTokens(StudioActor $actor, string $tokenGroup = ThemeResolver::DEFAULT_GROUP): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        return $this->requireThemeService()->layers($tokenGroup);
    }

    /**
     * Replace the tenant's stored Studio token overrides of one group
     * (`studio-builder.tokens`). Values pass the same sanitization the
     * renderer applies; unknown refs and unsafe values reject the write. Every
     * page of the tenant that uses the group recompiles on next request.
     *
     * @param array<string, mixed> $tokens ref => value|null
     * @return array{group: string, tokens: list<array<string, mixed>>}
     */
    public function saveDesignTokens(StudioActor $actor, string $tokenGroup, array $tokens): array
    {
        $this->authorize($actor, StudioPermissions::TOKENS);
        $result = $this->requireThemeService()->saveTokens($tokenGroup, $tokens, (int) $actor->userId);
        $this->invalidator?->invalidateTokenGroup($tokenGroup);
        AuditLog::record('studio.tokens.saved', $tokenGroup, ['count' => count(array_filter($tokens, static fn($v): bool => $v !== null && $v !== ''))]);
        return $result;
    }

    // ── Publish / rollback commands ─────────────────────────────────────────

    /**
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string}
     */
    public function publish(StudioActor $actor, int $pageId, ?int $expectedRevisionId, ?string $summary = null): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::PUBLISH);

        if ($this->renderer === null) {
            $result = $this->revisions->publishWorkingRevision($pageId, $expectedRevisionId, (int) $actor->userId, $summary, $this->buildValidationOptions($actor));
            $this->invalidateSharedContent($result['page']);
            AuditLog::record('studio.page.published', (string) $pageId);
            return $result;
        }

        // Publish + compile are ONE transaction: publishWorkingRevision() joins it
        // (it only owns a transaction when none is open), and the published
        // artifact is compiled from the new publish revision before commit. Any
        // compilation failure rolls back the revision, the page pointers and the
        // artifact together — the previous published state stays live.
        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $result = $this->revisions->publishWorkingRevision($pageId, $expectedRevisionId, (int) $actor->userId, $summary, $this->buildValidationOptions($actor));
            $compiled = $this->renderer->compilePublished(
                PageAddress::fromRow($result['page']),
                $result['revision'],
                RenderContext::forPublic($tenantId, $this->renderer->siteContext()),
            );
            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // A republished Global Component / header / footer partial: drop the
        // artifacts of every dependent page (they recompile on next request).
        $this->invalidateSharedContent($result['page']);

        AuditLog::record('studio.page.published', (string) $pageId, [
            'revision_id'  => (int) $result['revision']['id'],
            'content_hash' => $compiled->contentHash,
        ]);
        return $result;
    }

    /**
     * Rollback only ever mutates the working draft pointer — `publish()` is a
     * separate, explicit act — so this requires `studio-builder.edit`, not
     * `studio-builder.publish` (matches the "edit cannot publish" / "publish
     * cannot bypass entitlement" test expectations: an editor can restore a
     * prior draft without also being granted the ability to make it live).
     *
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, rolled_back_from_revision_id: int}
     */
    public function rollback(StudioActor $actor, int $pageId, int $targetRevisionId, ?int $expectedRevisionId, ?string $summary = null): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->revisions->rollbackToRevision($pageId, $targetRevisionId, $expectedRevisionId, (int) $actor->userId, $summary, $this->buildValidationOptions($actor));
        AuditLog::record('studio.page.rolled_back', (string) $pageId, ['target_revision_id' => $targetRevisionId]);
        return $result;
    }

    // ── Authoring render commands (read-only) ───────────────────────────────

    /**
     * Preview a page revision: the page's working draft by default, or an
     * explicit revision of THAT page. Every id is resolved through the
     * tenant-scoped repositories, so another tenant's page, or a revision id
     * belonging to a different page, is simply "not found" — an arbitrary
     * revision id is never rendered on its own authority. Read-only: no
     * revision, compilation, audit row or any other write is produced.
     */
    public function renderPreview(StudioActor $actor, int $pageId, ?int $revisionId = null): RenderResult
    {
        $tenantId = $this->authorize($actor, StudioPermissions::VIEW);
        $renderer = $this->requireRenderer();
        [$page, $revision] = $this->loadPageRevision($pageId, $revisionId);
        return $renderer->renderRevision($page, $revision, RenderContext::forPreview($tenantId, $renderer->siteContext(), $actor));
    }

    /**
     * Render the working draft for the (Phase 5) builder canvas: editor node
     * metadata included, never cached or indexed. Requires studio-builder.edit.
     */
    public function renderForEditor(StudioActor $actor, int $pageId): RenderResult
    {
        $tenantId = $this->authorize($actor, StudioPermissions::EDIT);
        $renderer = $this->requireRenderer();
        [$page, $revision] = $this->loadPageRevision($pageId, null);
        return $renderer->renderRevision($page, $revision, RenderContext::forEditor($tenantId, $renderer->siteContext(), $actor));
    }

    // ── Builder queries (read-only) ─────────────────────────────────────────

    /**
     * The tenant's Studio pages for the builder's page list (archived pages
     * excluded). Summaries only — never a document body.
     *
     * @return list<array<string, mixed>>
     */
    public function listPages(StudioActor $actor): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $out = [];
        foreach ($this->pageRepo->all([], 'updated_at DESC', self::MAX_LISTED_PAGES + 50) as $row) {
            if (($row['status'] ?? '') === 'archived') {
                continue;
            }
            $out[] = StudioEditorViews::page($row);
            if (count($out) >= self::MAX_LISTED_PAGES) {
                break;
            }
        }
        return $out;
    }

    /**
     * Lightweight page state (revision pointers, publish status) — what the
     * builder polls to notice that the server moved on without it.
     *
     * @return array<string, mixed>
     */
    public function pageStatus(StudioActor $actor, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        return StudioEditorViews::page($page);
    }

    /**
     * The builder's authoritative starting point: the page and its CURRENT
     * working draft, decoded from the immutable revision row. The builder is
     * always reconstructed from this — never from browser state.
     *
     * @return array{page: array<string, mixed>, revision: ?array<string, mixed>, document: array<string, mixed>}
     */
    public function loadEditorDocument(StudioActor $actor, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }

        $draftId  = isset($page['active_draft_revision_id']) ? (int) $page['active_draft_revision_id'] : 0;
        $revision = $draftId > 0 ? $this->revisionRepo->findByIdForPage($pageId, $draftId) : null;
        $document = $revision !== null
            ? CanonicalJson::decode((string) $revision['document_json'])
            : CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);

        return [
            'page'     => StudioEditorViews::page($page),
            'revision' => $revision !== null ? StudioEditorViews::revision($revision) : null,
            'document' => $document,
        ];
    }

    /**
     * Everything the builder needs to generate its palette and property panels,
     * as transport-safe DATA: block manifests (filtered by this tenant's
     * entitlements and this actor's permissions), the parameter schemas of the
     * data providers those blocks may bind to, the resolved design-token refs,
     * and the canonical vocabulary (breakpoints, spacing scale, limits, ...).
     * No PHP class name, closure, path, SQL or credential is ever included.
     *
     * @return array<string, mixed>
     */
    public function editorManifest(StudioActor $actor): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::EDIT);
        $entitled = static fn(?string $moduleKey): bool => $moduleKey === null || $moduleKey === '' || EntitlementService::canAccess($tenantId, $moduleKey);

        $providers = [];
        foreach ($this->providers->all() as $key => $provider) {
            if (!$entitled($provider->requiredEntitlement()) || !$actor->can($provider->requiredPermission())) {
                continue;
            }
            $providers[] = [
                'key'         => (string) $key,
                'max_results' => $provider->maxResults(),
                'params'      => $provider->parameterSchema()->toEditorManifest(),
            ];
        }

        $tokens = [];
        $themeTokens = $this->themes !== null ? $this->themes->resolve(ThemeResolver::DEFAULT_GROUP)->tokens() : ThemeResolver::DEFAULT_TOKENS;
        foreach ($themeTokens as $ref => $value) {
            $tokens[] = ['category' => explode('.', (string) $ref, 2)[0], 'ref' => (string) $ref, 'value' => (string) $value];
        }

        return [
            'blocks'      => $this->registry->editorManifests($entitled, static fn(string $perm): bool => $actor->can($perm)),
            'providers'   => $providers,
            'tokens'      => $tokens,
            'vocabulary'  => [
                'alignments'       => CanonicalDocumentSchema::ALLOWED_ALIGNMENTS,
                'auth_states'      => CanonicalDocumentSchema::ALLOWED_AUTH_STATES,
                'breakpoints'      => CanonicalDocumentSchema::ALLOWED_BREAKPOINTS,
                'chrome_modes'     => CanonicalDocumentSchema::ALLOWED_CHROME_MODES,
                'container_widths' => CanonicalDocumentSchema::ALLOWED_CONTAINER_WIDTHS,
                'robots'           => CanonicalDocumentSchema::ALLOWED_ROBOTS_DIRECTIVES,
                'spacing_scale'    => CanonicalDocumentSchema::ALLOWED_SPACING_SCALE,
                'style_keys'       => CanonicalDocumentSchema::ALLOWED_STYLE_KEYS,
            ],
            'limits'      => [
                'max_blocks'         => CanonicalDocumentSchema::MAX_BLOCKS_PER_DOCUMENT,
                'max_nesting_depth'  => CanonicalDocumentSchema::MAX_NESTING_DEPTH,
                'max_repeater_items' => CanonicalDocumentSchema::MAX_REPEATER_ITEMS,
                'max_sections'       => CanonicalDocumentSchema::MAX_SECTIONS,
            ],
            'permissions' => [
                'admin'   => $actor->can(StudioPermissions::ADMIN),
                'edit'    => $actor->can(StudioPermissions::EDIT),
                'publish' => $actor->can(StudioPermissions::PUBLISH),
                'tokens'  => $actor->can(StudioPermissions::TOKENS),
                'view'    => $actor->can(StudioPermissions::VIEW),
            ],
            'templates'   => [
                'insertable_types' => StudioTemplateService::INSERTABLE_TYPES,
                'types'            => StudioTemplate::ALLOWED_TEMPLATE_TYPES,
            ],
            'components'  => [
                'document_type'    => CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE,
                'referencing_types' => CanonicalDocumentSchema::GLOBAL_REF_DOCUMENT_TYPES,
            ],
        ];
    }

    /**
     * Recent revisions of one page (newest first), as summaries — the history
     * list a rollback is chosen from. Never includes a document body.
     *
     * @return list<array<string, mixed>>
     */
    public function listRevisions(StudioActor $actor, int $pageId, int $limit = 30): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        if ($this->pageRepo->find($pageId) === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $limit = max(1, min($limit, self::MAX_LISTED_REVISIONS));
        return array_map([StudioEditorViews::class, 'revision'], $this->revisionRepo->forPage($pageId, $limit));
    }

    // ── Advisory edit-session lock commands ─────────────────────────────────

    /**
     * @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int}
     */
    public function acquireEditLock(StudioActor $actor, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        return $this->requireLocks()->acquire($pageId, (int) $actor->userId);
    }

    /**
     * @return array{held: bool, lock_token: ?string, ttl_seconds: int, other_editor: bool, other_expires_in: ?int}
     */
    public function refreshEditLock(StudioActor $actor, int $pageId, string $lockToken): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        return $this->requireLocks()->refresh($pageId, (int) $actor->userId, $lockToken);
    }

    public function releaseEditLock(StudioActor $actor, int $pageId, string $lockToken): bool
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        return $this->requireLocks()->release($pageId, (int) $actor->userId, $lockToken);
    }

    // ── Dynamic data provider command ───────────────────────────────────────

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function runDataProvider(StudioActor $actor, string $providerKey, array $params): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::VIEW);
        return $this->providers->resolve(
            $providerKey,
            $params,
            $this->tenants,
            static fn(string $moduleKey): bool => EntitlementService::canAccess($tenantId, $moduleKey),
            static fn(string $permKey): bool => $actor->can($permKey),
        );
    }

    // ── Enforcement pipeline ─────────────────────────────────────────────────

    /**
     * Steps 1-4 of the pipeline: TenantContext -> authentication -> entitlement
     * -> RBAC permission. Every command calls this first, before touching any
     * domain service. Returns the active tenant id for convenience.
     */
    private function authorize(StudioActor $actor, string $permission): int
    {
        $tenantId = $this->requireTenantId();

        if (!$actor->isAuthenticated()) {
            throw new StudioAuthenticationException();
        }

        if (!EntitlementService::canAccess($tenantId, self::ENTITLEMENT_KEY)) {
            throw new StudioEntitlementException();
        }

        if (!$actor->can($permission)) {
            throw new StudioAuthorizationException($permission);
        }

        return $tenantId;
    }

    private function requireComponents(): StudioGlobalComponentService
    {
        if ($this->components === null) {
            throw new \LogicException('StudioApplicationService was built without a StudioGlobalComponentService.');
        }
        return $this->components;
    }

    private function requireThemeService(): StudioThemeService
    {
        if ($this->themeService === null) {
            throw new \LogicException('StudioApplicationService was built without a StudioThemeService.');
        }
        return $this->themeService;
    }

    /**
     * A page row and its CURRENT working document (decoded from the immutable
     * draft revision; a blank document when the page has none).
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function currentDocument(int $pageId): array
    {
        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $draftId  = isset($page['active_draft_revision_id']) ? (int) $page['active_draft_revision_id'] : 0;
        $revision = $draftId > 0 ? $this->revisionRepo->findByIdForPage($pageId, $draftId) : null;
        $document = $revision !== null
            ? CanonicalJson::decode((string) $revision['document_json'])
            : CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);
        return [$page, $document];
    }

    /** Tenant-scoped thumbnail URL of a template row through the existing media resolver, or null. */
    private function thumbnailUrl(array $templateRow): ?string
    {
        $mediaId = isset($templateRow['thumbnail_media_id']) ? (int) $templateRow['thumbnail_media_id'] : 0;
        if ($mediaId <= 0 || $this->media === null) {
            return null;
        }
        return $this->media->resolveImage($mediaId)?->url;
    }

    /**
     * After a shared-content page changes what its consumers render (publish,
     * archive): components → their dependents; header/footer partials → every
     * chromed page showing that region. Tenant-scoped by construction (the
     * dependency index and the compilation store are tenant-scoped repositories).
     *
     * @param array<string, mixed> $pageRow
     */
    private function invalidateSharedContent(array $pageRow): void
    {
        if ($this->invalidator === null) {
            return;
        }
        match ((string) ($pageRow['page_type'] ?? '')) {
            CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE => $this->invalidator->invalidateComponent((string) ($pageRow['uuid'] ?? '')),
            'header_partial' => $this->invalidator->invalidateChrome('header'),
            'footer_partial' => $this->invalidator->invalidateChrome('footer'),
            default => 0,
        };
    }

    private function requireLocks(): StudioEditLockService
    {
        if ($this->locks === null) {
            throw new \LogicException('StudioApplicationService was built without a StudioEditLockService.');
        }
        return $this->locks;
    }

    private function requireRenderer(): StudioRenderService
    {
        if ($this->renderer === null) {
            throw new \LogicException('StudioApplicationService was built without a StudioRenderService.');
        }
        return $this->renderer;
    }

    /**
     * @return array{0: PageAddress, 1: array<string, mixed>}
     */
    private function loadPageRevision(int $pageId, ?int $revisionId): array
    {
        $row = $this->pageRepo->find($pageId);
        if ($row === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $page = PageAddress::fromRow($row);

        $targetId = $revisionId ?? $page->activeDraftRevisionId ?? $page->publishedRevisionId;
        $revision = ($targetId !== null && $targetId > 0) ? $this->revisionRepo->findByIdForPage($pageId, $targetId) : null;
        if ($revision === null) {
            throw new StudioNotFoundException('The requested revision was not found for this page in the active tenant.', ['page_id' => $pageId]);
        }
        return [$page, $revision];
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }

    /**
     * The single place `media_exists` / `template_exists` / `partial_exists` /
     * `entitlement_check` / `permission_check` are wired into
     * `DocumentValidator` (via `ValidatedDocument::from()`). None of these
     * trust a caller-supplied id or tenant claim: each resolves through an
     * already tenant-scoped repository or service (`Media::get()`,
     * `TemplateRepository::findByKey()`, `EntitlementService::canAccess()`)
     * bound to the CURRENT `TenantContext`, never a value read out of the
     * document itself.
     *
     * `partial_exists` (Phase 6) resolves a section's `global_ref` to a
     * non-archived Global Component — a `section_preset` page of the CURRENT
     * tenant, by uuid (`PageRepository::findComponentByRef()`). Templates are
     * copy presets and are never referenced by a document section.
     *
     * @return array<string, mixed>
     */
    private function buildValidationOptions(StudioActor $actor): array
    {
        $tenantId = $this->requireTenantId();

        return [
            'media_exists' => static function (int $mediaId) use ($tenantId): bool {
                return class_exists(Media::class) && Media::get($mediaId) !== null;
            },
            'template_exists' => function (string $key): bool {
                return $this->templateRepo->findByKey($key) !== null;
            },
            'partial_exists' => function (string $ref): bool {
                return $this->pageRepo->findComponentByRef($ref) !== null;
            },
            'entitlement_check' => static function (string $moduleKey) use ($tenantId): bool {
                return EntitlementService::canAccess($tenantId, $moduleKey);
            },
            'permission_check' => static function (string $permKey) use ($actor): bool {
                return $actor->can($permKey);
            },
        ];
    }
}
