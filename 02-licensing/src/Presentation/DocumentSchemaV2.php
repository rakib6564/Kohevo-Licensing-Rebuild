<?php
/**
 * Slate — DocumentSchemaV2: Version 2 hierarchical Page Document Model.
 *
 * Provides a structured, nested document tree for the Visual Page Editor:
 * - Root document metadata (header, template, seo, global settings)
 * - Section containers (id, name, layout, props, blocks)
 * - Blocks with UUIDs, props, dynamic bindings, visibility rules, and nested children
 * - Deterministic two-way normalization between Legacy Flat Arrays, Envelope v1, and Document v2.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class DocumentSchemaV2
{
    public const VERSION = 2;

    /**
     * Normalize any stored representation (legacy flat array, v1 envelope, or v2 document)
     * into a canonical Document Schema v2 tree.
     */
    public static function normalize(mixed $stored, array $defaults = []): array
    {
        $decoded = self::decode($stored);

        // If it is already a v2 document
        if (is_array($decoded) && !array_is_list($decoded) && (isset($decoded['version']) && (int) $decoded['version'] === 2)) {
            return self::normalizeV2Tree($decoded, $defaults);
        }

        // If it is a v1 envelope { "schema": 1, "type": ..., "sections": [...], "seo": {...} }
        if (is_array($decoded) && !array_is_list($decoded) && (isset($decoded['sections']) || isset($decoded['schema']))) {
            return self::fromV1Envelope($decoded, $defaults);
        }

        // Otherwise treat as legacy flat block array [ {type, props}, ... ]
        $flatBlocks = is_array($decoded) ? $decoded : [];
        return self::fromFlatBlocks($flatBlocks, $defaults);
    }

    /**
     * Convert canonical v2 document into ADR-0013 v1 envelope for backwards-compatible
     * PageAssembler and core rendering engines.
     */
    public static function toV1Envelope(array $v2): array
    {
        $sections = [];
        $secIndex = 0;
        foreach (($v2['sections'] ?? []) as $s) {
            $secIndex++;
            $secId = !empty($s['id']) ? (string) $s['id'] : 's' . $secIndex;
            $blocks = [];
            foreach (($s['blocks'] ?? []) as $b) {
                if (isset($b['type']) && $b['type'] !== '') {
                    $blocks[] = [
                        'type'  => (string) $b['type'],
                        'props' => (array) ($b['props'] ?? []),
                    ];
                }
            }
            $sections[] = [
                'id'     => $secId,
                'layout' => (array) ($s['layout'] ?? ['cols' => 1]),
                'blocks' => $blocks,
            ];
        }

        return [
            'schema'   => 1,
            'type'     => (string) ($v2['header']['type'] ?? 'page'),
            'template' => (string) ($v2['header']['template'] ?? ''),
            'sections' => $sections,
            'seo'      => (array) ($v2['header']['seo'] ?? []),
        ];
    }

    /**
     * Flatten all blocks across sections into a flat list of ['type' => ..., 'props' => ...]
     * for legacy contentbuilder_posts.layout json column.
     */
    public static function toFlatBlocks(array $v2): array
    {
        $out = [];
        foreach (($v2['sections'] ?? []) as $s) {
            foreach (($s['blocks'] ?? []) as $b) {
                if (isset($b['type']) && $b['type'] !== '') {
                    $out[] = [
                        'type'  => (string) $b['type'],
                        'props' => (array) ($b['props'] ?? []),
                    ];
                    // Also flatten 1-level children if present
                    if (!empty($b['children']) && is_array($b['children'])) {
                        foreach ($b['children'] as $child) {
                            if (isset($child['type']) && $child['type'] !== '') {
                                $out[] = [
                                    'type'  => (string) $child['type'],
                                    'props' => (array) ($child['props'] ?? []),
                                ];
                            }
                        }
                    }
                }
            }
        }
        return $out;
    }

    /**
     * Generate a stable, cryptographically sound UUIDv4.
     */
    public static function uuid(string $prefix = ''): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant RFC 4122
        $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        return $prefix !== '' ? $prefix . '-' . $uuid : $uuid;
    }

    // ── Internal normalizers ───────────────────────────────────

    private static function normalizeV2Tree(array $tree, array $defaults): array
    {
        $header = (array) ($tree['header'] ?? []);
        $settings = (array) ($tree['settings'] ?? []);
        $rawSections = (array) ($tree['sections'] ?? []);

        $normHeader = [
            'title'    => (string) ($header['title'] ?? ($defaults['title'] ?? 'Untitled')),
            'slug'     => (string) ($header['slug'] ?? ($defaults['slug'] ?? '')),
            'status'   => (string) ($header['status'] ?? ($defaults['status'] ?? 'draft')),
            'type'     => (string) ($header['type'] ?? ($defaults['type'] ?? 'page')),
            'template' => (string) ($header['template'] ?? ($defaults['template'] ?? 'default')),
            'seo'      => is_array($header['seo'] ?? null) ? $header['seo'] : [],
            'meta'     => is_array($header['meta'] ?? null) ? $header['meta'] : [],
        ];

        $normSettings = [
            'maxWidth'  => (string) ($settings['maxWidth'] ?? '1200px'),
            'padding'   => (string) ($settings['padding'] ?? '0'),
            'customCss' => (string) ($settings['customCss'] ?? ''),
        ];

        $normSections = [];
        $secIdx = 0;
        foreach ($rawSections as $s) {
            if (!is_array($s)) continue;
            $secIdx++;
            $secId = !empty($s['id']) ? (string) $s['id'] : 'sec-' . $secIdx;
            $secName = !empty($s['name']) ? (string) $s['name'] : 'Section ' . $secIdx;

            $normBlocks = [];
            $blkIdx = 0;
            foreach ((array) ($s['blocks'] ?? []) as $b) {
                if (!is_array($b) || empty($b['type'])) continue;
                $blkIdx++;
                $normBlocks[] = self::normalizeBlock($b, $blkIdx);
            }

            $normSections[] = [
                'id'     => $secId,
                'name'   => $secName,
                'type'   => (string) ($s['type'] ?? 'section'),
                'layout' => (array) ($s['layout'] ?? ['cols' => 1]),
                'props'  => [
                    'background' => (string) ($s['props']['background'] ?? 'transparent'),
                    'padding'    => (string) ($s['props']['padding'] ?? 'medium'),
                    'maxWidth'   => (string) ($s['props']['maxWidth'] ?? 'container'),
                ],
                'blocks' => $normBlocks,
            ];
        }

        return [
            'version'  => self::VERSION,
            'header'   => $normHeader,
            'settings' => $normSettings,
            'sections' => $normSections,
        ];
    }

    private static function fromV1Envelope(array $env, array $defaults): array
    {
        $rawSections = (array) ($env['sections'] ?? []);
        $normSections = [];
        $secIdx = 0;

        foreach ($rawSections as $s) {
            if (!is_array($s)) continue;
            $secIdx++;
            $secId = !empty($s['id']) ? (string) $s['id'] : 'sec-' . $secIdx;
            $normBlocks = [];
            $blkIdx = 0;

            foreach ((array) ($s['blocks'] ?? []) as $b) {
                if (!is_array($b) || empty($b['type'])) continue;
                $blkIdx++;
                $normBlocks[] = self::normalizeBlock($b, $blkIdx);
            }

            $normSections[] = [
                'id'     => $secId,
                'name'   => 'Section ' . $secIdx,
                'type'   => 'section',
                'layout' => (array) ($s['layout'] ?? ['cols' => 1]),
                'props'  => [
                    'background' => 'transparent',
                    'padding'    => 'medium',
                    'maxWidth'   => 'container',
                ],
                'blocks' => $normBlocks,
            ];
        }

        return [
            'version' => self::VERSION,
            'header'  => [
                'title'    => (string) ($defaults['title'] ?? 'Untitled'),
                'slug'     => (string) ($defaults['slug'] ?? ''),
                'status'   => (string) ($defaults['status'] ?? 'draft'),
                'type'     => (string) ($env['type'] ?? ($defaults['type'] ?? 'page')),
                'template' => (string) ($env['template'] ?? ($defaults['template'] ?? 'default')),
                'seo'      => is_array($env['seo'] ?? null) ? $env['seo'] : [],
                'meta'     => [],
            ],
            'settings' => [
                'maxWidth'  => '1200px',
                'padding'   => '0',
                'customCss' => '',
            ],
            'sections' => $normSections,
        ];
    }

    private static function fromFlatBlocks(array $flatBlocks, array $defaults): array
    {
        $normBlocks = [];
        $blkIdx = 0;
        foreach ($flatBlocks as $b) {
            if (!is_array($b) || empty($b['type'])) continue;
            $blkIdx++;
            $normBlocks[] = self::normalizeBlock($b, $blkIdx);
        }

        $sections = [];
        if (!empty($normBlocks)) {
            $sections[] = [
                'id'     => 'sec-1',
                'name'   => 'Main Section',
                'type'   => 'section',
                'layout' => ['cols' => 1],
                'props'  => [
                    'background' => 'transparent',
                    'padding'    => 'medium',
                    'maxWidth'   => 'container',
                ],
                'blocks' => $normBlocks,
            ];
        }

        return [
            'version' => self::VERSION,
            'header'  => [
                'title'    => (string) ($defaults['title'] ?? 'Untitled'),
                'slug'     => (string) ($defaults['slug'] ?? ''),
                'status'   => (string) ($defaults['status'] ?? 'draft'),
                'type'     => (string) ($defaults['type'] ?? 'page'),
                'template' => (string) ($defaults['template'] ?? 'default'),
                'seo'      => [],
                'meta'     => [],
            ],
            'settings' => [
                'maxWidth'  => '1200px',
                'padding'   => '0',
                'customCss' => '',
            ],
            'sections' => $sections,
        ];
    }

    private static function normalizeBlock(array $b, int $index): array
    {
        $id = !empty($b['id']) ? (string) $b['id'] : 'blk-' . $index;
        $children = [];
        if (!empty($b['children']) && is_array($b['children'])) {
            $cIdx = 0;
            foreach ($b['children'] as $child) {
                if (is_array($child) && !empty($child['type'])) {
                    $cIdx++;
                    $children[] = self::normalizeBlock($child, $cIdx);
                }
            }
        }

        return [
            'id'         => $id,
            'type'       => (string) $b['type'],
            'version'    => (int) ($b['version'] ?? 1),
            'props'      => (array) ($b['props'] ?? []),
            'bindings'   => (array) ($b['bindings'] ?? []),
            'visibility' => [
                'device'   => (string) ($b['visibility']['device'] ?? 'all'),
                'roles'    => (array) ($b['visibility']['roles'] ?? []),
                'schedule' => (array) ($b['visibility']['schedule'] ?? []),
            ],
            'children'   => $children,
        ];
    }

    private static function decode(mixed $stored): mixed
    {
        if (is_array($stored)) return $stored;
        if ($stored === null || $stored === '') return [];
        $d = json_decode((string) $stored, true);
        return is_array($d) ? $d : [];
    }
}
