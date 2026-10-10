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
 * Phase 7 (AI + MCP) adds NO new mutation path. The actor's ORIGIN (session,
 * mcp_token, admin_assistant — see StudioActor) now decides how a draft write
 * is RECORDED: an AI-origin actor's draft mutations are always persisted as
 * `ai_operation` revisions, whatever kind the caller asked for, and a human
 * session can only write `autosave` / `manual` (never `ai_operation`). Every
 * Studio audit event carries the actor's explicit attribution
 * (`origin`, `user_id`, `token_id`) plus the resulting `revision_id`, so an
 * MCP tool call, its token, the delegating user, the AI revision and a later
 * human publish are all linkable in the ONE existing audit log. The
 * structured revision diff (`diffRevisions`) and the revision lookup
 * (`findRevision`) are read-only queries added for the review workflow.
 *
 * Phase 8A (JSON packages) adds `exportPackage()` (studio-builder.view) and
 * `importPackage()` (studio-builder.edit; token items additionally need
 * studio-builder.tokens, template items keep the existing template boundary
 * studio-builder.admin). A dry run plans and validates without any write or
 * audit row; a commit runs the plan's writes through the canonical services
 * in ONE transaction owned here, records every draft as `import`, publishes
 * nothing, and audits after the commit. See StudioPackageService.
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

