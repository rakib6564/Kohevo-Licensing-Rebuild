<?php
/**
 * Kohevo Studio (studio-builder) — Render Runtime Mode.
 *
 * The three explicitly separated runtimes that share ONE render pipeline:
 *
 *  - Editor  — the future Phase 5 builder canvas. Authenticated authoring
 *              context (studio-builder.edit), working revision, editor node
 *              metadata (`data-sb-node`), never cached, never indexed.
 *  - Preview — an authorized render of a working (or historical) revision
 *              before publish (studio-builder.view). Same renderer, registry,
 *              theme and dynamic-data rules as Public; noindex/nofollow;
 *              no-store; never persisted.
 *  - Public  — anonymous visitors. Published revision ONLY, anonymous data
 *              context, never any authenticated editor state.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render;

enum RenderMode: string
{
    case Editor  = 'editor';
    case Preview = 'preview';
    case Public  = 'public';

    public function isPublic(): bool
    {
        return $this === self::Public;
    }

    /** Preview and Editor output must never be indexed or stored by any cache. */
    public function isPrivate(): bool
    {
        return $this !== self::Public;
    }
}
