<?php
/**
 * Kohevo Studio (studio-builder) — how many times each block type is used (the Element Manager's usage scan).
 *
 * Pure: it counts over documents it is handed. The application service decides which documents (the working
 * drafts of the tenant's pages) and checks permission first.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Registry;

final class BlockUsage
{
    /** How many page titles are kept per block type, so the answer stays small. */
    public const SAMPLE_PAGES = 5;

    private const MAX_DEPTH = 64;

    /**
     * @param iterable<array{title: string, document: array<string, mixed>}> $pages
     * @return array<string, array{blocks: int, pages: int, sample: list<string>}>
     */
    public static function count(iterable $pages): array
    {
        $usage = [];
        foreach ($pages as $page) {
            $perPage = [];
            $walk = static function (mixed $list, int $depth) use (&$walk, &$perPage): void {
                if (!is_array($list) || $depth > self::MAX_DEPTH) {
                    return;
                }
                foreach ($list as $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    $type = $block['type'] ?? null;
                    if (is_string($type) && $type !== '') {
                        $perPage[$type] = ($perPage[$type] ?? 0) + 1;
                    }
                    $walk($block['children'] ?? null, $depth + 1);
                }
            };
            foreach ((array) ($page['document']['sections'] ?? []) as $section) {
                if (is_array($section)) {
                    $walk($section['blocks'] ?? null, 0);
                }
            }
            foreach ($perPage as $type => $n) {
                $usage[$type] ??= ['blocks' => 0, 'pages' => 0, 'sample' => []];
                $usage[$type]['blocks'] += $n;
                $usage[$type]['pages']++;
                if (count($usage[$type]['sample']) < self::SAMPLE_PAGES) {
                    $usage[$type]['sample'][] = (string) ($page['title'] ?? '');
                }
            }
        }
        ksort($usage, SORT_STRING);
        return $usage;
    }
}
