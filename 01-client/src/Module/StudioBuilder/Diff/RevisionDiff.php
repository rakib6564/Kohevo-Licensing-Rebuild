<?php
/**
 * Kohevo Studio (studio-builder) — Structured diff of two canonical documents.
 *
 * Phase 7 (AI + MCP). A human reviewing an AI-proposed draft needs to see
 * WHAT changed in the page's own vocabulary — sections, blocks, properties,
 * styles, settings, SEO, template, global-component references — not a raw
 * JSON text diff. This service compares two canonical Studio documents (the
 * decoded `document_json` of two revisions of the same page) structurally,
 * by node id, and returns bounded, transport-safe DATA:
 *
 *   - counts per change kind (the "at a glance" summary);
 *   - the changed sections and blocks, each with the kind of change
 *     (added / removed / moved / updated) and the changed keys;
 *   - settings / SEO / template changes with bounded before/after values;
 *   - global-component references added or removed;
 *   - short human-readable lines generated from that data.
 *
 * Every string taken from the documents (titles, labels, prop values) is
 * page CONTENT: it is truncated, stripped of control characters and returned
 * as plain data. No renderer, repository or database is involved — the
 * application layer resolves the revisions (tenant-scoped) and hands the two
 * documents in. Pure and dependency-free, so it is unit-tested directly.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Diff;

final class RevisionDiff
{
    public const CHANGE_ADDED   = 'added';
    public const CHANGE_REMOVED = 'removed';
    public const CHANGE_MOVED   = 'moved';
    public const CHANGE_UPDATED = 'updated';

    /** Bounds on the returned structure, so a diff can never become a document dump. */
    public const MAX_LISTED_NODES   = 500;
    public const MAX_LINES          = 200;
    public const MAX_VALUE_CHARS    = 120;
    public const MAX_LABEL_CHARS    = 80;

    private const SECTION_COMPARED_KEYS = ['label', 'global_ref', 'layout', 'visibility'];
    private const BLOCK_COMPARED_KEYS   = ['props', 'style', 'visibility', 'bindings'];

    private function __construct() {}

    /**
     * @param array<string, mixed> $base     the document being changed (parent / published)
     * @param array<string, mixed> $proposed the document that would replace it
     * @return array<string, mixed>
     */
    public static function compare(array $base, array $proposed): array
    {
        $baseSections = self::indexSections($base);
        $propSections = self::indexSections($proposed);
        $baseBlocks   = self::indexBlocks($base);
        $propBlocks   = self::indexBlocks($proposed);

        $sectionOrder = self::relativeOrder(array_keys($baseSections), array_keys($propSections));
        $blockOrder   = self::relativeBlockOrder($baseBlocks, $propBlocks);

        $sections = [];
        $summary  = [
            'sections_added' => 0, 'sections_removed' => 0, 'sections_moved' => 0, 'sections_updated' => 0,
            'blocks_added' => 0, 'blocks_removed' => 0, 'blocks_moved' => 0, 'blocks_updated' => 0,
        ];
        $nodes = ['added' => [], 'removed' => [], 'moved' => [], 'updated' => []];

        foreach ($propSections as $id => $sec) {
            if (!isset($baseSections[$id])) {
                $summary['sections_added']++;
                $nodes['added'][] = $id;
                $sections[] = [
                    'id'         => $id,
                    'label'      => $sec['label'],
                    'change'     => self::CHANGE_ADDED,
                    'index'      => $sec['index'],
                    'global_ref' => $sec['global_ref'],
                    'blocks'     => count($sec['block_ids']),
                ];
                continue;
            }
            $before = $baseSections[$id];
            $changedKeys = [];
            foreach (self::SECTION_COMPARED_KEYS as $key) {
                if (self::canonical($before['raw'][$key] ?? null) !== self::canonical($sec['raw'][$key] ?? null)) {
                    $changedKeys[] = $key;
                }
            }
            $moved = $sectionOrder[$id] ?? false;
            if ($moved || $changedKeys !== []) {
                if ($moved) {
                    $summary['sections_moved']++;
                    $nodes['moved'][] = $id;
                }
                if ($changedKeys !== []) {
                    $summary['sections_updated']++;
                    $nodes['updated'][] = $id;
                }
                $entry = [
                    'id'           => $id,
                    'label'        => $sec['label'],
                    'change'       => $changedKeys !== [] ? self::CHANGE_UPDATED : self::CHANGE_MOVED,
                    'index_before' => $before['index'],
                    'index_after'  => $sec['index'],
                    'changed_keys' => $changedKeys,
                ];
                if (in_array('global_ref', $changedKeys, true)) {
                    $entry['global_ref_before'] = $before['global_ref'];
                    $entry['global_ref_after']  = $sec['global_ref'];
                }
                if (in_array('label', $changedKeys, true)) {
                    $entry['label_before'] = $before['label'];
                }
                if (in_array('layout', $changedKeys, true)) {
                    $entry['layout'] = self::changedMap($before['raw']['layout'] ?? [], $sec['raw']['layout'] ?? []);
                }
                $sections[] = $entry;
            }
        }
        foreach ($baseSections as $id => $sec) {
            if (!isset($propSections[$id])) {
                $summary['sections_removed']++;
                $nodes['removed'][] = $id;
                $sections[] = [
                    'id'         => $id,
                    'label'      => $sec['label'],
                    'change'     => self::CHANGE_REMOVED,
                    'index'      => $sec['index'],
                    'global_ref' => $sec['global_ref'],
                    'blocks'     => count($sec['block_ids']),
                ];
            }
        }

        $blocks = [];
        foreach ($propBlocks as $id => $blk) {
            if (!isset($baseBlocks[$id])) {
                // A block inside an added section is reported once, as part of the section.
                $inAddedSection = !isset($baseSections[$blk['section_id']]);
                $summary['blocks_added']++;
                $nodes['added'][] = $id;
                if (!$inAddedSection) {
                    $blocks[] = [
                        'id'      => $id,
                        'type'    => $blk['type'],
                        'label'   => $blk['label'],
                        'change'  => self::CHANGE_ADDED,
                        'parent'  => $blk['parent_id'],
                        'section' => $blk['section_id'],
                        'index'   => $blk['index'],
                    ];
                }
                continue;
            }
            $before = $baseBlocks[$id];
            $changedKeys = [];
            $detail = [];
            foreach (self::BLOCK_COMPARED_KEYS as $key) {
                $b = $before['raw'][$key] ?? null;
                $a = $blk['raw'][$key] ?? null;
                if (self::canonical($b) !== self::canonical($a)) {
                    $changedKeys[] = $key;
                    if (in_array($key, ['props', 'style'], true)) {
                        $detail[$key] = self::changedMap(is_array($b) ? $b : [], is_array($a) ? $a : []);
                    }
                }
            }
            $typeChanged = $before['type'] !== $blk['type'];
            $moved = $before['parent_id'] !== $blk['parent_id'] || ($blockOrder[$id] ?? false);
            if ($moved || $changedKeys !== [] || $typeChanged) {
                if ($moved) {
                    $summary['blocks_moved']++;
                    $nodes['moved'][] = $id;
                }
                if ($changedKeys !== [] || $typeChanged) {
                    $summary['blocks_updated']++;
                    $nodes['updated'][] = $id;
                }
                $entry = [
                    'id'            => $id,
                    'type'          => $blk['type'],
                    'label'         => $before['label'], // the reviewer knows the block by what it WAS
                    'change'        => ($changedKeys !== [] || $typeChanged) ? self::CHANGE_UPDATED : self::CHANGE_MOVED,
                    'parent_before' => $before['parent_id'],
                    'parent_after'  => $blk['parent_id'],
                    'index_before'  => $before['index'],
                    'index_after'   => $blk['index'],
                    'changed_keys'  => $typeChanged ? array_merge(['type'], $changedKeys) : $changedKeys,
                ];
                if ($typeChanged) {
                    $entry['type_before'] = $before['type'];
                }
                if (isset($detail['props'])) {
                    $entry['props'] = $detail['props'];
                }
                if (isset($detail['style'])) {
                    $entry['style'] = $detail['style'];
                }
                $blocks[] = $entry;
            }
        }
        foreach ($baseBlocks as $id => $blk) {
            if (!isset($propBlocks[$id])) {
                $summary['blocks_removed']++;
                $nodes['removed'][] = $id;
                if (isset($propSections[$blk['section_id']])) {
                    $blocks[] = [
                        'id'      => $id,
                        'type'    => $blk['type'],
                        'label'   => $blk['label'],
                        'change'  => self::CHANGE_REMOVED,
                        'parent'  => $blk['parent_id'],
                        'section' => $blk['section_id'],
                        'index'   => $blk['index'],
                    ];
                }
            }
        }

        $settings = self::changedMap(self::objectOf($base['settings'] ?? null), self::objectOf($proposed['settings'] ?? null));
        $seo      = self::changedMap(self::objectOf($base['seo'] ?? null), self::objectOf($proposed['seo'] ?? null));
        $templateBefore = self::text($base['template_key'] ?? null);
        $templateAfter  = self::text($proposed['template_key'] ?? null);
        $template = $templateBefore !== $templateAfter ? ['before' => $templateBefore, 'after' => $templateAfter] : null;

        $baseRefs = self::refsOf($baseSections);
        $propRefs = self::refsOf($propSections);
        $components = [
            'added'   => array_values(array_diff($propRefs, $baseRefs)),
            'removed' => array_values(array_diff($baseRefs, $propRefs)),
        ];

        $summary['settings_changed'] = $settings !== [];
        $summary['seo_changed']      = $seo !== [];
        $summary['template_changed'] = $template !== null;
        $summary['changed'] = $template !== null || $settings !== [] || $seo !== []
            || array_sum(array_filter($summary, 'is_int')) > 0;

        foreach ($nodes as $k => $list) {
            $nodes[$k] = array_slice(array_values(array_unique($list)), 0, self::MAX_LISTED_NODES);
        }

        $result = [
            'summary'    => $summary,
            'sections'   => array_slice($sections, 0, self::MAX_LISTED_NODES),
            'blocks'     => array_slice($blocks, 0, self::MAX_LISTED_NODES),
            'settings'   => $settings,
            'seo'        => $seo,
            'template'   => $template,
            'components' => $components,
            'nodes'      => $nodes,
        ];
        $result['lines'] = self::lines($result);
        return $result;
    }

    // ── Human-readable lines ───────────────────────────────────────────────

    /**
     * Short, bounded sentences generated from the structured result. Content
     * strings appear only as quoted, truncated plain text.
     *
     * @param array<string, mixed> $diff
     * @return list<string>
     */
    private static function lines(array $diff): array
    {
        $out = [];
        if (!$diff['summary']['changed']) {
            return ['No changes between the two revisions.'];
        }
        if ($diff['template'] !== null) {
            $out[] = 'Template changed from "' . $diff['template']['before'] . '" to "' . $diff['template']['after'] . '".';
        }
        foreach ($diff['settings'] as $key => $chg) {
            $out[] = 'Setting "' . $key . '" changed from ' . self::quote($chg['before']) . ' to ' . self::quote($chg['after']) . '.';
        }
        foreach ($diff['seo'] as $key => $chg) {
            $out[] = 'SEO "' . $key . '" changed from ' . self::quote($chg['before']) . ' to ' . self::quote($chg['after']) . '.';
        }
        foreach ($diff['sections'] as $sec) {
            $name = 'Section "' . $sec['label'] . '"';
            $out[] = match ($sec['change']) {
                self::CHANGE_ADDED   => $name . ' added at position ' . ($sec['index'] + 1) . ($sec['global_ref'] !== null ? ' (global component reference)' : ' with ' . $sec['blocks'] . ' block(s)') . '.',
                self::CHANGE_REMOVED => $name . ' removed (was position ' . ($sec['index'] + 1) . ').',
                self::CHANGE_MOVED   => $name . ' moved from position ' . ($sec['index_before'] + 1) . ' to ' . ($sec['index_after'] + 1) . '.',
                default              => $name . ' updated (' . implode(', ', $sec['changed_keys']) . ')' . (($sec['index_before'] ?? null) !== ($sec['index_after'] ?? null) ? ', moved to position ' . ($sec['index_after'] + 1) : '') . '.',
            };
        }
        foreach ($diff['blocks'] as $blk) {
            $name = $blk['label'] . ' (' . $blk['type'] . ')';
            $out[] = match ($blk['change']) {
                self::CHANGE_ADDED   => $name . ' added.',
                self::CHANGE_REMOVED => $name . ' removed.',
                self::CHANGE_MOVED   => $name . ' moved' . ($blk['parent_before'] !== $blk['parent_after'] ? ' to another container' : '') . '.',
                default              => $name . ' updated: ' . self::describeBlockChange($blk) . '.',
            };
        }
        foreach ($diff['components']['added'] as $ref) {
            $out[] = 'Global component reference added: ' . $ref . '.';
        }
        foreach ($diff['components']['removed'] as $ref) {
            $out[] = 'Global component reference removed: ' . $ref . '.';
        }
        if (count($out) > self::MAX_LINES) {
            $out = array_slice($out, 0, self::MAX_LINES);
            $out[] = '… and more changes not listed.';
        }
        return $out;
    }

    /** @param array<string, mixed> $blk */
    private static function describeBlockChange(array $blk): string
    {
        $parts = [];
        foreach (['props', 'style'] as $group) {
            foreach ($blk[$group] ?? [] as $key => $chg) {
                $parts[] = ($group === 'style' ? 'style ' : '') . $key . ' ' . self::quote($chg['before']) . ' → ' . self::quote($chg['after']);
            }
        }
        foreach ($blk['changed_keys'] as $key) {
            if (!in_array($key, ['props', 'style'], true)) {
                $parts[] = $key . ' changed';
            }
        }
        return $parts === [] ? implode(', ', $blk['changed_keys']) : implode('; ', array_slice($parts, 0, 8));
    }

    // ── Order ──────────────────────────────────────────────────────────────

    /**
     * Which ids changed their RELATIVE order among the ids present in both
     * lists. A node that merely shifts because a sibling was inserted or
     * removed next to it did not move — only reordering is reported.
     *
     * @param list<string> $baseIds
     * @param list<string> $proposedIds
     * @return array<string, bool>
     */
    private static function relativeOrder(array $baseIds, array $proposedIds): array
    {
        $common = array_flip(array_intersect($baseIds, $proposedIds));
        $before = array_values(array_filter($baseIds, static fn(string $id): bool => isset($common[$id])));
        $after  = array_values(array_filter($proposedIds, static fn(string $id): bool => isset($common[$id])));
        $afterPos = array_flip($after);
        $out = [];
        foreach ($before as $pos => $id) {
            $out[$id] = ($afterPos[$id] ?? $pos) !== $pos;
        }
        return $out;
    }

    /**
     * Relative order per parent for blocks that stayed under the same parent.
     *
     * @param array<string, array<string, mixed>> $baseBlocks
     * @param array<string, array<string, mixed>> $propBlocks
     * @return array<string, bool>
     */
    private static function relativeBlockOrder(array $baseBlocks, array $propBlocks): array
    {
        $byParentBefore = [];
        $byParentAfter  = [];
        foreach ($baseBlocks as $id => $b) {
            $byParentBefore[$b['parent_id']][] = $id;
        }
        foreach ($propBlocks as $id => $b) {
            $byParentAfter[$b['parent_id']][] = $id;
        }
        $out = [];
        foreach ($byParentBefore as $parent => $ids) {
            $samePerParent = array_values(array_filter($ids, static fn(string $id): bool => isset($propBlocks[$id]) && $propBlocks[$id]['parent_id'] === $parent));
            $afterIds = array_values(array_filter($byParentAfter[$parent] ?? [], static fn(string $id): bool => isset($baseBlocks[$id]) && $baseBlocks[$id]['parent_id'] === $parent));
            foreach (self::relativeOrder($samePerParent, $afterIds) as $id => $moved) {
                $out[$id] = $moved;
            }
        }
        return $out;
    }

    // ── Indexing ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $document
     * @return array<string, array{index: int, label: string, global_ref: ?string, block_ids: list<string>, raw: array<string, mixed>}>
     */
    private static function indexSections(array $document): array
    {
        $out = [];
        $sections = is_array($document['sections'] ?? null) ? array_values($document['sections']) : [];
        foreach ($sections as $i => $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null)) {
                continue;
            }
            $ids = [];
            self::collectBlockIds(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $ids);
            $out[$section['id']] = [
                'index'      => $i,
                'label'      => self::label($section['label'] ?? null, 'Section'),
                'global_ref' => is_string($section['global_ref'] ?? null) ? self::text($section['global_ref']) : null,
                'block_ids'  => $ids,
                'raw'        => $section,
            ];
        }
        return $out;
    }

    /** @param list<mixed> $blocks @param list<string> $ids */
    private static function collectBlockIds(array $blocks, array &$ids): void
    {
        foreach ($blocks as $b) {
            if (!is_array($b) || !is_string($b['id'] ?? null)) {
                continue;
            }
            $ids[] = $b['id'];
            self::collectBlockIds(is_array($b['children'] ?? null) ? $b['children'] : [], $ids);
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, array{type: string, label: string, parent_id: string, section_id: string, index: int, raw: array<string, mixed>}>
     */
    private static function indexBlocks(array $document): array
    {
        $out = [];
        $sections = is_array($document['sections'] ?? null) ? array_values($document['sections']) : [];
        foreach ($sections as $section) {
            if (!is_array($section) || !is_string($section['id'] ?? null)) {
                continue;
            }
            self::walkBlocks(is_array($section['blocks'] ?? null) ? $section['blocks'] : [], $section['id'], $section['id'], $out);
        }
        return $out;
    }

    /** @param list<mixed> $blocks @param array<string, array<string, mixed>> $out */
    private static function walkBlocks(array $blocks, string $parentId, string $sectionId, array &$out): void
    {
        foreach (array_values($blocks) as $i => $b) {
            if (!is_array($b) || !is_string($b['id'] ?? null)) {
                continue;
            }
            $type = is_string($b['type'] ?? null) ? self::text($b['type']) : 'unknown';
            $out[$b['id']] = [
                'type'       => $type,
                'label'      => self::blockLabel($b, $type),
                'parent_id'  => $parentId,
                'section_id' => $sectionId,
                'index'      => $i,
                'raw'        => $b,
            ];
            self::walkBlocks(is_array($b['children'] ?? null) ? $b['children'] : [], $b['id'], $sectionId, $out);
        }
    }

    /** A short label for a block: its type, plus the first short text prop as content. @param array<string, mixed> $block */
    private static function blockLabel(array $block, string $type): string
    {
        $props = is_array($block['props'] ?? null) ? $block['props'] : [];
        foreach (['text', 'heading', 'title', 'label'] as $key) {
            if (is_string($props[$key] ?? null) && trim($props[$key]) !== '') {
                return self::label($props[$key], $type) . '';
            }
        }
        $short = substr($type, (int) strrpos($type, '.') + 1);
        return ucfirst(str_replace('_', ' ', $short));
    }

    /** @param array<string, array<string, mixed>> $sections @return list<string> */
    private static function refsOf(array $sections): array
    {
        $refs = [];
        foreach ($sections as $sec) {
            if ($sec['global_ref'] !== null && $sec['global_ref'] !== '' && !in_array($sec['global_ref'], $refs, true)) {
                $refs[] = $sec['global_ref'];
            }
        }
        return $refs;
    }

    // ── Values ─────────────────────────────────────────────────────────────

    /**
     * The keys whose value differs, with bounded before/after summaries.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{before: ?string, after: ?string}>
     */
    private static function changedMap(array $before, array $after): array
    {
        $out = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if (self::canonical($b) !== self::canonical($a)) {
                $out[(string) $key] = ['before' => self::summarize($b), 'after' => self::summarize($a)];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function objectOf(mixed $value): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [];
    }

    private static function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $k => $v) {
                $value[$k] = is_array($v) ? json_decode(self::canonical($v), true) : $v;
            }
        }
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** A bounded, plain-text summary of any value (content is data, never markup). */
    private static function summarize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return self::text($value, self::MAX_VALUE_CHARS);
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[list of ' . count($value) . ']';
            }
            $short = [];
            foreach (array_slice($value, 0, 6, true) as $k => $v) {
                $short[] = (string) $k . ': ' . (is_array($v) ? (array_is_list($v) ? '[list of ' . count($v) . ']' : '{…}') : (string) self::summarize($v));
            }
            return '{' . implode(', ', $short) . (count($value) > 6 ? ', …' : '') . '}';
        }
        return '[unsupported]';
    }

    private static function label(mixed $value, string $fallback): string
    {
        $text = is_string($value) ? self::text($value, self::MAX_LABEL_CHARS) : '';
        return trim($text) === '' ? $fallback : $text;
    }

    /** Plain text: control characters removed, length bounded, always valid UTF-8. */
    private static function text(mixed $value, int $max = self::MAX_VALUE_CHARS): string
    {
        $s = is_scalar($value) ? (string) $value : '';
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        if ($s === '' || preg_match('//u', $s) !== 1) {
            $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
        }
        $s = trim((string) preg_replace('/\s+/u', ' ', $s));
        return mb_strlen($s, 'UTF-8') > $max ? mb_substr($s, 0, $max - 1, 'UTF-8') . '…' : $s;
    }

    private static function quote(?string $value): string
    {
        return $value === null ? '(none)' : '"' . $value . '"';
    }
}
