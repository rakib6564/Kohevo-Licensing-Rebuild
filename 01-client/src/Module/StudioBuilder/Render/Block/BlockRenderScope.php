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

    /** @return array<string, mixed> */
    public function props(): array
    {
        return is_array($this->block['props'] ?? null) ? $this->block['props'] : [];
    }

    public function prop(string $key, mixed $default = null): mixed
    {
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
