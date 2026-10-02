<?php
/**
 * Kohevo Studio (studio-builder) — Block Renderer Registry.
 *
 * Maps a registered block type to exactly one server renderer. A block type
 * with a definition but no renderer (or a renderer but no definition) is
 * rendered as the non-executable "unavailable" fallback — it is never guessed
 * at, and nothing is ever dispatched by an arbitrary string.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Render\Block;

use Slate\Module\StudioBuilder\Document\CanonicalDocumentSchema;

final class BlockRendererRegistry
{
    /** @var array<string, BlockRendererInterface> */
    private array $renderers = [];

    public function register(BlockRendererInterface $renderer): void
    {
        $type = $renderer->type();
        if (preg_match(CanonicalDocumentSchema::BLOCK_TYPE_PATTERN, $type) !== 1) {
            throw new \InvalidArgumentException("Invalid renderer block type '{$type}'.");
        }
        if (isset($this->renderers[$type])) {
            throw new \InvalidArgumentException("Duplicate Studio block renderer for type '{$type}'.");
        }
        $this->renderers[$type] = $renderer;
    }

    public function get(string $type): ?BlockRendererInterface
    {
        return $this->renderers[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->renderers[$type]);
    }

    /** Deterministic identity of the renderer set (part of the compilation fingerprint). */
    public function fingerprint(): string
    {
        $keys = [];
        foreach ($this->renderers as $type => $renderer) {
            $keys[] = $type . ($renderer->isDynamic() ? ':dynamic' : ':static');
        }
        sort($keys, SORT_STRING);
        return hash('sha256', implode('|', $keys));
    }

    public static function withCoreRenderers(): self
    {
        $registry = new self();
        $registry->register(new CoreRenderers\HeroRenderer());
        $registry->register(new CoreRenderers\HeadingRenderer());
        $registry->register(new CoreRenderers\RichTextRenderer());
        $registry->register(new CoreRenderers\ImageRenderer());
        $registry->register(new CoreRenderers\ButtonRenderer());
        $registry->register(new CoreRenderers\FeatureListRenderer());
        $registry->register(new CoreRenderers\ContainerRenderer());
        $registry->register(new CoreRenderers\SectionRenderer());
        $registry->register(new CoreRenderers\LayoutContainerRenderer());
        $registry->register(new CoreRenderers\FlexRenderer());
        $registry->register(new CoreRenderers\GridRenderer());
        $registry->register(new CoreRenderers\TextRenderer());
        $registry->register(new CoreRenderers\QueryLoopRenderer());
        return $registry;
    }

    /** Core renderers plus the business-module catalogue block renderers. */
    public static function withStudioRenderers(): self
    {
        $registry = self::withCoreRenderers();
        $registry->register(new ModuleRenderers\BookingServicesRenderer());
        $registry->register(new ModuleRenderers\MembershipPlansRenderer());
        $registry->register(new ModuleRenderers\FormCardRenderer());
        return $registry;
    }
}
