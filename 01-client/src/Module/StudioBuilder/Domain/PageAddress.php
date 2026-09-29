<?php
/**
 * Kohevo Studio (studio-builder) — Page Address Value Object.
 *
 * Wraps the identity/lifecycle columns of a `studiobuilder_pages` row (target
 * architecture §3.1). Addressing is deliberately separate from the canonical
 * document: this object never carries `document_json`/`sections` — only a
 * page's route identity (uuid, slug, type), its lifecycle status, and its
 * revision pointers (`active_draft_revision_id`, `published_revision_id`).
 *
 * `tenant_id` is intentionally absent: tenant scope is resolved exclusively via
 * `TenantContext` at the repository layer, never carried in a domain value.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Domain;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;
use Slate\Module\StudioBuilder\Exception\StudioValidationException;

final class PageAddress
{
    public const ALLOWED_STATUSES    = ['draft', 'published', 'scheduled', 'archived'];
    public const ALLOWED_ROUTE_MODES = ['standalone', 'homepage', 'system_override'];
    public const SLUG_PATTERN        = '/^[a-z0-9]([a-z0-9-]{0,189}[a-z0-9])?$/';

    public function __construct(
        public readonly ?int $id,
        public readonly string $uuid,
        public readonly string $title,
        public readonly string $slug,
        public readonly string $pageType,
        public readonly string $status,
        public readonly string $routeMode,
        public readonly ?int $activeDraftRevisionId,
        public readonly ?int $publishedRevisionId,
        public readonly ?string $publishedAt = null,
        public readonly ?string $scheduledFor = null,
    ) {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->uuid)) {
            throw new StudioValidationException([
                ['path' => '$.uuid', 'code' => 'invalid_uuid', 'message' => 'PageAddress uuid must be a valid UUID string.'],
            ]);
        }
        if (trim($this->title) === '' || mb_strlen($this->title, 'UTF-8') > 255) {
            throw new StudioValidationException([
                ['path' => '$.title', 'code' => 'invalid_title', 'message' => 'PageAddress title must be a non-empty string of at most 255 characters.'],
            ]);
        }
        if (preg_match(self::SLUG_PATTERN, $this->slug) !== 1) {
            throw new StudioValidationException([
                ['path' => '$.slug', 'code' => 'invalid_slug', 'message' => 'PageAddress slug must be lowercase alphanumeric with single internal hyphens.'],
            ]);
        }
        if (!in_array($this->pageType, CanonicalDocumentSchema::ALLOWED_DOCUMENT_TYPES, true)) {
            throw new StudioValidationException([
                ['path' => '$.page_type', 'code' => 'invalid_page_type', 'message' => 'PageAddress page_type must be a supported Studio document type.'],
            ]);
        }
        if (!in_array($this->status, self::ALLOWED_STATUSES, true)) {
            throw new StudioValidationException([
                ['path' => '$.status', 'code' => 'invalid_status', 'message' => 'PageAddress status must be one of: ' . implode(', ', self::ALLOWED_STATUSES) . '.'],
            ]);
        }
        if (!in_array($this->routeMode, self::ALLOWED_ROUTE_MODES, true)) {
            throw new StudioValidationException([
                ['path' => '$.route_mode', 'code' => 'invalid_route_mode', 'message' => 'PageAddress route_mode must be one of: ' . implode(', ', self::ALLOWED_ROUTE_MODES) . '.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row A `studiobuilder_pages` row (as returned by PageRepository).
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: isset($row['id']) ? (int) $row['id'] : null,
            uuid: (string) $row['uuid'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            pageType: (string) $row['page_type'],
            status: (string) $row['status'],
            routeMode: (string) $row['route_mode'],
            activeDraftRevisionId: isset($row['active_draft_revision_id']) ? (int) $row['active_draft_revision_id'] : null,
            publishedRevisionId: isset($row['published_revision_id']) ? (int) $row['published_revision_id'] : null,
            publishedAt: isset($row['published_at']) ? (string) $row['published_at'] : null,
            scheduledFor: isset($row['scheduled_for']) ? (string) $row['scheduled_for'] : null,
        );
    }

    public function hasWorkingDraft(): bool
    {
        return $this->activeDraftRevisionId !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->publishedRevisionId !== null;
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function hasUnpublishedChanges(): bool
    {
        return $this->activeDraftRevisionId !== null
            && $this->activeDraftRevisionId !== $this->publishedRevisionId;
    }

    /**
     * Row-shape suitable for `PageRepository::insert()`/`update()` (identity/lifecycle
     * columns only — never `tenant_id`, timestamps, or revision content).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'uuid'                      => $this->uuid,
            'title'                     => $this->title,
            'slug'                      => $this->slug,
            'page_type'                 => $this->pageType,
            'status'                    => $this->status,
            'route_mode'                => $this->routeMode,
            'active_draft_revision_id'  => $this->activeDraftRevisionId,
            'published_revision_id'     => $this->publishedRevisionId,
            'published_at'              => $this->publishedAt,
            'scheduled_for'             => $this->scheduledFor,
        ];
        if ($this->id !== null) {
            $data['id'] = $this->id;
        }
        return $data;
    }

    public function withTitle(string $title): self
    {
        return new self(
            $this->id,
            $this->uuid,
            $title,
            $this->slug,
            $this->pageType,
            $this->status,
            $this->routeMode,
            $this->activeDraftRevisionId,
            $this->publishedRevisionId,
            $this->publishedAt,
            $this->scheduledFor,
        );
    }

    public function withSlug(string $slug): self
    {
        return new self(
            $this->id,
            $this->uuid,
            $this->title,
            $slug,
            $this->pageType,
            $this->status,
            $this->routeMode,
            $this->activeDraftRevisionId,
            $this->publishedRevisionId,
            $this->publishedAt,
            $this->scheduledFor,
        );
    }

    public function withRouteMode(string $routeMode): self
    {
        return new self(
            $this->id,
            $this->uuid,
            $this->title,
            $this->slug,
            $this->pageType,
            $this->status,
            $routeMode,
            $this->activeDraftRevisionId,
            $this->publishedRevisionId,
            $this->publishedAt,
            $this->scheduledFor,
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            $this->id,
            $this->uuid,
            $this->title,
            $this->slug,
            $this->pageType,
            $status,
            $this->routeMode,
            $this->activeDraftRevisionId,
            $this->publishedRevisionId,
            $this->publishedAt,
            $this->scheduledFor,
        );
    }
}
