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
 * Cross-reference validation (`media_exists`/`template_exists`/`partial_exists`)
 * and block entitlement/permission enforcement
 * (`entitlement_check`/`permission_check`) are wired ONCE, here, in
 * `buildValidationOptions()` — never scattered into individual block classes.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Application;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Domain\PageAddress;
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
use Slate\Module\StudioBuilder\Render\Compile\StudioCompilationInvalidator;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderResult;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Render\Theme\ThemeResolver;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Service\StudioEditLockService;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
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
        $result = $this->pages->archivePage($pageId, (int) $actor->userId);
        $this->invalidator?->invalidatePage($pageId);
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
        if (!in_array($revisionKind, self::EDITOR_REVISION_KINDS, true)) {
            throw new StudioValidationException([
                ['path' => '$.revision_kind', 'code' => 'invalid_revision_kind', 'message' => 'revision_kind must be autosave or manual.'],
            ]);
        }

        $page = $this->pageRepo->find($pageId);
        if ($page === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }

        $currentDraftId = isset($page['active_draft_revision_id']) && $page['active_draft_revision_id'] !== null
            ? (int) $page['active_draft_revision_id']
            : null;

        if ($currentDraftId !== null) {
            $draftRevision = $this->revisionRepo->findByIdForPage($pageId, $currentDraftId);
            $currentDocument = $draftRevision !== null
                ? CanonicalJson::decode((string) $draftRevision['document_json'])
                : CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);
        } else {
            $currentDocument = CanonicalDocumentSchema::emptyDocument((string) $page['page_type'], 'default', (string) $page['title']);
        }

        $mutatedDocument = DocumentOperationApplier::apply($currentDocument, $operations, $this->registry);
        $validated = ValidatedDocument::from($mutatedDocument, $this->registry, $this->buildValidationOptions($actor));

        $result = $this->revisions->createDraftRevision($pageId, $validated, $expectedRevisionId, (int) $actor->userId, $revisionKind, $summary);
        AuditLog::record('studio.page.operation_applied', (string) $pageId, [
            'operation_count' => count($operations),
            'revision_kind'   => $revisionKind,
            'deduplicated'    => $result['deduplicated'],
        ]);
        return $result;
    }

    // ── Template commands ────────────────────────────────────────────────────

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
    ): array {
        // Template/system administration — StudioPermissions::ADMIN, not EDIT
        // (target architecture example: "system/template administration -> studio-builder.admin").
        $this->authorize($actor, StudioPermissions::ADMIN);
        $result = $this->templates->saveTemplate($templateKey, $templateType, $category, $name, $description, $document, (int) $actor->userId, $this->buildValidationOptions($actor));
        // Pages that reference this template (template_key / section global_ref) recompile on next request.
        $this->invalidator?->invalidateTemplate($templateKey);
        AuditLog::record('studio.template.saved', $templateKey, ['template_type' => $templateType]);
        return $result;
    }

    /**
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function applyTemplate(StudioActor $actor, string $templateKey, int $pageId): array
    {
        $this->authorize($actor, StudioPermissions::EDIT);
        $result = $this->templates->applyTemplate($templateKey, $pageId, (int) $actor->userId, $this->buildValidationOptions($actor));
        AuditLog::record('studio.template.applied', (string) $pageId, ['template_key' => $templateKey]);
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(StudioActor $actor, ?string $templateType = null, ?string $category = null): array
    {
        $this->authorize($actor, StudioPermissions::VIEW);
        return $this->templates->listTemplates($templateType, $category);
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
                'view'    => $actor->can(StudioPermissions::VIEW),
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
     * `partial_exists` reuses the same `studiobuilder_templates` table
     * (`section_preset`/`header_preset`/`footer_preset` types model exactly
     * what target architecture §9 calls a "Reusable Section / Pattern") —
     * Studio has no separate global-component/partial table, and building one
     * before Phase 6 needs it would be exactly the "parallel CMS" table the
     * architecture forbids without a documented lineage decision.
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
                return $this->templateRepo->findByKey($ref) !== null;
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
