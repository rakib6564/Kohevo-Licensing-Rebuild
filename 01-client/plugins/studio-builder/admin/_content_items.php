<?php
/**
 * Kohevo Studio — how the Content screen names one portfolio, service or testimonial item.
 *
 * The items are stored as JSON lists in tenant settings, without ids of their own. A Delete or "Change image" button
 * names its item by a key made from the item's content, not by its position: after another item was added or deleted
 * (in another tab, or by the same form sent twice) a position points at a different item, a key points at none.
 */

declare(strict_types=1);

if (!function_exists('sb_content_item_key')) {
    /** The key of one item: a hash of its stored fields, so it changes when the item does. */
    function sb_content_item_key(array $item): string
    {
        return substr(hash('sha256', (string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 20);
    }
}

if (!function_exists('sb_content_item_index')) {
    /**
     * Where the item with this key is in the list now, or null when no item has it (it was deleted or changed since the
     * screen was shown). Two identical items share a key; either one is the right one to delete.
     *
     * @param list<array<string, mixed>> $items
     */
    function sb_content_item_index(array $items, string $key): ?int
    {
        if ($key === '') {
            return null;
        }
        foreach (array_values($items) as $i => $item) {
            if (is_array($item) && hash_equals(sb_content_item_key($item), $key)) {
                return $i;
            }
        }
        return null;
    }
}
