<?php
/**
 * Kohevo Studio (studio-builder) — The complete, read-only world a block renderer sees.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

use Slate\Module\StudioBuilder\Render\Media\MediaResolverInterface;
use Slate\Module\StudioBuilder\Render\Media\ResolvedMedia;
use Slate\Module\StudioBuilder\Render\RenderCollector;
use Slate\Module\StudioBuilder\Render\RenderContext;
use Slate\Module\StudioBuilder\Render\RenderMode;
use Slate\Module\StudioBuilder\Render\SiteContext;
use Slate\Module\StudioBuilder\Render\Theme\ResolvedTheme;

final class BlockRenderScope
{
    /**
     * @param array<string, mixed> $block normalized block
     * @param array<string, ?list<array<string, mixed>>> $bindingRows slot => rows (null = unavailable)
     */
    public function __construct(
        private readonly array $block,
        private readonly RenderContext $context,
        private readonly MediaResolverInterface $media,
        private readonly ResolvedTheme $theme,
        private readonly RenderCollector $collector,
        private readonly string $childrenHtml = '',
        private readonly array $bindingRows = [],
    ) {}

    public function type(): string
    {
        return (string) ($this->block['type'] ?? '');
    }

    /**
     * A DOM-id-safe form of this block's node id.
     *
     * Renderers need stable, unique element ids for `aria-controls` /
     * `aria-labelledby` pairs (tabs, accordion) and for fragment targets.
     * Node ids are `blk_<24 hex>`; stripping the non-hex-safe characters
     * leaves a stable, collision-free prefix derived from the document rather
     * than from randomness — so the same document always compiles the same
     * ids, and two blocks on one page can never collide.
     *
     * The fallback matters: a block without an id (hand-built in a test, or
     * mid-migration) still needs *some* id, and returning '' would silently
     * produce `id="-panel"`, which is both invalid and shared.
     */
    public function domId(string $suffix = ''): string
    {
        $raw = (string) ($this->block['id'] ?? '');
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $raw);
        $base = ($safe === null || $safe === '') ? 'sb' : 'sb-' . $safe;
        return $suffix === '' ? $base : $base . '-' . $suffix;
    }

    /** @return array<string, mixed> */
    public function props(): array
    {
        return is_array($this->block['props'] ?? null) ? $this->block['props'] : [];
    }

    public function prop(string $key, mixed $default = null): mixed
    {
        // 1. Dynamic binding property mapping resolution
        $bindings = is_array($this->block['bindings'] ?? null) ? $this->block['bindings'] : [];
        foreach ($bindings as $slot => $binding) {
            if (is_array($binding) && isset($binding['mapping']) && is_array($binding['mapping'])) {
                $mappedField = $binding['mapping'][$key] ?? null;
                if (is_string($mappedField) && isset($this->bindingRows[$slot][0][$mappedField]) && $this->bindingRows[$slot][0][$mappedField] !== null) {
                    return $this->bindingRows[$slot][0][$mappedField];
                }
            }
        }

        $props = $this->props();
        return array_key_exists($key, $props) && $props[$key] !== null ? $props[$key] : $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->prop($key);
        return is_string($v) ? $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $v = $this->prop($key);
        return is_bool($v) ? $v : $default;
    }

    public function mode(): RenderMode
    {
        return $this->context->mode;
    }

    public function context(): RenderContext
    {
        return $this->context;
    }

    public function site(): SiteContext
    {
        return $this->context->site;
    }

    public function showsDiagnostics(): bool
    {
        return $this->context->showsDiagnostics();
    }

    /**
     * Resolve a normalized `media_ref` ({media_id, alt, focal_point}) to a
     * servable image of the CURRENT tenant, or null.
     */
    public function image(mixed $mediaRef): ?ResolvedMedia
    {
        if (!is_array($mediaRef) || !isset($mediaRef['media_id']) || !is_int($mediaRef['media_id']) || $mediaRef['media_id'] <= 0) {
            return null;
        }
        return $this->media->resolveImage($mediaRef['media_id']);
    }

    /** First asking wins: for the one `<link>`/`<script>` a plugin block needs per page, however many blocks use it. */
    public function claimOnce(string $key): bool
    {
        return $this->collector->claimOnce($key);
    }

    public function tokenClass(string $kind, mixed $ref): string
    {
        return $this->collector->tokenClass($kind, $ref, $this->theme);
    }

    public function childrenHtml(): string
    {
        return $this->childrenHtml;
    }

    public function hasBinding(string $slot): bool
    {
        return array_key_exists($slot, $this->bindingRows);
    }

    /**
     * Provider rows for a binding slot: a list (possibly empty) when the
     * provider ran, null when the binding is absent or was denied/failed.
     *
     * @return ?list<array<string, mixed>>
     */
    public function rows(string $slot): ?array
    {
        return $this->bindingRows[$slot] ?? null;
    }
}
