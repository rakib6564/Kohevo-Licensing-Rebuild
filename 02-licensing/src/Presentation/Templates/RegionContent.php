<?php
/**
 * Slate — RegionContent: the named regions a Template assembles (Presentation).
 *
 * A small immutable map of region name => rendered HTML (docs/05-Rendering/
 * theme-and-template-engine.md §1). Sections render into 'content'; chrome presets
 * fill 'header'/'footer'; the head stage fills 'head'. A Template reads regions
 * from here; it never reaches back into how they were produced.
 *
 * @internal — a value container for the Template contract.
 */

declare(strict_types=1);

namespace Slate\Presentation\Templates;

final class RegionContent
{
    /** @param array<string,string> $regions region name => HTML */
    public function __construct(private readonly array $regions = [])
    {
    }

    /** Return a new RegionContent with $name set to $html (immutable). */
    public function with(string $name, string $html): self
    {
        $regions = $this->regions;
        $regions[$name] = $html;
        return new self($regions);
    }

    public function get(string $name): string
    {
        return $this->regions[$name] ?? '';
    }

    public function has(string $name): bool
    {
        return isset($this->regions[$name]);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->regions;
    }
}
