<?php
/**
 * Kohevo Studio (studio-builder) — Revision Lifecycle & Concurrency Service.
 *
 * Orchestrates immutable Studio revisions (`studiobuilder_revisions`), page
 * revision pointers (`studiobuilder_pages.active_draft_revision_id` and
 * `published_revision_id`), and revision dependency records (`studiobuilder_dependencies`).
 *
 * Invariants:
 * 1. Revisions are strictly append-only and immutable.
 * 2. Every revision allocation locks the parent `studiobuilder_pages` row (`FOR UPDATE`)
 *    inside a database transaction.
 * 3. `expected_revision_id` is verified against the locked page's `active_draft_revision_id`
 *    (fail-closed `StudioConcurrencyException` on mismatch).
 * 4. `revision_number` is monotonic per `(tenant_id, page_id)` (`1, 2, 3, ...`).
 * 5. `parent_revision_id` records the exact lineage revision ID (`null` only on initial revision #1).
 * 6. Autosaves (`revision_kind = 'autosave'`) whose canonical SHA-256 fingerprint matches
 *    the current working revision do not create redundant rows.
 * 7. Rollback creates a NEW revision (`revision_kind = 'rollback'`) with `parent_revision_id`
 *    pointing to the target historical revision; historical revisions are never mutated.
 * 8. `published_revision_id` is only modified by `publishWorkingRevision()` / `unpublishPage()`.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Exception\StudioConcurrencyException;
use Slate\Module\StudioBuilder\Exception\StudioNotFoundException;
use Slate\Module\StudioBuilder\Exception\StudioTenantScopeException;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;
use Slate\Module\StudioBuilder\Registry\BlockRegistry;
use Slate\Module\StudioBuilder\Repository\DependencyRepository;
use Slate\Module\StudioBuilder\Repository\PageRepository;
use Slate\Module\StudioBuilder\Repository\RevisionRepository;
use Slate\Tenancy\TenantContext;

final class StudioRevisionService
{
    public const ALLOWED_REVISION_KINDS = [
        'autosave',
        'manual',
        'publish',
        'rollback',
        'ai_operation',
        'import',
    ];

    public const WRITABLE_DRAFT_KINDS = [
        'autosave',
        'manual',
        'ai_operation',
        'import',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PageRepository $pages,
        private readonly RevisionRepository $revisions,
        private readonly DependencyRepository $dependencies,
        private readonly BlockRegistry $registry,
    ) {}

    /**
     * Create a new immutable draft revision for a page (`manual`, `autosave`, `ai_operation`, `import`).
     *
     * @param array<string, mixed> $normalizedDocument Must already be validated and normalized
     * @return array{
     *   revision: array<string, mixed>,
     *   page: array<string, mixed>,
     *   fingerprint: string,
     *   deduplicated: bool
     * }
     */
    public function createDraftRevision(
        int $pageId,
        array $normalizedDocument,
        ?int $expectedRevisionId,
        int $actorId,
        string $revisionKind = 'manual',
        ?string $summary = null,
    ): array {
        $tenantId = $this->requireTenantId();

        if (!in_array($revisionKind, self::WRITABLE_DRAFT_KINDS, true)) {
            throw new StudioValidationException([
                ['path' => '$.revision_kind', 'code' => 'invalid_revision_kind', "message" => "Invalid draft revision_kind '{$revisionKind}'."],
            ]);
        }

        $canonicalJson = CanonicalJson::encode($normalizedDocument);
        $fingerprint   = hash('sha256', $canonicalJson);
        $deps          = DependencyExtractor::extract($normalizedDocument, $this->registry);

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $lockedPage = $this->lockPageRow($tenantId, $pageId);
            $currentDraftId = $lockedPage['active_draft_revision_id'] !== null
                ? (int) $lockedPage['active_draft_revision_id']
                : null;

            $this->assertExpectedRevision($pageId, $currentDraftId, $expectedRevisionId);

            // Autosave deduplication: if the working draft already has the exact same SHA-256 fingerprint,
            // return the existing working revision without inserting a redundant row.
            if ($revisionKind === 'autosave' && $currentDraftId !== null) {
                $currentDraft = $this->revisions->findByIdForPage($pageId, $currentDraftId);
                if ($currentDraft !== null) {
                    $existingHash = hash('sha256', (string) $currentDraft['document_json']);
                    if (hash_equals($existingHash, $fingerprint)) {
                        if ($ownsTx) {
                            $pdo->commit();
                        }
                        return [
                            'revision'     => $currentDraft,
                            'page'         => $lockedPage,
                            'fingerprint'  => $fingerprint,
                            'deduplicated' => true,
                        ];
                    }
                }
            }

            $nextNumber = $this->nextRevisionNumber($tenantId, $pageId);
            $cleanSummary = $summary !== null && trim($summary) !== ''
                ? mb_substr(trim($summary), 0, 255, 'UTF-8')
                : null;

            $newRevisionId = $this->revisions->insert([
                'page_id'            => $pageId,
                'revision_number'    => $nextNumber,
                'revision_kind'      => $revisionKind,
                'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                'document_json'      => $canonicalJson,
                'summary'            => $cleanSummary,
                'parent_revision_id' => $currentDraftId,
                'created_by'         => $actorId > 0 ? $actorId : null,
            ]);

            $this->dependencies->replaceForPageRevision($pageId, $newRevisionId, $deps);

            $this->pages->update($pageId, [
                'active_draft_revision_id' => $newRevisionId,
                'seo_json'                 => CanonicalJson::encode($normalizedDocument['seo'] ?? CanonicalDocumentSchema::defaultSeo()),
                'settings_json'            => CanonicalJson::encode($normalizedDocument['settings'] ?? CanonicalDocumentSchema::defaultSettings()),
                'updated_by'               => $actorId > 0 ? $actorId : null,
            ]);

            $updatedPage = $this->pages->find($pageId);
            $createdRev  = $this->revisions->findByIdForPage($pageId, $newRevisionId);

            if ($ownsTx) {
                $pdo->commit();
            }

            return [
                'revision'     => $createdRev ?? [],
                'page'         => $updatedPage ?? $lockedPage,
                'fingerprint'  => $fingerprint,
                'deduplicated' => false,
            ];
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                throw new StudioConcurrencyException(
                    'Concurrent revision allocation conflict detected.',
                    ['page_id' => $pageId],
                );
            }
            throw $e;
        }
    }

    /**
     * Promote the current working draft revision to a new immutable published revision (`revision_kind = 'publish'`).
     *
     * @param array<string, mixed> $validationOptions
     * @return array{
     *   revision: array<string, mixed>,
     *   page: array<string, mixed>,
     *   fingerprint: string
     * }
     */
    public function publishWorkingRevision(
        int $pageId,
        ?int $expectedRevisionId,
        int $actorId,
        ?string $summary = null,
        array $validationOptions = [],
    ): array {
        $tenantId = $this->requireTenantId();

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $lockedPage = $this->lockPageRow($tenantId, $pageId);
            $currentDraftId = $lockedPage['active_draft_revision_id'] !== null
                ? (int) $lockedPage['active_draft_revision_id']
                : null;

            if ($currentDraftId === null) {
                throw new StudioValidationException([
                    ['path' => '$.active_draft_revision_id', 'code' => 'missing_working_revision', 'message' => 'Cannot publish a page that has no working draft revision.'],
                ]);
            }

            if ($expectedRevisionId !== null) {
                $this->assertExpectedRevision($pageId, $currentDraftId, $expectedRevisionId);
            }

            $workingRev = $this->revisions->findByIdForPage($pageId, $currentDraftId);
            if ($workingRev === null) {
                throw new StudioNotFoundException("Working draft revision {$currentDraftId} not found for page {$pageId}.");
            }

            // Re-validate and normalize the working revision's canonical document before publishing
            $normalizedDoc = DocumentNormalizer::validateAndNormalize(
                (string) $workingRev['document_json'],
                $this->registry,
                $validationOptions
            );
            $canonicalJson = CanonicalJson::encode($normalizedDoc);
            $fingerprint   = hash('sha256', $canonicalJson);
            $deps          = DependencyExtractor::extract($normalizedDoc, $this->registry);

            $nextNumber = $this->nextRevisionNumber($tenantId, $pageId);
            $cleanSummary = $summary !== null && trim($summary) !== ''
                ? mb_substr(trim($summary), 0, 255, 'UTF-8')
                : 'Published revision #' . $workingRev['revision_number'];

            $publishRevId = $this->revisions->insert([
                'page_id'            => $pageId,
                'revision_number'    => $nextNumber,
                'revision_kind'      => 'publish',
                'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                'document_json'      => $canonicalJson,
                'summary'            => $cleanSummary,
                'parent_revision_id' => $currentDraftId,
                'created_by'         => $actorId > 0 ? $actorId : null,
            ]);

            $this->dependencies->replaceForPageRevision($pageId, $publishRevId, $deps);

            $now = \function_exists('slate_db_now') ? \slate_db_now() : gmdate('Y-m-d H:i:s');
            $this->pages->update($pageId, [
                'active_draft_revision_id' => $publishRevId,
                'published_revision_id'    => $publishRevId,
                'status'                   => 'published',
                'published_at'             => $now,
                'seo_json'                 => CanonicalJson::encode($normalizedDoc['seo'] ?? CanonicalDocumentSchema::defaultSeo()),
                'settings_json'            => CanonicalJson::encode($normalizedDoc['settings'] ?? CanonicalDocumentSchema::defaultSettings()),
                'updated_by'               => $actorId > 0 ? $actorId : null,
            ]);

            $updatedPage = $this->pages->find($pageId);
            $createdRev  = $this->revisions->findByIdForPage($pageId, $publishRevId);

            if ($ownsTx) {
                $pdo->commit();
            }

            return [
                'revision'    => $createdRev ?? [],
                'page'        => $updatedPage ?? $lockedPage,
                'fingerprint' => $fingerprint,
            ];
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                throw new StudioConcurrencyException(
                    'Concurrent publish revision allocation conflict detected.',
                    ['page_id' => $pageId],
                );
            }
            throw $e;
        }
    }

    /**
     * Roll back a page's working draft to a historical revision by creating a NEW immutable
     * revision (`revision_kind = 'rollback'`) whose `parent_revision_id` points to `$targetRevisionId`.
     * Historical revisions are never modified or deleted.
     *
     * @param array<string, mixed> $validationOptions
     * @return array{
     *   revision: array<string, mixed>,
     *   page: array<string, mixed>,
     *   fingerprint: string,
     *   rolled_back_from_revision_id: int
     * }
     */
    public function rollbackToRevision(
        int $pageId,
        int $targetRevisionId,
        ?int $expectedRevisionId,
        int $actorId,
        ?string $summary = null,
        array $validationOptions = [],
    ): array {
        $tenantId = $this->requireTenantId();

        $pdo = Database::get();
        $ownsTx = !$pdo->inTransaction();
        if ($ownsTx) {
            $pdo->beginTransaction();
        }

        try {
            $lockedPage = $this->lockPageRow($tenantId, $pageId);
            $currentDraftId = $lockedPage['active_draft_revision_id'] !== null
                ? (int) $lockedPage['active_draft_revision_id']
                : null;

            $this->assertExpectedRevision($pageId, $currentDraftId, $expectedRevisionId);

            $targetRev = $this->revisions->findByIdForPage($pageId, $targetRevisionId);
            if ($targetRev === null) {
                throw new StudioNotFoundException(
                    "Target revision {$targetRevisionId} does not exist for page {$pageId} in the active tenant.",
                    ['page_id' => $pageId, 'target_revision_id' => $targetRevisionId]
                );
            }

            $normalizedDoc = DocumentNormalizer::validateAndNormalize(
                (string) $targetRev['document_json'],
                $this->registry,
                $validationOptions
            );
            $canonicalJson = CanonicalJson::encode($normalizedDoc);
            $fingerprint   = hash('sha256', $canonicalJson);
            $deps          = DependencyExtractor::extract($normalizedDoc, $this->registry);

            $nextNumber = $this->nextRevisionNumber($tenantId, $pageId);
            $cleanSummary = $summary !== null && trim($summary) !== ''
                ? mb_substr(trim($summary), 0, 255, 'UTF-8')
                : 'Rolled back to revision #' . $targetRev['revision_number'];

            $newRevisionId = $this->revisions->insert([
                'page_id'            => $pageId,
                'revision_number'    => $nextNumber,
                'revision_kind'      => 'rollback',
                'schema_version'     => CanonicalDocumentSchema::SCHEMA_VERSION,
                'document_json'      => $canonicalJson,
                'summary'            => $cleanSummary,
                'parent_revision_id' => $targetRevisionId,
                'created_by'         => $actorId > 0 ? $actorId : null,
            ]);

            $this->dependencies->replaceForPageRevision($pageId, $newRevisionId, $deps);

            // Rollback updates only the working draft pointer (active_draft_revision_id);
            // published_revision_id remains unchanged until an explicit publish command.
            $this->pages->update($pageId, [
                'active_draft_revision_id' => $newRevisionId,
                'seo_json'                 => CanonicalJson::encode($normalizedDoc['seo'] ?? CanonicalDocumentSchema::defaultSeo()),
                'settings_json'            => CanonicalJson::encode($normalizedDoc['settings'] ?? CanonicalDocumentSchema::defaultSettings()),
                'updated_by'               => $actorId > 0 ? $actorId : null,
            ]);

            $updatedPage = $this->pages->find($pageId);
            $createdRev  = $this->revisions->findByIdForPage($pageId, $newRevisionId);

            if ($ownsTx) {
                $pdo->commit();
            }

            return [
                'revision'                     => $createdRev ?? [],
                'page'                         => $updatedPage ?? $lockedPage,
                'fingerprint'                  => $fingerprint,
                'rolled_back_from_revision_id' => $targetRevisionId,
            ];
        } catch (\Throwable $e) {
            if ($ownsTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof \PDOException && (string) $e->getCode() === '23000') {
                throw new StudioConcurrencyException(
                    'Concurrent rollback revision allocation conflict detected.',
                    ['page_id' => $pageId],
                );
            }
            throw $e;
        }
    }

    /**
     * Lock the parent `studiobuilder_pages` row (`FOR UPDATE`) within the active tenant.
     *
     * @return array<string, mixed>
     */
    private function lockPageRow(int $tenantId, int $pageId): array
    {
        $row = Database::row(
            'SELECT * FROM `studiobuilder_pages` WHERE `tenant_id` = ? AND `id` = ? FOR UPDATE',
            [$tenantId, $pageId]
        );

        if ($row === null) {
            throw new StudioNotFoundException(
                "Studio page {$pageId} was not found in the active tenant.",
                ['page_id' => $pageId]
            );
        }

        return $row;
    }

    /**
     * Compute the next monotonic revision_number (`1, 2, 3, ...`) for a locked page.
     */
    private function nextRevisionNumber(int $tenantId, int $pageId): int
    {
        $max = (int) Database::value(
            'SELECT COALESCE(MAX(`revision_number`), 0) FROM `studiobuilder_revisions` WHERE `tenant_id` = ? AND `page_id` = ?',
            [$tenantId, $pageId]
        );
        return $max + 1;
    }

    /**
     * Verify optimistic concurrency (`expected_revision_id` vs `active_draft_revision_id`).
     */
    private function assertExpectedRevision(int $pageId, ?int $currentDraftId, ?int $expectedRevisionId): void
    {
        if ($currentDraftId === null) {
            if ($expectedRevisionId !== null && $expectedRevisionId !== 0) {
                throw new StudioConcurrencyException(
                    "Page {$pageId} has no existing draft revision, but expected_revision_id={$expectedRevisionId} was supplied.",
                    [
                        'page_id'              => $pageId,
                        'current_revision_id'  => null,
                        'expected_revision_id' => $expectedRevisionId,
                    ]
                );
            }
            return;
        }

        if ($expectedRevisionId === null || $expectedRevisionId !== $currentDraftId) {
            throw new StudioConcurrencyException(
                "Stale revision conflict on page {$pageId}: expected revision " . ($expectedRevisionId ?? 'null') . ", but current active draft revision is {$currentDraftId}.",
                [
                    'page_id'              => $pageId,
                    'current_revision_id'  => $currentDraftId,
                    'expected_revision_id' => $expectedRevisionId,
                ]
            );
        }
    }

    private function requireTenantId(): int
    {
        if (!$this->tenants->isScoped() || $this->tenants->id() <= 0) {
            throw new StudioTenantScopeException();
        }
        return $this->tenants->id();
    }
}
