<?php
/**
 * Slate — RevisionStore (Phase 3A B4): append-only content revision history.
 *
 * Backs the `content_revisions` table (migration 0004). Every save/publish of a
 * piece of content appends an immutable snapshot, giving the model the history
 * and the separate "working" draft that content-builder's overwrite-in-place
 * savePost lacks (docs/09-Roadmap/phase3a-content-design.md §3, rendering-
 * pipeline.md §5 "working revision" preview).
 *
 * OWNER-AGNOSTIC: content is addressed by (owner_type, owner_id), not a foreign
 * key, so this store snapshots content-builder posts today and any future core
 * `content_pages` rows unchanged.
 *
 * DELIBERATELY SCHEMA-BLIND: the store treats `document` as opaque JSON and never
 * interprets it — that keeps this a pure Data/Services concern with NO dependency
 * on Slate\Presentation (which sits above Services in the layer order). The caller
 * (the B5 content-builder bridge) canonicalizes the document via DocumentSchema
 * before snapshotting and passes the schema version alongside.
 *
 * Tenant scoping is inherited from the base Repository — every read/write carries
 * `AND tenant_id = ?` without this class ever writing it (invariant #2).
 *
 * Layer: Services — depends on Data + Tenancy (via the base Repository) only.
 */

declare(strict_types=1);

namespace Slate\Services\Content;

use Slate\Data\Repository;

final class RevisionStore extends Repository
{
    protected string $table = 'content_revisions';

    public const STATUS_WORKING   = 'working';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED  = 'archived';

    /**
     * Append a new revision for an owner and return its row id. The revision
     * number is the next in sequence for (tenant, owner_type, owner_id).
     *
     * @param array|string $document  a JSON string or an array (json-encoded here)
     * @param int          $schemaVersion  the document's schema version (caller supplies)
     */
    public function snapshot(
        string $ownerType,
        int $ownerId,
        array|string $document,
        string $status = self::STATUS_WORKING,
        ?int $authorId = null,
        ?string $note = null,
        int $schemaVersion = 1,
    ): int {
        $json = $this->encodeDocument($document);
        $status = $this->normalizeStatus($status);

        return $this->insert([
            'owner_type'     => $ownerType,
            'owner_id'       => $ownerId,
            'revision'       => $this->nextRevision($ownerType, $ownerId),
            'status'         => $status,
            'document'       => $json,
            'schema_version' => $schemaVersion,
            'author_id'      => $authorId,
            'note'           => $note !== null ? mb_substr($note, 0, 190) : null,
        ]);
    }

    /**
     * Promote the current working draft to a new published revision (copying its
     * document), returning the new revision's row id — or null if there is no
     * working revision to publish.
     */
    public function publish(string $ownerType, int $ownerId, ?int $authorId = null): ?int
    {
        $working = $this->working($ownerType, $ownerId);
        if ($working === null) {
            return null;
        }
        return $this->snapshot(
            $ownerType,
            $ownerId,
            (string) $working['document'],
            self::STATUS_PUBLISHED,
            $authorId,
            null,
            (int) $working['schema_version'],
        );
    }

    /** A specific revision by row id (tenant-scoped). */
    public function get(int $id): ?array
    {
        $row = $this->find($id);
        return is_array($row) ? $row : null;
    }

    /** The most recent revision for an owner, optionally filtered by status. */
    public function latest(string $ownerType, int $ownerId, ?string $status = null): ?array
    {
        $q = $this->query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId);
        if ($status !== null) {
            $q->where('status', $status);
        }
        return $q->orderBy('revision', 'DESC')->first();
    }

    /** The current working draft (preview source), or null. */
    public function working(string $ownerType, int $ownerId): ?array
    {
        return $this->latest($ownerType, $ownerId, self::STATUS_WORKING);
    }

    /** The current live snapshot, or null. */
    public function published(string $ownerType, int $ownerId): ?array
    {
        return $this->latest($ownerType, $ownerId, self::STATUS_PUBLISHED);
    }

    /**
     * Revision history for an owner, newest first.
     * @return array<int,array>
     */
    public function listForOwner(string $ownerType, int $ownerId, int $limit = 50, int $offset = 0): array
    {
        return $this->query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('revision', 'DESC')
            ->limit(max(1, $limit))
            ->offset(max(0, $offset))
            ->get();
    }

    /** Number of revisions retained for an owner. */
    public function countForOwner(string $ownerType, int $ownerId): int
    {
        return $this->query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->count();
    }

    /**
     * Decode a revision row's `document` JSON to an array (empty on malformed).
     * Kept Presentation-free — callers turn this into a Page via DocumentSchema.
     *
     * @return array<string,mixed>
     */
    public static function documentOf(array $row): array
    {
        $d = json_decode((string) ($row['document'] ?? ''), true);
        return is_array($d) ? $d : [];
    }

    // ── internals ─────────────────────────────────────────────

    /** Next monotonic revision number for (tenant, owner_type, owner_id). */
    private function nextRevision(string $ownerType, int $ownerId): int
    {
        $max = $this->query()
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('revision', 'DESC')
            ->value('revision');
        return $max === null ? 1 : ((int) $max + 1);
    }

    private function encodeDocument(array|string $document): string
    {
        if (is_array($document)) {
            return (string) json_encode($document);
        }
        // A string must be valid JSON — the column enforces json_valid() anyway,
        // but fail early with a clear message rather than a driver error.
        json_decode($document);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('RevisionStore::snapshot document string is not valid JSON.');
        }
        return $document;
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, [self::STATUS_WORKING, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED], true)
            ? $status
            : self::STATUS_WORKING;
    }
}
