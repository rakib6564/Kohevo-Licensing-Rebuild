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
use Slate\Module\StudioBuilder\Operation\DocumentOperation;
use Slate\Module\StudioBuilder\Operation\DocumentOperationApplier;
use Slate\Module\StudioBuilder\Provider\DataProviderRegistry;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Module\StudioBuilder\Repository\TemplateRepository;
use Slate\Module\StudioBuilder\Service\StudioPageAddressService;
use Slate\Module\StudioBuilder\Service\StudioRevisionService;
use Slate\Module\StudioBuilder\Service\StudioTemplateService;
use Slate\Module\StudioBuilder\StudioPermissions;
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
    ) {}

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
     * @param list<DocumentOperation> $operations
     * @return array{revision: array<string, mixed>, page: array<string, mixed>, fingerprint: string, deduplicated: bool}
     */
    public function applyDocumentOperation(
        StudioActor $actor,
        int $pageId,
        array $operations,
        ?int $expectedRevisionId,
        ?string $summary = null,
    ): array {
        $this->authorize($actor, StudioPermissions::EDIT);

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

        $result = $this->revisions->createDraftRevision($pageId, $validated, $expectedRevisionId, (int) $actor->userId, 'manual', $summary);
        AuditLog::record('studio.page.operation_applied', (string) $pageId, ['operation_count' => count($operations)]);
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
        $this->authorize($actor, StudioPermissions::PUBLISH);
        $result = $this->revisions->publishWorkingRevision($pageId, $expectedRevisionId, (int) $actor->userId, $summary, $this->buildValidationOptions($actor));
        AuditLog::record('studio.page.published', (string) $pageId);
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
