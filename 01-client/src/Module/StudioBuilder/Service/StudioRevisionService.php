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
 * 9. Publishing a document identical to the one already published (or to a publish revision that is the working
 *    draft) reuses that revision instead of storing another copy.
 * 10. Old autosaves are pruned (see `pruneAutosaves()`): only autosave revisions beyond the page's newest
 *     AUTOSAVE_KEEP that are also older than AUTOSAVE_KEEP_DAYS, and never one the page or another row still uses.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Service;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Dependency\DependencyExtractor;
use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Document\CanonicalJson;
use Slate\Module\StudioBuilder\Document\LayerLock;
use Slate\Module\StudioBuilder\Document\DocumentUpgrader;
use Slate\Module\StudioBuilder\Document\DocumentNormalizer;
use Slate\Module\StudioBuilder\Document\ValidatedDocument;
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

    /** Autosaves always kept per page (the newest ones), whatever their age. */
    public const AUTOSAVE_KEEP = 30;

    /** Autosaves younger than this are always kept. */
    public const AUTOSAVE_KEEP_DAYS = 30;

    /** At most this many old autosaves are pruned per save, so a save never pays for a large backlog at once. */
    public const AUTOSAVE_PRUNE_BATCH = 50;

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
     * Takes a `ValidatedDocument`, not a raw array: `ValidatedDocument::from()` is the
     * only way to construct one, and it always runs the document through
     * `DocumentNormalizer::validateAndNormalize()` first. This closes the Phase 2
     * finding that this method previously trusted its caller's word that a plain
     * array had already been validated.
     *
     * @return array{
     *   revision: array<string, mixed>,
     *   page: array<string, mixed>,
     *   fingerprint: string,
     *   deduplicated: bool
     * }
     */
    public function createDraftRevision(
        int $pageId,
        ValidatedDocument $document,
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

        $normalizedDocument = $document->toArray();
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
            $this->assertNotArchived($lockedPage);
            $currentDraftId = $lockedPage['active_draft_revision_id'] !== null
                ? (int) $lockedPage['active_draft_revision_id']
                : null;

            $this->assertExpectedRevision($pageId, $currentDraftId, $expectedRevisionId);
            $this->assertDocumentType($lockedPage, $normalizedDocument);
            $this->assertLocksKept($pageId, $currentDraftId, $normalizedDocument);
            $this->lockNewComponentRefs($tenantId, $pageId, $currentDraftId, $deps);

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

            // Autosaves are the bulk of history: each one prunes this page's old ones, a bounded batch at a time, under
            // the page row lock it already holds.
            if ($revisionKind === 'autosave') {
                $this->pruneAutosaves($tenantId, $pageId);
            }

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
     *   fingerprint: string,
     *   deduplicated: bool
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
            $this->assertNotArchived($lockedPage);
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

            // Re-validate and normalize the working revision's canonical document before publishing (blocks saved at an
            // older block version are upgraded first; the published revision is a new row, history is never rewritten)
            $normalizedDoc = DocumentNormalizer::validateAndNormalize(
                self::withPageType(DocumentUpgrader::upgrade(CanonicalJson::decode((string) $workingRev['document_json']), $this->registry)['document'], $lockedPage),
                $this->registry,
                $validationOptions
            );
            $canonicalJson = CanonicalJson::encode($normalizedDoc);
            $fingerprint   = hash('sha256', $canonicalJson);

            // anti-drift-ignore: CLOCK — slate_db_now() (MySQL's own clock) is always
            // preferred; the gmdate() fallback only runs in an isolated context (e.g. a
            // unit test) where includes/helpers.php was never loaded, and is never reached
            // in a real request.
            $now = \function_exists('slate_db_now') ? \slate_db_now() : gmdate('Y-m-d H:i:s');

            // Nothing changed since the last publish: reuse that publish revision (the page's published one, or the
            // working draft when it is itself a publish revision, e.g. after an unpublish) instead of storing a copy.
            $reusable = $this->reusablePublishRevision($pageId, $lockedPage, $workingRev, $canonicalJson);
            if ($reusable !== null) {
                $reuseId = (int) $reusable['id'];
                $alreadyLive = ($lockedPage['status'] ?? null) === 'published' && (int) ($lockedPage['published_revision_id'] ?? 0) === $reuseId;
                $this->pages->update($pageId, [
                    'active_draft_revision_id' => $reuseId,
                    'published_revision_id'    => $reuseId,
                    'status'                   => 'published',
                    'published_at'             => $alreadyLive && !empty($lockedPage['published_at']) ? $lockedPage['published_at'] : $now,
                    'seo_json'                 => CanonicalJson::encode($normalizedDoc['seo'] ?? CanonicalDocumentSchema::defaultSeo()),
                    'settings_json'            => CanonicalJson::encode($normalizedDoc['settings'] ?? CanonicalDocumentSchema::defaultSettings()),
                    'updated_by'               => $actorId > 0 ? $actorId : null,
                ]);
                $updatedPage = $this->pages->find($pageId);
                if ($ownsTx) {
                    $pdo->commit();
                }
                return [
                    'revision'     => $reusable,
                    'page'         => $updatedPage ?? $lockedPage,
                    'fingerprint'  => $fingerprint,
                    'deduplicated' => true,
                ];
            }

            $deps = DependencyExtractor::extract($normalizedDoc, $this->registry);

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
            $this->assertNotArchived($lockedPage);
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
                self::withPageType(DocumentUpgrader::upgrade(CanonicalJson::decode((string) $targetRev['document_json']), $this->registry)['document'], $lockedPage),
                $this->registry,
                $validationOptions
            );
            $this->assertLocksKept($pageId, $currentDraftId, $normalizedDoc);
            $canonicalJson = CanonicalJson::encode($normalizedDoc);
            $fingerprint   = hash('sha256', $canonicalJson);
            $deps          = DependencyExtractor::extract($normalizedDoc, $this->registry);
            $this->lockNewComponentRefs($tenantId, $pageId, $currentDraftId, $deps);

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
     * A draft's `document_type` must be its page's type. Callers set it from the page (the client never decides it);
     * this is the last check before anything is stored.
     *
     * @param array<string, mixed> $lockedPage
     * @param array<string, mixed> $document
     */
    private function assertDocumentType(array $lockedPage, array $document): void
    {
        $pageType = (string) ($lockedPage['page_type'] ?? '');
        if ($pageType !== '' && (string) ($document['document_type'] ?? '') !== $pageType) {
            throw new StudioValidationException([
                ['path' => '$.document_type', 'code' => 'document_type_mismatch', 'message' => "This page is a '{$pageType}', so its document must be one too."],
            ]);
        }
    }

    /**
     * A stored document read back for publish or rollback takes its page's type (an older draft may say otherwise).
     *
     * @param mixed $document
     * @param array<string, mixed> $lockedPage
     * @return mixed
     */
    private static function withPageType(mixed $document, array $lockedPage): mixed
    {
        if (is_array($document) && !empty($lockedPage['page_type'])) {
            $document['document_type'] = (string) $lockedPage['page_type'];
        }
        return $document;
    }

    /**
     * Component references this draft ADDS (not already in the current draft) are re-checked under a shared lock on
     * the component's row: archiving a component holds an exclusive lock on that row while it checks for references,
     * so either the archive sees this draft's reference, or this draft sees the component archived — never neither.
     *
     * @param list<\Slate\Module\StudioBuilder\Dependency\DependencyRecord> $deps
     */
    private function lockNewComponentRefs(int $tenantId, int $pageId, ?int $currentDraftId, array $deps): void
    {
        $refs = [];
        foreach ($deps as $dep) {
            // Section component references only (a uuid); `chrome:*` and `template:*` keys are not components.
            if ($dep->dependencyType === 'partial' && preg_match(CanonicalDocumentSchema::COMPONENT_REF_PATTERN, $dep->dependencyKey) === 1) {
                $refs[$dep->dependencyKey] = true;
            }
        }
        if ($refs === []) {
            return;
        }
        if ($currentDraftId !== null) {
            foreach ($this->dependencies->forPageRevision($pageId, $currentDraftId) as $row) {
                if (($row['dependency_type'] ?? null) === 'partial') {
                    unset($refs[(string) $row['dependency_key']]);
                }
            }
        }
        foreach (array_keys($refs) as $ref) {
            $status = Database::value(
                'SELECT `status` FROM `studiobuilder_pages` WHERE `tenant_id` = ? AND `uuid` = ? AND `page_type` = ? LOCK IN SHARE MODE',
                [$tenantId, (string) $ref, CanonicalDocumentSchema::COMPONENT_DOCUMENT_TYPE]
            );
            if ($status === null || $status === false || $status === 'archived') {
                throw new StudioValidationException([
                    ['path' => '$.sections', 'code' => 'cross_tenant_or_missing_partial', 'message' => "Referenced global_ref '{$ref}' does not exist in the active tenant."],
                ]);
            }
        }
    }

    /**
     * The publish revision a publish can reuse because it already holds exactly this document: the page's published
     * revision, or the working draft when it is a publish revision itself. Null when the document is new.
     *
     * @param array<string, mixed> $lockedPage
     * @param array<string, mixed> $workingRev
     * @return array<string, mixed>|null
     */
    private function reusablePublishRevision(int $pageId, array $lockedPage, array $workingRev, string $canonicalJson): ?array
    {
        if (($workingRev['revision_kind'] ?? null) === 'publish' && hash_equals((string) $workingRev['document_json'], $canonicalJson)) {
            return $workingRev;
        }
        $publishedId = (int) ($lockedPage['published_revision_id'] ?? 0);
        if ($publishedId <= 0 || $publishedId === (int) $workingRev['id']) {
            return null;
        }
        $published = $this->revisions->findByIdForPage($pageId, $publishedId);
        if ($published !== null && ($published['revision_kind'] ?? null) === 'publish' && hash_equals((string) $published['document_json'], $canonicalJson)) {
            return $published;
        }
        return null;
    }

    /**
     * Delete a bounded batch of this page's old autosaves. A revision is pruned only when ALL of these hold:
     *   - it is an autosave (manual saves, publishes, rollbacks, AI operations and imports are kept for good);
     *   - it is not among the page's newest AUTOSAVE_KEEP autosaves, and is older than AUTOSAVE_KEEP_DAYS;
     *   - it is not the page's working draft or published revision, nor the parent of either (the default diff of
     *     the draft compares it with its parent);
     *   - no revision of another kind names it as its parent (a rollback's target, a publish's source);
     *   - no compiled artifact was built from it.
     * Its dependency rows go with it. Runs inside the caller's transaction, under the page row lock; it is housekeeping,
     * so a failure here never fails the save. Returns how many revisions were deleted.
     */
    public function pruneAutosaves(int $tenantId, int $pageId): int
    {
        try {
            $cutoffNumber = Database::value(
                'SELECT `revision_number` FROM `studiobuilder_revisions` WHERE `tenant_id` = ? AND `page_id` = ? AND `revision_kind` = \'autosave\'
                  ORDER BY `revision_number` DESC LIMIT 1 OFFSET ' . (self::AUTOSAVE_KEEP - 1),
                [$tenantId, $pageId]
            );
            if ($cutoffNumber === null || $cutoffNumber === false) {
                return 0; // no more than AUTOSAVE_KEEP autosaves yet
            }
            $page = Database::row(
                'SELECT `active_draft_revision_id`, `published_revision_id` FROM `studiobuilder_pages` WHERE `tenant_id` = ? AND `id` = ?',
                [$tenantId, $pageId]
            );
            $keep = [];
            foreach (['active_draft_revision_id', 'published_revision_id'] as $pointer) {
                $id = (int) ($page[$pointer] ?? 0);
                if ($id > 0) {
                    $keep[$id] = true;
                    $parent = (int) Database::value('SELECT `parent_revision_id` FROM `studiobuilder_revisions` WHERE `tenant_id` = ? AND `id` = ?', [$tenantId, $id]);
                    if ($parent > 0) {
                        $keep[$parent] = true;
                    }
                }
            }
            $rows = Database::rows(
                'SELECT r.`id` FROM `studiobuilder_revisions` r
                  WHERE r.`tenant_id` = ? AND r.`page_id` = ? AND r.`revision_kind` = \'autosave\'
                    AND r.`revision_number` < ?
                    AND r.`created_at` < (NOW() - INTERVAL ' . self::AUTOSAVE_KEEP_DAYS . ' DAY)
                    AND NOT EXISTS (SELECT 1 FROM `studiobuilder_revisions` c
                                     WHERE c.`tenant_id` = r.`tenant_id` AND c.`page_id` = r.`page_id`
                                       AND c.`parent_revision_id` = r.`id` AND c.`revision_kind` <> \'autosave\')
                    AND NOT EXISTS (SELECT 1 FROM `studiobuilder_compilations` m
                                     WHERE m.`tenant_id` = r.`tenant_id` AND m.`revision_id` = r.`id`)
                  ORDER BY r.`revision_number` ASC
                  LIMIT ' . (self::AUTOSAVE_PRUNE_BATCH + count($keep)),
                [$tenantId, $pageId, (int) $cutoffNumber]
            );
            $ids = [];
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!isset($keep[$id]) && count($ids) < self::AUTOSAVE_PRUNE_BATCH) {
                    $ids[] = $id;
                }
            }
            if ($ids === []) {
                return 0;
            }
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $args  = array_merge([$tenantId, $pageId], $ids);
            Database::query("DELETE FROM `studiobuilder_dependencies` WHERE `tenant_id` = ? AND `page_id` = ? AND `revision_id` IN ({$marks})", $args);
            Database::query("DELETE FROM `studiobuilder_revisions` WHERE `tenant_id` = ? AND `page_id` = ? AND `id` IN ({$marks})", $args);
            return count($ids);
        } catch (\PDOException) {
            return 0; // housekeeping only: the save stands, the next autosave tries again
        }
    }

    /**
     * Lock the parent `studiobuilder_pages` row (`FOR UPDATE`) within the active tenant.
     *
     * @return array<string, mixed>
     */
    /**
     * Whole-document draft writes (a saved document, a template applied, a rollback or undo, an import replacing the
     * draft) must leave the current draft's locked layers as they are; operations are checked one by one in the
     * applier, these here (see LayerLock::assertPreserved). A draft without any lock is not decoded.
     *
     * @param array<string, mixed> $next
     */
    private function assertLocksKept(int $pageId, ?int $currentDraftId, array $next): void
    {
        if ($currentDraftId === null) {
            return;
        }
        $current = $this->revisions->findByIdForPage($pageId, $currentDraftId);
        $json = (string) ($current['document_json'] ?? '');
        if ($json === '' || !str_contains($json, '"locked":true')) {
            return;
        }
        $decoded = CanonicalJson::decode($json);
        if (!is_array($decoded)) {
            return;
        }
        // Compare like with like: the new document is upgraded and normalized, so the stored draft is too (a draft
        // saved by an older block version would otherwise look changed everywhere).
        try {
            $decoded = DocumentNormalizer::normalize(DocumentUpgrader::upgrade($decoded, $this->registry)['document'], $this->registry);
        } catch (\Throwable) {
            // compare the stored form as it is
        }
        LayerLock::assertPreserved($decoded, $next);
    }

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
     * An archived page takes no new revisions. Without this, a builder tab left open on a page that was then archived
     * could save or publish it, and publishing put it back on the site (with its custom fields already deleted).
     *
     * @param array<string, mixed> $lockedPage
     */
    private function assertNotArchived(array $lockedPage): void
    {
        if (($lockedPage['status'] ?? null) === 'archived') {
            throw new StudioValidationException([
                ['path' => '$.page_id', 'code' => 'page_archived', 'message' => 'This page has been deleted, so it can no longer be changed or published.'],
            ]);
        }
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
