<?php
/**
 * Slate — Page value object (Presentation).
 *
 * A Page binds a Template to an ordered list of Sections for a content type
 * (page, post, product, landing, service) — docs/05-Rendering/
 * blocks-and-sections.md §4, ADR-0007.
 *
 *   Page = Template + [ Section, Section, … ]
 *
 * `type` drives template selection (content-type-aware, rendering-pipeline.md §4);
 * `template` is an explicit override ('' = fall back to the content-type default).
 * `seo` is a plain metadata bag fed to the SEO Manager at head assembly (Phase 3D);
 * it is empty-safe now.
 *
 * This is the in-memory model the Renderer walks. It is produced from the stored
 * document JSON by the document normalizer (Phase 3A B2) — including the BC bridge
 * that turns a legacy flat block array into a single implicit Section. Page itself
 * stays pure and storage-agnostic.
 *
 * Immutable value object (platform-foundation §5). Pure — no DB, no rendering.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class Page implements \JsonSerializable
{
    /**
     * @param string        $type      content type → template selection ('page','post',…)
     * @param string        $template  explicit template override ('' = content-type default)
     * @param list<Section> $sections  ordered Sections rendered into the 'content' region
     * @param array<string,mixed> $seo  metadata bag for the SEO Manager (Phase 3D)
     */
    public function __construct(
        public readonly string $type = 'page',
        public readonly string $template = '',
        public readonly array $sections = [],
        public readonly array $seo = [],
    ) {}

    /** @param list<Section> $sections */
    public static function of(string $type, array $sections, string $template = '', array $seo = []): self
    {
        return new self($type, $template, array_values($sections), $seo);
    }

    /** @return list<Section> */
    public function sections(): array { return $this->sections; }

    public function isEmpty(): bool
    {
        return $this->sections === [];
    }

    /**
     * Serialize to the document shape (without the envelope's `schema` version,
     * which the document normalizer stamps). Kept in sync with Section::toArray.
     *
     * @return array{type:string,template:string,sections:list<array>,seo:array<string,mixed>}
     */
    public function toArray(): array
    {
        return [
            'type'     => $this->type,
            'template' => $this->template,
            'sections' => array_map(static fn (Section $s) => $s->toArray(), $this->sections),
            'seo'      => $this->seo,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
