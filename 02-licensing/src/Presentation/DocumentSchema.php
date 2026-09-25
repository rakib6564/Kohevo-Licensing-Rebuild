<?php
/**
 * Slate — DocumentSchema: the versioned page-document contract + normalizer.
 *
 * Turns what is STORED into the in-memory Page model the Renderer walks, and
 * back. It is the single place that knows the persisted JSON shape, so the
 * Renderer, the revision store, and the content-builder bridge never each
 * re-implement parsing.
 *
 * Two stored shapes are accepted (docs/05-Rendering/blocks-and-sections.md §4,
 * rendering-pipeline.md §6 "Backward-compatible"):
 *
 *   1. LEGACY FLAT ARRAY  — a JSON list of blocks: [{type,props}, …]. Every
 *      existing contentbuilder_posts.layout is this. It upconverts to ONE
 *      implicit Section with the default LayoutSpec. This is a pure READ-TIME
 *      transform — no stored row is rewritten in Phase 3A.
 *
 *   2. DOCUMENT ENVELOPE  — the first-class shape:
 *        { "schema": 1, "type": "page", "template": "",
 *          "sections": [ { "id","layout","blocks":[…] } ], "seo": {} }
 *
 * Normalization is IDEMPOTENT and DETERMINISTIC: missing section ids are filled
 * positionally ("s1","s2",…), never randomly, so re-normalizing an already-
 * normalized document yields byte-identical output (required for revision diffing
 * and render caching).
 *
 * Note: the content-builder "render mode" (builder vs full_html) is NOT part of
 * the document — it lives in post meta (`cb_render_mode`) and is a chrome/template
 * concern resolved by the bridge/Template engine, not the content tree. A
 * full_html page is simply a one-section document holding a single `html` block.
 *
 * Pure — no DB, no rendering, no side effects.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class DocumentSchema
{
    /** Current document schema version. Bump when the envelope shape changes. */
    public const VERSION = 1;

    /**
     * Parse a stored layout value into a Page.
     *
     * @param string|array|null $stored  JSON string, decoded array, or null/empty
     * @param string            $type    content type from the owning row (e.g. 'page')
     */
    public static function toPage(string|array|null $stored, string $type = 'page'): Page
    {
        $env = self::normalize($stored, $type);
        $sections = array_map(
            static fn (array $s) => Section::fromArray($s),
            $env['sections']
        );
        return new Page($env['type'], $env['template'], $sections, $env['seo']);
    }

    /**
     * Normalize any accepted stored shape to the canonical document envelope
     * (arrays only — no value objects), with a stamped schema version and stable
     * positional section ids.
     *
     * @param string|array|null $stored
     * @return array{schema:int,type:string,template:string,sections:list<array>,seo:array<string,mixed>}
     */
    public static function normalize(string|array|null $stored, string $type = 'page'): array
    {
        $decoded = self::decode($stored);

        // Envelope shape: an associative array carrying 'sections' or 'schema'.
        $isEnvelope = is_array($decoded)
            && !array_is_list($decoded)
            && (array_key_exists('sections', $decoded) || array_key_exists('schema', $decoded));

        if ($isEnvelope) {
            $sections = self::normalizeSections((array) ($decoded['sections'] ?? []));
            return [
                'schema'   => self::VERSION,
                'type'     => (string) ($decoded['type'] ?? $type),
                'template' => (string) ($decoded['template'] ?? ''),
                'sections' => $sections,
                'seo'      => is_array($decoded['seo'] ?? null) ? $decoded['seo'] : [],
            ];
        }

        // Legacy flat block array → one implicit Section (or none if empty).
        $blocks = self::normalizeBlocks(is_array($decoded) ? $decoded : []);
        $sections = $blocks === []
            ? []
            : [[
                'id'     => 's1',
                'layout' => LayoutSpec::default()->toArray(),
                'blocks' => $blocks,
            ]];

        return [
            'schema'   => self::VERSION,
            'type'     => $type,
            'template' => '',
            'sections' => $sections,
            'seo'      => [],
        ];
    }

    /** Serialize a Page back to the canonical envelope (with schema stamp). */
    public static function fromPage(Page $page): array
    {
        return [
            'schema'   => self::VERSION,
            'type'     => $page->type,
            'template' => $page->template,
            'sections' => array_map(static fn (Section $s) => $s->toArray(), $page->sections),
            'seo'      => $page->seo,
        ];
    }

    /** True when a decoded layout is the legacy flat block-array shape. */
    public static function isLegacyFlat(string|array|null $stored): bool
    {
        $decoded = self::decode($stored);
        return is_array($decoded) && array_is_list($decoded);
    }

    // ── internals ─────────────────────────────────────────────

    /** Decode a stored value to an array; malformed/absent → []. */
    private static function decode(string|array|null $stored): array
    {
        if (is_array($stored)) {
            return $stored;
        }
        if ($stored === null || $stored === '') {
            return [];
        }
        $d = json_decode($stored, true);
        return is_array($d) ? $d : [];
    }

    /**
     * Normalize a sections list: keep well-formed section objects, fill missing
     * ids positionally, normalize each section's layout + blocks.
     *
     * @return list<array>
     */
    private static function normalizeSections(array $sections): array
    {
        $out = [];
        $i = 0;
        foreach ($sections as $s) {
            if (!is_array($s)) {
                continue;
            }
            $i++;
            $id = isset($s['id']) && $s['id'] !== '' ? (string) $s['id'] : 's' . $i;
            $entry = [
                'id'     => $id,
                'layout' => LayoutSpec::fromArray((array) ($s['layout'] ?? []))->toArray(),
                'blocks' => self::normalizeBlocks((array) ($s['blocks'] ?? [])),
            ];
            if (isset($s['savedAs']) && $s['savedAs'] !== '') {
                $entry['savedAs'] = (string) $s['savedAs'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Normalize a block list to canonical {type, props} entries, dropping any
     * malformed entry (matches Section::fromArray + the fail-soft renderer).
     *
     * @return list<array{type:string,props:array<string,mixed>}>
     */
    private static function normalizeBlocks(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (is_array($b) && isset($b['type']) && $b['type'] !== '') {
                $entry = ['type' => (string) $b['type'], 'props' => (array) ($b['props'] ?? [])];
                if (!empty($b['style']) && is_array($b['style'])) {
                    $entry['style'] = $b['style'];
                }
                $out[] = $entry;
            }
        }
        return $out;
    }
}
