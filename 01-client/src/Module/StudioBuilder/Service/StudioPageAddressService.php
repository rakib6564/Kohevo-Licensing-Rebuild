<?php
/**
 * Kohevo Studio (studio-builder) — Page Address Lifecycle Service.
 *
 * Creates and manages `studiobuilder_pages` identity/lifecycle rows
 * (`PageAddress`), independent of canonical document content. Page creation
 * also allocates the page's first immutable revision (a blank canonical
 * document) via `StudioRevisionService`, so a page never exists without at
 * least one revision to work from — the pairing target architecture §4
 * describes as "Page row = address/lifecycle metadata; Revision row =
 * immutable canonical document snapshot".
 *
 * This service intentionally stops at tenant scoping (via the injected
 * repositories/TenantContext). It does not perform actor authentication,
 * RBAC permission checks, or commercial entitlement checks — those belong to
 * the full "Studio Application Layer" command pipeline (target architecture
 * §5), which is a later phase layered on top of this Phase 2 domain.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
use Slate\Module\StudioBuilder\Domain\PageAddress;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Render\StudioRenderService;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Runtime\StudioReservedRoutes;
use Slate\Tenancy\TenantContext;

final class StudioPageAddressService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly StudioRevisionService $revisions,
        private readonly BlockRegistry $registry,
        ?StudioReservedRoutes $reserved = null,
    ) {
        // Always enforced (Phase 9C): there is no way to build this service without the reserved-route rule.
        $this->reserved = $reserved ?? new StudioReservedRoutes();
    }

    private readonly StudioReservedRoutes $reserved;

    /**
     * The public-address invariants (Phase 9C), enforced here because every
     * command path — Builder API, MCP, package/HTML import — ends in this
     * service. A publicly routable page (`page` / `landing`):
     *
     *  - must not use a slug the platform or a module prefix owns (even one
     *    that is merely inactive right now), and
     *  - must not share its slug with the OTHER public type of the same
     *    tenant: the public runtime serves `page` before `landing` for one
     *    slug, so a same-slug pair would silently shadow one of them.
     *
     * Non-public types (header/footer partials, section presets, system) never
     * occupy a public address and are not restricted by either rule.
     */
    private function assertPublicAddressAvailable(string $slug, string $pageType, ?int $exceptPageId): void
    {
        if (!in_array($pageType, StudioRenderService::PUBLIC_PAGE_TYPES, true)) {
            return;
        }
        if ($this->reserved->isReserved($slug)) {
            throw new StudioValidationException([
                ['path' => '$.slug', 'code' => 'reserved_route', 'message' => "'/{$slug}' is reserved by the platform and cannot be a Studio page address."],
            ]);
        }
        foreach (StudioRenderService::PUBLIC_PAGE_TYPES as $other) {
            if ($other === $pageType) {
                continue;
            }
            $existing = $this->pages->findBySlug($slug, $other);
            if ($existing !== null && (int) $existing['id'] !== $exceptPageId) {
                throw new StudioValidationException([
                    ['path' => '$.slug', 'code' => 'route_collision', 'message' => "A {$other} with slug '{$slug}' already exists in this tenant; a {$pageType} cannot share its public address."],
                ]);
            }
        }
    }

    /**
     * Create a new Studio page: inserts its `PageAddress` row and its first
     * immutable draft revision (a blank canonical document) in one transaction.
     *
     * Phase 2 finding F1 fix: the `findBySlug()` pre-check below is
     * necessarily racy (TOCTOU) — two concurrent requests can both pass it
     * before either commits. The `catch` block now recognizes the resulting
     * `uq_studiobuilder_pages_tenant_slug_type` duplicate-key violation
     * (SQLSTATE 23000) and rewraps it as the same clean `StudioValidationException`
     * the pre-check throws, rather than leaking a raw `\PDOException`, matching
     * `StudioRevisionService`'s existing 23000-handling convention.
     *
     * @param array<string, mixed> $validationOptions Forwarded to `ValidatedDocument::from()`.
     * @return array{page: array<string, mixed>, revision: array<string, mixed>}
     */
    public function createPage(
        string $title,
        string $slug,
        string $pageType,
        string $routeMode,
        int $actorId,
        array $validationOptions = [],
        string $revisionKind = 'manual',
    ): array {
        $this->requireTenantId();

        if ($this->pages->findBySlug($slug, $pageType) !== null) {
            throw new StudioValidationException([
                ['path' => '$.slug', 'code' => 'duplicate_slug', 'message' => "A page with slug '{$slug}' and page_type '{$pageType}' already exists in this tenant."],
            ]);
        }

        // Validate identity fields up front via the PageAddress value object
        // (uuid/status/route_mode are placeholders here; only used for shape validation).
        PageAddress::fromRow([
            'uuid'                     => self::newUuidV4(),
            'title'                    => $title,
            'slug'                     => $slug,
            'page_type'                => $pageType,
            'status'                   => 'draft',
            'route_mode'               => $routeMode,
            'active_draft_revision_id' => null,
            'published_revision_id'    => null,
        ]);
        $this->assertPublicAddressAvailable($slug, $pageType, null);

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $pageId = $this->pages->insert([
                'uuid'       => self::newUuidV4(),
                'title'      => $title,
                'slug'       => $slug,
                'page_type'  => $pageType,
                'status'     => 'draft',
                'route_mode' => $routeMode,
                'created_by' => $actorId > 0 ? $actorId : null,
                'updated_by' => $actorId > 0 ? $actorId : null,
            ]);

            $blankDocument = ValidatedDocument::from(
                CanonicalDocumentSchema::emptyDocument($pageType, 'default', $title),
                $this->registry,
                $validationOptions,
            );

            $result = $this->revisions->createDraftRevision(
                $pageId,
                $blankDocument,
                null,
                $actorId,
                $revisionKind,
                'Initial page creation',
            );

            if ($ownsTx) {
                $pdo->commit();
            }

            return ['page' => $result['page'], 'revision' => $result['revision']];
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                throw new StudioValidationException([
                    ['path' => '$.slug', 'code' => 'duplicate_slug', 'message' => "A page with slug '{$slug}' and page_type '{$pageType}' already exists in this tenant."],
                ]);
            }
            throw $e;
        }
    }

    /**
     * Update a page's address fields (title, slug, route_mode). `page_type` is
     * immutable after creation — it participates in the tenant uniqueness key
     * and changing it would silently reinterpret an already-authored document.
     *
     * @param array{title?: string, slug?: string, route_mode?: string} $changes
     * @return array<string, mixed>
     */
    public function updateAddress(int $pageId, array $changes, int $actorId): array
    {
        $tenantId = $this->requireTenantId();
        $current = $this->pages->find($pageId);
        if ($current === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }

        $title     = (string) ($changes['title'] ?? $current['title']);
        $slug      = (string) ($changes['slug'] ?? $current['slug']);
        $routeMode = (string) ($changes['route_mode'] ?? $current['route_mode']);

        $candidate = PageAddress::fromRow(array_merge($current, [
            'title'      => $title,
            'slug'       => $slug,
            'route_mode' => $routeMode,
        ]));

        if ($slug !== $current['slug']) {
            $existing = $this->pages->findBySlug($slug, (string) $current['page_type']);
            if ($existing !== null && (int) $existing['id'] !== $pageId) {
                throw new StudioValidationException([
                    ['path' => '$.slug', 'code' => 'duplicate_slug', 'message' => "A page with slug '{$slug}' and page_type '{$current['page_type']}' already exists in this tenant."],
                ]);
            }
        }

        if ($slug !== $current['slug']) {
            $this->assertPublicAddressAvailable($slug, (string) $current['page_type'], $pageId);
        }

        $this->pages->update($pageId, [
            'title'      => $candidate->title,
            'slug'       => $candidate->slug,
            'route_mode' => $candidate->routeMode,
            'updated_by' => $actorId > 0 ? $actorId : null,
        ]);

        return $this->pages->find($pageId) ?? [];
    }

    /**
     * Archive a page (`status = 'archived'`). Revisions and dependency records
     * are left untouched — archiving is a lifecycle flag, not a deletion.
     *
     * @return array<string, mixed>
     */
    public function archivePage(int $pageId, int $actorId): array
    {
        $this->requireTenantId();
        $current = $this->pages->find($pageId);
        if ($current === null) {
            throw new StudioNotFoundException("Studio page {$pageId} was not found in the active tenant.", ['page_id' => $pageId]);
        }

        $this->pages->update($pageId, [
            'status'     => 'archived',
            'updated_by' => $actorId > 0 ? $actorId : null,
        ]);

        return $this->pages->find($pageId) ?? [];
    }

    public function findAddress(int $pageId): ?PageAddress
    {
        $row = $this->pages->find($pageId);
        return $row === null ? null : PageAddress::fromRow($row);
    }

    public function findAddressBySlug(string $slug, string $pageType = 'page'): ?PageAddress
    {
        $row = $this->pages->findBySlug($slug, $pageType);
        return $row === null ? null : PageAddress::fromRow($row);
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }

    /**
     * Generate an RFC 4122 version-4 UUID string.
     */
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
