<?php
/**
 * Kohevo Studio (studio-builder) — RBAC Permission Key Vocabulary.
 *
 * The single source of truth for the 5 `studio-builder.*` permission keys
 * declared in `plugins/studio-builder/plugin.json` (Phase 1) and enforced by
 * `StudioActor`/`StudioApplicationService` (Phase 3). Kept as one small,
 * dependency-free class so both the RBAC boundary and anything needing to
 * enumerate "every Studio permission" (a settings/roles admin page, tests)
 * read from exactly one place.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder;

final class StudioPermissions
{
    public const VIEW    = 'studio-builder.view';
    public const EDIT    = 'studio-builder.edit';
    public const PUBLISH = 'studio-builder.publish';
    public const TOKENS  = 'studio-builder.tokens';
    public const ADMIN   = 'studio-builder.admin';

    public const ALL = [
        self::VIEW,
        self::EDIT,
        self::PUBLISH,
        self::TOKENS,
        self::ADMIN,
    ];

    private function __construct() {}
}