use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Diff\RevisionDiff;
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
use Slate\Module\StudioBuilder\Package\Html\HtmlImportConverter;
use Slate\Module\StudioBuilder\Package\StudioImportReport;
use Slate\Module\StudioBuilder\Provider\DataProviderInterface;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Provider\ParamChoicesProviderInterface;
use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Registry\BlockAvailability;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Registry\BlockUsage;
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
use Slate\Module\StudioBuilder\Presets\ElementVariantCatalog;
use Slate\Module\StudioBuilder\Presets\SectionPresetCatalog;
use Slate\Module\StudioBuilder\Presets\WireframeOutline;
use Slate\Module\StudioBuilder\Runtime\StudioLog;
use Slate\Module\StudioBuilder\Runtime\StudioPublicLocale;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
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
        private ?StudioPackageService $packages = null,
        private readonly ?HtmlImportConverter $htmlImporter = null,
        private readonly ?BlockAvailability $availability = null,
    ) {}

    /** Builder queries return at most this many pages / revisions per call. */
    public const MAX_LISTED_PAGES     = 200;
    public const MAX_LISTED_REVISIONS = 50;

    /** The Element Manager's usage scan reads at most this many pages' drafts. */
    public const MAX_SCANNED_PAGES = 500;

    /** Revision kinds a builder session may write through `applyDocumentOperation()`. */
    public const EDITOR_REVISION_KINDS = ['autosave', 'manual'];

    /** The ONLY kind an AI-origin actor (mcp_token / admin_assistant) can write a draft as. */
    public const AI_REVISION_KIND = 'ai_operation';

    /**
     * Phase 8A: the kind every package-import draft is recorded as. Reachable
     * ONLY through `importPackage()` / `importHtml()` (Phase 8B) — never through
     * saveDraft/operations, never selectable by a client, never by an AI-origin actor.
     */
    public const IMPORT_REVISION_KIND = 'import';

    // ── Page address commands ───────────────────────────────────────────────

    /**
     * @return array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function createPage(StudioActor $actor, string $title, string $slug, string $pageType, string $routeMode): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->pages->createPage($title, $slug, $pageType, $routeMode, (int) $actor->userId, $this->buildValidationOptions($actor), $this->draftKind($actor, 'manual'));
        $this->audit($actor, 'studio.page.created', (string) $result['page']['id'], ['title' => $title, 'slug' => $slug, 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
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
        $this->audit($actor, 'studio.page.address_updated', (string) $pageId, $changes);
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
        $this->audit($actor, 'studio.page.archived', (string) $pageId);
        return $result;
    }

    /**
     * Copy a page into a new draft page: same page type and document, a new title and slug, never published and
     * never the homepage (`standalone`). The copy is created blank and then filled by a normal draft save, so every
     * validation and audit step runs; if the fill fails the half-made page is archived rather than left behind.
     *
     * @return array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function duplicatePage(StudioActor $actor, int $sourcePageId, string $title, string $slug): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $source = $this->pageRepo->find($sourcePageId);
        if ($source === null) {
            throw new StudioNotFoundException("Studio page {$sourcePageId} was not found in the active tenant.", ['page_id' => $sourcePageId]);
        }
        $loaded = $this->loadEditorDocument($actor, $sourcePageId);
        $pageType = (string) $source['page_type'];

        $created = $this->createPage($actor, $title, $slug, $pageType, 'standalone');
        $newId = (int) $created['page']['id'];
        try {
            $filled = $this->saveDraft($actor, $newId, $loaded['document'], (int) $created['revision']['id'], 'manual', 'Duplicated from "' . (string) $source['title'] . '"');
        } catch (\Throwable $e) {
            try {
                $this->pages->archivePage($newId, (int) $actor->userId);
            } catch (\Throwable $ignored) {
                // the original failure is the one worth reporting
            }
            throw $e;
        }
        $this->audit($actor, 'studio.page.duplicated', (string) $newId, ['source_page_id' => $sourcePageId]);
        return ['page' => $filled['page'], 'revision' => $filled['revision']];
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
        $revisionKind = $this->draftKind($actor, $revisionKind);
        $validated = ValidatedDocument::from($rawDocument, $this->registry, $this->buildValidationOptions($actor));
        $result = $this->revisions->createDraftRevision($pageId, $validated, $expectedRevisionId, (int) $actor->userId, $revisionKind, $summary);
        $this->audit($actor, 'studio.page.draft_saved', (string) $pageId, ['revision_kind' => $revisionKind, 'deduplicated' => $result['deduplicated'], 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
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
     * the current working draft creates no new revision. For an AI-origin
     * actor the requested kind is ignored and the revision is always recorded
     * as `ai_operation` (Phase 7) — the kind is decided here, never by a client.
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
        $revisionKind = $this->draftKind($actor, $revisionKind);
        $result = $this->mutateDocument($actor, $pageId, $operations, $expectedRevisionId, $summary, $revisionKind);
        $this->audit($actor, 'studio.page.operation_applied', (string) $pageId, [
            'operation_count' => count($operations),
            'revision_kind'   => $revisionKind,
            'deduplicated'    => $result['deduplicated'],
            'revision_id'     => (int) ($result['revision']['id'] ?? 0),
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
        $revisionKind = $this->draftKind($actor, $revisionKind);

        $this->assertBlocksAvailable($operations);
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
        $this->audit($actor, 'studio.template.saved', $templateKey, ['template_type' => $templateType]);
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
        $this->audit($actor, 'studio.template.deleted', $templateKey);
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
        $result = $this->templates->applyTemplate($templateKey, $pageId, $expectedRevisionId, (int) $actor->userId, $this->buildValidationOptions($actor), $this->draftKind($actor, 'manual'));
        $this->audit($actor, 'studio.template.applied', (string) $pageId, ['template_key' => $templateKey, 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
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
        $this->audit($actor, 'studio.template.inserted', (string) $pageId, ['template_key' => $templateKey, 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
        return $result;
    }

    /**
     * The built-in section presets (system templates) for the Add panel. The first call for a
     * tenant seeds them; later calls only read. Same transport-safe views as the library.
     *
     * @return list<array<string, mixed>>
     */
    public function sectionPresets(StudioActor $actor): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $this->ensureSystemPresets();
        $order = array_flip(array_column(SectionPresetCatalog::all(), 'key'));
        $presets = array_values(array_filter(
            $this->templateLibrary($actor, 'section_preset', true),
            static fn(array $t): bool => !empty($t['is_system']) && isset($order[(string) $t['template_key']]),
        ));
        // Catalogue order (the author's order), not the storage/alphabetical order.
        usort($presets, static fn(array $a, array $b): int => $order[(string) $a['template_key']] <=> $order[(string) $b['template_key']]);
        return $presets;
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
    public function templateLibrary(StudioActor $actor, ?string $templateType = null, bool $withOutline = false): array
    {
        $out = [];
        foreach ($this->listTemplates($actor, $templateType) as $row) {
            $summary = null;
            $outline = null;
            try {
                $document = CanonicalJson::decode((string) $row['document_json']);
                $summary = $this->templates->summarize($document);
                $outline = $withOutline ? WireframeOutline::fromDocument($document) : null;
            } catch (\Throwable $ignored) {
                $summary = null;
            }
            $view = self::translatePresetCopy(StudioEditorViews::template($row, $this->thumbnailUrl($row), $summary));
            if ($withOutline) {
                $view['outline'] = $outline ?? [];
            }
            $out[] = $view;
        }
        return $out;
    }

    /**
     * Translate the name and description of a built-in preset for the active admin locale
     * (`studio_preset_<slug>_name|desc`). Tenant templates are left exactly as written.
     *
     * @param array<string, mixed> $view
     * @return array<string, mixed>
     */
    private static function translatePresetCopy(array $view): array
    {
        $key = (string) ($view['template_key'] ?? '');
        if (empty($view['is_system']) || !str_starts_with($key, SectionPresetCatalog::KEY_PREFIX) || !\function_exists('__')) {
            return $view;
        }
        $slug = str_replace('-', '_', substr($key, strlen(SectionPresetCatalog::KEY_PREFIX)));
        foreach (['name' => 'name', 'description' => 'desc'] as $field => $suffix) {
            $default = (string) ($view[$field] ?? '');
            if ($default === '') {
                continue;
            }
            try {
                $view[$field] = (string) \__("studio_preset_{$slug}_{$suffix}", $default);
            } catch (\Throwable $ignored) {
                $view[$field] = $default;
            }
        }
        return $view;
    }

    /**
     * @param array<string, mixed> $variant
     * @return array<string, mixed>
     */
    private static function translateVariantCopy(array $variant): array
    {
        if (!\function_exists('__')) {
            return $variant;
        }
        $slug = str_replace('-', '_', (string) $variant['key']);
        foreach (['title' => 'title', 'description' => 'desc'] as $field => $suffix) {
            try {
                $variant[$field] = (string) \__("studio_variant_{$slug}_{$suffix}", (string) $variant[$field]);
            } catch (\Throwable $ignored) {
                // keep the English default
            }
        }
        return $variant;
    }

    /** Tenant ids whose built-in presets were already synced during this request. */
    private static array $presetsSynced = [];

    /**
     * Make sure the active tenant has the built-in section presets (system templates).
     * Idempotent and cheap after the first call; a failure never blocks the library.
     */
    private function ensureSystemPresets(): void
    {
        $tenant = $this->tenants->id();
        if ($tenant <= 0 || isset(self::$presetsSynced[$tenant])) {
            return;
        }
        self::$presetsSynced[$tenant] = true;
        try {
            $this->templates->syncSystemTemplates('section_preset', SectionPresetCatalog::all());
        } catch (\Throwable $e) {
            StudioLog::failure('presets', 'sync_system_templates', $e, 'warning');
        }
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
            $created = $this->pages->createPage($title, $slug, CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'standalone', (int) $actor->userId, $this->buildValidationOptions($actor), $this->draftKind($actor, 'manual'));
            $componentRow = $created['page'];
            $ref = (string) $componentRow['uuid'];
            $pageResult = null;

            if ($extraction !== null && $fromPageId !== null) {
                $validated = ValidatedDocument::from($extraction['component_document'], $this->registry, $this->buildValidationOptions($actor));
                $this->revisions->createDraftRevision((int) $componentRow['id'], $validated, (int) $created['revision']['id'], (int) $actor->userId, $this->draftKind($actor, 'manual'), "Created from page {$fromPageId}");
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
            $this->audit($actor, 'studio.page.operation_applied', (string) $fromPageId, ['operation_count' => 2, 'revision_kind' => $this->draftKind($actor, 'manual'), 'deduplicated' => false, 'revision_id' => (int) ($pageResult['revision']['id'] ?? 0)]);
        }
        $this->audit($actor, 'studio.component.created', (string) $componentRow['id'], ['from_page_id' => $fromPageId, 'section_id' => $sectionId, 'revision_id' => (int) ($componentRow['active_draft_revision_id'] ?? 0)]);
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
        $this->audit($actor, 'studio.component.detached', (string) $pageId, ['section_id' => $sectionId, 'component_id' => (int) $detach['component']['id'], 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
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
        $this->audit($actor, 'studio.tokens.saved', $tokenGroup, ['count' => count(array_filter($tokens, static fn($v): bool => $v !== null && $v !== ''))]);
        return $result;
    }

    // ── Element Manager (per-site block availability) ───────────────────────

    /**
     * Every block this site may use, with whether it is switched off and where it is used (the working draft
     * of each non-archived page). Administrators only: it reads every page's document.
     *
     * @return array{elements: list<array<string, mixed>>, scanned_pages: int, truncated: bool}
     */
    public function elementManager(StudioActor $actor): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::ADMIN);
        $entitled = static fn(?string $moduleKey): bool => $moduleKey === null || $moduleKey === '' || EntitlementService::canAccess($tenantId, $moduleKey);
        $disabled = $this->availability?->disabled($tenantId) ?? [];

        $documents = [];
        $rows = $this->pageRepo->all([], 'updated_at DESC', self::MAX_SCANNED_PAGES + 1);
        $truncated = count($rows) > self::MAX_SCANNED_PAGES;
        foreach (array_slice($rows, 0, self::MAX_SCANNED_PAGES) as $row) {
            if (($row['status'] ?? '') === 'archived') {
                continue;
            }
            $draftId = isset($row['active_draft_revision_id']) ? (int) $row['active_draft_revision_id'] : 0;
            $revision = $draftId > 0 ? $this->revisionRepo->findByIdForPage((int) $row['id'], $draftId) : null;
            if ($revision === null) {
                continue;
            }
            $documents[] = ['title' => (string) ($row['title'] ?? ''), 'document' => CanonicalJson::decode((string) $revision['document_json'])];
        }
        $usage = BlockUsage::count($documents);

        $elements = [];
        foreach ($this->registry->editorManifests($entitled, static fn(string $perm): bool => $actor->can($perm)) as $block) {
            $block = self::translateBlockCopy($block);
            $type = (string) $block['type'];
            $elements[] = [
                'type'        => $type,
                'title'       => (string) ($block['title'] ?? $block['label'] ?? $type),
                'description' => (string) ($block['description'] ?? ''),
                'category'    => (string) ($block['category'] ?? ''),
                'icon'        => $block['icon'] ?? null,
                'disabled'    => in_array($type, $disabled, true),
                'usage'       => $usage[$type] ?? ['blocks' => 0, 'pages' => 0, 'sample' => []],
            ];
        }
        return ['elements' => $elements, 'scanned_pages' => count($documents), 'truncated' => $truncated];
    }

    /**
     * Replace the list of switched-off block types. Existing blocks of those types are untouched.
     *
     * @param array<mixed> $disabled
     * @return array{disabled: list<string>}
     */
    public function saveElementManager(StudioActor $actor, array $disabled): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::ADMIN);
        if ($this->availability === null) {
            throw new \LogicException('StudioApplicationService was built without a BlockAvailability.');
        }
        $saved = $this->availability->save($tenantId, $disabled, $this->registry);
        $this->audit($actor, 'studio.elements.saved', 'blocks', ['disabled_count' => count($saved)]);
        return ['disabled' => $saved];
    }

    /** Refuse an operation list that would insert a block type this site switched off. @param list<DocumentOperation> $operations */
    private function assertBlocksAvailable(array $operations): void
    {
        if ($this->availability === null) {
            return;
        }
        $blocked = BlockAvailability::blockedBy($operations, $this->availability->disabled($this->requireTenantId()));
        if ($blocked !== []) {
            throw new StudioValidationException(array_map(
                static fn(string $type): array => ['path' => '$.payload.block.type', 'code' => 'block_disabled', 'message' => "The block type '{$type}' is switched off on this site."],
                $blocked,
            ));
        }
    }

    // ── Site-wide custom CSS (administrators) ───────────────────────────────

    /**
     * The stored site stylesheet and its ceiling. It is the same setting the Code & tracking admin screen edits
     * (`studio_code_custom_css`), emitted at serve time in the public site and in Preview, never in the canvas.
     *
     * @return array{css: string, bytes: int, max_bytes: int}
     */
    public function customCss(StudioActor $actor): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::ADMIN);
        $css = StudioCodePolicy::customCss($tenantId);
        return ['css' => $css, 'bytes' => strlen($css), 'max_bytes' => StudioCodePolicy::MAX_CUSTOM_CSS_BYTES];
    }

    /**
     * Replace the site stylesheet. Oversized input is refused; anything else is reduced by the code policy and
     * the stylesheet that was really stored is returned. No page recompiles: tenant CSS is added at render time.
     *
     * @return array{css: string, bytes: int, max_bytes: int, changed: bool}
     */
    public function saveCustomCss(StudioActor $actor, string $css): array
    {
        $tenantId = $this->authorize($actor, StudioPermissions::ADMIN);
        try {
            $prepared = StudioCodePolicy::prepareCustomCss($css);
        } catch (\InvalidArgumentException) {
            throw new StudioValidationException([['path' => '$.css', 'code' => 'css_too_large', 'message' => 'The stylesheet is too large.']]);
        }
        Database::setSetting(StudioCodePolicy::SETTING_CUSTOM_CSS, $prepared['css'], $tenantId);
        $this->audit($actor, 'studio.custom_css.saved', (string) $tenantId, ['css_bytes' => $prepared['bytes'], 'sanitized' => $prepared['changed']]);
        return $prepared + ['max_bytes' => StudioCodePolicy::MAX_CUSTOM_CSS_BYTES];
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
            $this->audit($actor, 'studio.page.published', (string) $pageId, ['revision_id' => (int) ($result['revision']['id'] ?? 0), 'source_revision_id' => (int) ($result['revision']['parent_revision_id'] ?? 0)]);
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
            // The stored artifact is the one public visitors get, so it is compiled
            // for the tenant's public site locale — never the publisher's own
            // session locale (Phase 9A), or the first public request would
            // recompile it.
            $compiled = StudioPublicLocale::run(fn() => $this->renderer->compilePublished(
                PageAddress::fromRow($result['page']),
                $result['revision'],
                RenderContext::forPublic($tenantId, $this->renderer->siteContext()),
            ));
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

        $this->audit($actor, 'studio.page.published', (string) $pageId, [
            'revision_id'        => (int) $result['revision']['id'],
            'source_revision_id' => (int) ($result['revision']['parent_revision_id'] ?? 0),
            'content_hash'       => $compiled->contentHash,
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
        $this->audit($actor, 'studio.page.rolled_back', (string) $pageId, ['target_revision_id' => $targetRevisionId, 'revision_id' => (int) ($result['revision']['id'] ?? 0)]);
        return $result;
    }

    // ── Package export / import (Phase 8A) ──────────────────────────────────

    /**
     * Export one page (and, when asked, the global components, template and
     * token overrides it uses) as a Kohevo Studio JSON package.
     *
     * @return array{package: array<string, mixed>, package_hash: string, filename: string, summary: array<string, int>}
     */
    public function exportPackage(StudioActor $actor, int $pageId, bool $withComponents = true, bool $withTemplate = true, bool $withTokens = false): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $result = $this->requirePackages()->exportPage($pageId, $withComponents, $withTemplate, $withTokens);
        $this->audit($actor, 'studio.package.exported', (string) $pageId, [
            'package_hash' => $result['package_hash'],
            'counts'       => $result['summary'],
        ]);
        return $result;
    }

    /**
     * Analyse (`$dryRun`) or import a Kohevo Studio JSON package into drafts.
     *
     * A dry run validates and plans everything and writes NOTHING (no page,
     * revision, template, token, dependency row, no audit row). A commit
     * re-plans with real ids, refuses when the plan has any error, then runs
     * every write through the canonical services inside one transaction and
     * audits after it committed. Stale `expected_revision_id` on replace is a
     * StudioConcurrencyException (409). Nothing is ever published.
     *
     * @param array<string, mixed> $options mode, target_page_uuid, expected_revision_id, media_map, component_map, include_tokens
     * @return array{report: array<string, mixed>, target: ?array<string, mixed>}
     */
    public function importPackage(StudioActor $actor, mixed $package, array $options, bool $dryRun): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $revisionKind = $this->importKind($actor);
        $validationOptions = $this->buildValidationOptions($actor);
        $packages = $this->requirePackages();

        $plan = $packages->plan($package, $options, static fn(string $perm): bool => $actor->can($perm), $validationOptions, $dryRun);
        /** @var StudioImportReport $report */
        $report = $plan['report'];
        if ($dryRun || $report->hasErrors()) {
            return ['report' => $report->toArray(null), 'target' => null];
        }

        return $this->commitImportPlan($actor, $plan, $report, $revisionKind, $validationOptions, 'Imported from package ' . substr($report->packageHash, 0, 12), []);
    }

    /**
     * Phase 8B — analyse (`$dryRun`) or import constrained HTML/CSS into a draft.
     *
     * Authorization (tenant -> authentication -> entitlement -> studio-builder.edit,
     * AI origin refused) runs BEFORE the untrusted source is parsed. The pure
     * converter turns the source into a synthesized one-page package using the
     * tenant's resolved theme (read-only); from there it is the Phase 8A
     * pipeline unchanged: plan (media resolution, id re-minting, validation,
     * route checks), the same owned transaction, the same post-commit audit.
     * Revision kind `import`; nothing is ever published.
     *
     * @param array{html: string, css: string, title: ?string, slug: ?string, page_type: string} $source
     * @param array<string, mixed> $options mode, target_page_id, expected_revision_id, media_map
     * @return array{report: array<string, mixed>, target: ?array<string, mixed>}
     */
    public function importHtml(StudioActor $actor, array $source, array $options, bool $dryRun): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $revisionKind = $this->importKind($actor);
        $validationOptions = $this->buildValidationOptions($actor);
        $packages = $this->requirePackages();

        $mode = (string) ($options['mode'] ?? StudioPackageService::MODE_CREATE);
        $pageType = (string) ($source['page_type'] ?? 'page');
        if ($mode === StudioPackageService::MODE_REPLACE) {
            // The document type must match the (tenant-scoped) target; a non-page target is refused by the plan.
            $row = is_int($options['target_page_id'] ?? null) ? $this->pageRepo->find((int) $options['target_page_id']) : null;
            $pageType = $row !== null && in_array((string) $row['page_type'], HtmlImportConverter::PAGE_TYPES, true) ? (string) $row['page_type'] : 'page';
        }
        $theme = $this->themes?->resolve(ThemeResolver::DEFAULT_GROUP)->tokens() ?? ThemeResolver::DEFAULT_TOKENS;

        $converted = ($this->htmlImporter ?? new HtmlImportConverter())->convert(
            (string) $source['html'],
            (string) $source['css'],
            ['title' => $source['title'] ?? null, 'slug' => $source['slug'] ?? null, 'page_type' => $pageType],
            $theme,
        );
        $report = new StudioImportReport($mode, $dryRun, $converted['package_hash'], StudioImportReport::SOURCE_HTML_CSS);
        $report->setSource($converted['source_hash'], $converted['conversion']);
        $report->setItemOrder(HtmlImportConverter::ITEM_KEY, 0);
        foreach ($converted['issues'] as $issue) {
            $report->addIssue($issue);
        }
        if ($converted['package'] === null) {
            return ['report' => $report->toArray(null), 'target' => null];
        }

        $plan = $packages->plan($converted['package'], $options, static fn(string $perm): bool => $actor->can($perm), $validationOptions, $dryRun, $report);
        if ($dryRun || $report->hasErrors()) {
            return ['report' => $report->toArray(null), 'target' => null];
        }
        return $this->commitImportPlan($actor, $plan, $report, $revisionKind, $validationOptions, 'Imported from HTML ' . substr($converted['source_hash'], 0, 12), [
            'source_kind' => StudioImportReport::SOURCE_HTML_CSS,
            'source_hash' => $converted['source_hash'],
        ]);
    }

    /**
     * The commit half shared by every import source: ONE transaction owned
     * here runs the planned writes through the canonical services; after it
     * committed (never inside it — AuditLog can implicitly commit), caches
     * are invalidated and the import is audited.
     *
     * @param array{report: StudioImportReport, work: list<array<string, mixed>>} $plan
     * @param array<string, mixed> $validationOptions
     * @param array<string, string> $sourceMeta extra audit metadata of a converted source (none for kohevo_json)
     * @return array{report: array<string, mixed>, target: ?array<string, mixed>}
     */
    private function commitImportPlan(StudioActor $actor, array $plan, StudioImportReport $report, string $revisionKind, array $validationOptions, string $summary, array $sourceMeta): array
    {
        $packages = $this->requirePackages();
        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }
        try {
            $committed = $packages->commit($plan['work'], (int) $actor->userId, $validationOptions, $revisionKind, $summary);
            if ($ownsTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // After commit only (no audit inside an owned transaction — see mutateDocument()).
        foreach ($committed['tokens'] as $t) {
            $this->invalidator?->invalidateTokenGroup((string) $t['token_group']);
            $this->audit($actor, 'studio.tokens.imported', (string) $t['token_group'], ['package_hash' => $report->packageHash]);
        }
        foreach ($committed['templates'] as $t) {
            $this->invalidator?->invalidateTemplate((string) $t['template_key']);
            $this->audit($actor, 'studio.template.imported', (string) $t['template_key'], ['package_hash' => $report->packageHash]);
        }
        foreach ($committed['global_components'] as $c) {
            $this->audit($actor, 'studio.component.imported', (string) $c['page_id'], ['package_hash' => $report->packageHash, 'revision_id' => $c['revision_id'], 'revision_kind' => $revisionKind]);
        }
        foreach ($committed['pages'] as $p) {
            $this->audit($actor, 'studio.page.imported', (string) $p['page_id'], ['package_hash' => $report->packageHash, 'mode' => $p['mode'], 'revision_id' => $p['revision_id'], 'revision_kind' => $revisionKind] + $sourceMeta);
        }
        $public = ['pages' => $committed['pages'], 'global_components' => $committed['global_components'], 'templates' => $committed['templates'], 'tokens' => $committed['tokens']];
        $out = $report->toArray($public);
        $this->audit($actor, 'studio.package.imported', $report->packageHash, [
            'package_hash'   => $report->packageHash,
            'mode'           => $report->mode,
            'counts'         => $out['summary'],
            'page_ids'       => array_column($committed['pages'], 'page_id'),
            'component_ids'  => array_column($committed['global_components'], 'page_id'),
            'template_keys'  => array_column($committed['templates'], 'template_key'),
            'token_groups'   => array_column($committed['tokens'], 'token_group'),
        ] + $sourceMeta);
        return ['report' => $out, 'target' => $committed['target']];
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

    /**
     * Render ONE block as the editor canvas would show it, and save nothing. It lets the canvas show a block the author
     * has just inserted while the save is still on its way: the block goes into a copy of the page's working draft as the
     * only block of one fresh section, through the same preparation as a stored block (an invalid block, or one whose
     * module is not available, renders as the editor's "unavailable" notice). Every id is replaced with a fresh one, in
     * document order, so the caller maps them back by position and a made-up id can never collide with a real one.
     * Requires studio-builder.edit.
     *
     * @param array<string, mixed> $block
     */
    public function renderBlockFragment(StudioActor $actor, int $pageId, array $block): RenderResult
    {
        $tenantId = $this->authorize($actor, StudioPermissions::EDIT);
        $renderer = $this->requireRenderer();
        [$page, $revision] = $this->loadPageRevision($pageId, null);
        $document = CanonicalJson::decode((string) $revision['document_json']);
        $document['sections'] = [[
            'id'         => CanonicalDocumentSchema::newSectionId(),
            'label'      => '',
            'global_ref' => null,
            'layout'     => CanonicalDocumentSchema::defaultSectionLayout(),
            'visibility' => CanonicalDocumentSchema::defaultVisibility(),
            'blocks'     => [self::withFreshBlockIds($block)],
        ]];
        $revision['document_json'] = CanonicalJson::encode($document);
        return $renderer->renderRevision($page, $revision, RenderContext::forEditor($tenantId, $renderer->siteContext(), $actor));
    }

    /**
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function withFreshBlockIds(array $block): array
    {
        $block['id'] = CanonicalDocumentSchema::newBlockId();
        if (isset($block['children']) && is_array($block['children']) && array_is_list($block['children'])) {
            $block['children'] = array_map(
                static fn(mixed $child): mixed => is_array($child) && !array_is_list($child) ? self::withFreshBlockIds($child) : $child,
                $block['children'],
            );
        }
        return $block;
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
     * Translate the Add-panel copy of one block manifest. The registry stays locale-neutral
     * (its manifest is pinned by a fixture); the active admin locale is applied here. Keys are
     * `studio_block_<type with dots as underscores>_title|desc`; the English default is used
     * when the i18n layer is down or a block has no translation.
     *
     * @param array<string, mixed> $block
     * @return array<string, mixed>
     */
    private static function translateBlockCopy(array $block): array
    {
        $slug = str_replace('.', '_', (string) ($block['type'] ?? ''));
        foreach (['title' => 'title', 'description' => 'desc'] as $field => $suffix) {
            $default = (string) ($block[$field] ?? '');
            if ($default === '' || !\function_exists('__')) {
                continue;
            }
            try {
                $block[$field] = (string) \__("studio_block_{$slug}_{$suffix}", $default);
            } catch (\Throwable $ignored) {
                $block[$field] = $default;
            }
        }
        return $block;
    }

    /**
     * A provider's parameter schema as editor data. Every parameter of a pick-list provider (the id of one of the tenant's
     * forms or services) carries its `choices` for the ACTIVE tenant, so the Inspector shows a dropdown; its label is
     * translated like the block copy (`studio_param_<provider key with dots as underscores>_<param>`).
     *
     * @return list<array<string, mixed>>
     */
    public static function providerParams(DataProviderInterface $provider): array
    {
        $slug = str_replace('.', '_', $provider->key());
        $params = [];
        foreach ($provider->parameterSchema()->toEditorManifest() as $param) {
            $default = (string) ($param['label'] ?? '');
            if ($default !== '' && \function_exists('__')) {
                try {
                    $param['label'] = (string) \__("studio_param_{$slug}_{$param['key']}", $default);
                } catch (\Throwable $ignored) {
                    $param['label'] = $default;
                }
            }
            if ($provider instanceof ParamChoicesProviderInterface) {
                // An empty list is still a pick-list ("no forms yet"), never a free number box.
                $param['choices'] = array_slice($provider->paramChoices((string) $param['key']), 0, ParamChoicesProviderInterface::MAX_CHOICES);
            }
            $params[] = $param;
        }
        return $params;
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
                'params'      => self::providerParams($provider),
            ];
        }

        $tokens = [];
        $themeTokens = $this->themes !== null ? $this->themes->resolve(ThemeResolver::DEFAULT_GROUP)->tokens() : ThemeResolver::DEFAULT_TOKENS;
        foreach ($themeTokens as $ref => $value) {
            $tokens[] = ['category' => explode('.', (string) $ref, 2)[0], 'ref' => (string) $ref, 'value' => (string) $value];
        }

        $disabledTypes = $this->availability?->disabled($tenantId) ?? [];
        $blocks = array_map(
            static fn(array $block): array => self::translateBlockCopy($block) + (in_array($block['type'] ?? '', $disabledTypes, true) ? ['disabled' => true] : []),
            $this->registry->editorManifests($entitled, static fn(string $perm): bool => $actor->can($perm), true),
        );
        $offered = array_column(array_filter($blocks, static fn(array $b): bool => empty($b['locked'])), 'type');

        return [
            'blocks'      => $blocks,
            'variants'    => array_values(array_filter(
                array_map(static fn(array $v): array => self::translateVariantCopy($v), ElementVariantCatalog::all()),
                static fn(array $v): bool => in_array($v['type'], $offered, true),
            )),
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
                'seo_description_max' => CanonicalDocumentSchema::SEO_DESCRIPTION_MAX_LENGTH,
                'seo_title_max'      => CanonicalDocumentSchema::SEO_TITLE_MAX_LENGTH,
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

    /**
     * One revision of one page, as a summary (never the document body). The
     * revision must belong to THAT page of the active tenant — an id from
     * another page or tenant is simply not found.
     *
     * @return array<string, mixed>
     */
    public function findRevision(StudioActor $actor, int $pageId, int $revisionId): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        if ($this->pageRepo->find($pageId) === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $revision = $this->revisionRepo->findByIdForPage($pageId, $revisionId);
        if ($revision === null) {
            throw new StudioNotFoundException('The requested revision was not found for this page in the active tenant.', ['page_id' => $pageId]);
        }
        return StudioEditorViews::revision($revision);
    }

    /**
     * Structured, human-readable diff between two revisions of ONE page
     * (Phase 7 review workflow). `$proposedRevisionId` defaults to the current
     * working draft; `$baseRevisionId` defaults to the proposed revision's
     * parent, else the page's published revision, else an empty document.
     * Both ids are resolved through the tenant-scoped repository for THIS
     * page, so a foreign revision id is "not found", never compared. Read-only.
     *
     * @return array<string, mixed>
     */
    public function diffRevisions(StudioActor $actor, int $pageId, ?int $baseRevisionId = null, ?int $proposedRevisionId = null): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }
        $draftId     = isset($page['active_draft_revision_id']) ? (int) $page['active_draft_revision_id'] : 0;
        $publishedId = isset($page['published_revision_id']) ? (int) $page['published_revision_id'] : 0;

        $proposedId = $proposedRevisionId ?? ($draftId > 0 ? $draftId : null);
        $proposed   = $proposedId !== null && $proposedId > 0 ? $this->revisionRepo->findByIdForPage($pageId, $proposedId) : null;
        if ($proposed === null) {
            throw new StudioNotFoundException('The requested revision was not found for this page in the active tenant.', ['page_id' => $pageId]);
        }

        $baseId = $baseRevisionId
            ?? (isset($proposed['parent_revision_id']) && (int) $proposed['parent_revision_id'] > 0 ? (int) $proposed['parent_revision_id'] : null)
            ?? ($publishedId > 0 ? $publishedId : null);
        $base = null;
        if ($baseId !== null && $baseId > 0) {
            $base = $this->revisionRepo->findByIdForPage($pageId, $baseId);
            if ($base === null) {
                throw new StudioNotFoundException('The requested base revision was not found for this page in the active tenant.', ['page_id' => $pageId]);
            }
        }

        $baseDocument     = $base !== null ? CanonicalJson::decode((string) $base['document_json']) : CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);
        $proposedDocument = CanonicalJson::decode((string) $proposed['document_json']);

        $depsBefore = $this->dependencySignatures($baseDocument);
        $depsAfter  = $this->dependencySignatures($proposedDocument);
        $depAdded   = array_values(array_udiff($depsAfter, $depsBefore, static fn(array $a, array $b): int => strcmp($a['type'] . '|' . $a['key'], $b['type'] . '|' . $b['key'])));
        $depRemoved = array_values(array_udiff($depsBefore, $depsAfter, static fn(array $a, array $b): int => strcmp($a['type'] . '|' . $a['key'], $b['type'] . '|' . $b['key'])));

        $pageType   = (string) $page['page_type'];
        $dependents = null;
        if ($pageType === CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE && $this->components !== null) {
            $dependents = count($this->components->dependentPageIds((string) $page['uuid']));
        }

        return [
            'page'              => StudioEditorViews::page($page),
            'base_revision'     => $base !== null ? StudioEditorViews::revision($base) : null,
            'proposed_revision' => StudioEditorViews::revision($proposed),
            'diff'              => RevisionDiff::compare($baseDocument, $proposedDocument),
            'publish_impact'    => [
                'page_status'               => (string) $page['status'],
                'published_revision_id'     => $publishedId > 0 ? $publishedId : null,
                'proposed_is_current_draft' => $proposedId === $draftId,
                'proposed_is_published'     => $publishedId > 0 && $proposedId === $publishedId,
                'requires_publish'          => $proposedId !== $publishedId,
                'shared_content'            => in_array($pageType, [CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE, 'header_partial', 'footer_partial'], true),
                'dependent_pages'           => $dependents,
            ],
            'dependency_impact' => ['added' => $depAdded, 'removed' => $depRemoved],
        ];
    }

    /**
     * Distinct (type, key) dependencies of a canonical document — what
     * publishing it would bind the page to (media, modules, templates,
     * components, chrome). @param array<string, mixed> $document
     * @return list<array{type: string, key: string}>
     */
    private function dependencySignatures(array $document): array
    {
        $out = [];
        try {
            foreach (DependencyExtractor::extract($document, $this->registry) as $record) {
                $sig = $record->dependencyType . '|' . $record->dependencyKey;
                $out[$sig] = ['type' => $record->dependencyType, 'key' => $record->dependencyKey];
            }
        } catch (\Throwable $ignored) {
            // A historical revision that no longer extracts cleanly contributes nothing.
        }
        ksort($out, SORT_STRING);
        return array_values($out);
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

    /**
     * The revision kind a draft write is RECORDED as. An AI-origin actor
     * (mcp_token / admin_assistant) always produces `ai_operation`; a human
     * session may only write the editor kinds, so `ai_operation` (and any
     * other kind) requested by a session is rejected here — never trusted
     * from a client, never reachable except through the AI actor path.
     */
    private function draftKind(StudioActor $actor, string $requested): string
    {
        if ($actor->isAiOrigin()) {
            return self::AI_REVISION_KIND;
        }
        if (!in_array($requested, self::EDITOR_REVISION_KINDS, true)) {
            throw new StudioValidationException([
                ['path' => '$.revision_kind', 'code' => 'invalid_revision_kind', 'message' => 'revision_kind must be autosave or manual.'],
            ]);
        }
        return $requested;
    }

    /**
     * The revision kind of a package import: `import`, and ONLY for a human
     * session. An AI-origin actor can never import (Phase 8A adds no MCP
     * import tool; this is the defensive backstop), and `draftKind()` is
     * unchanged — no editor or AI write can select `import`.
     */
    private function importKind(StudioActor $actor): string
    {
        if ($actor->isAiOrigin()) {
            throw new StudioAuthorizationException(StudioPermissions::EDIT);
        }
        return self::IMPORT_REVISION_KIND;
    }

    private function requirePackages(): StudioPackageService
    {
        if ($this->packages === null) {
            $this->packages = new StudioPackageService(
                $this->pageRepo, $this->revisionRepo, $this->templateRepo,
                $this->pages, $this->revisions, $this->templates, $this->themeService,
                $this->registry, new StudioReservedRoutes(),
            );
        }
        return $this->packages;
    }

    /**
     * The ONE way this class writes the platform audit log: every Studio
     * event carries the actor's explicit attribution (origin / user_id /
     * token_id) merged with the event's own metadata. Never called inside a
     * transaction this class owns (see the Phase 6 transaction rule).
     *
     * @param array<string, mixed> $meta
     */
    private function audit(StudioActor $actor, string $action, string $target, array $meta = []): void
    {
        AuditLog::record($action, $target, $meta + $actor->auditAttribution());
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
