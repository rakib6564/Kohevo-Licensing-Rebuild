<?php
/**
 * Kohevo Studio — the testimonials of a content type, for the Testimonial grid.
 *
 * One published item of the type is one testimonial: its title is the reviewer's name and its custom fields hold the
 * text, the rating, the details shown under the name, the date and a photo, under the keys the block maps. A
 * testimonial type is usually not public (its items have no page of their own), so this reads the items directly
 * rather than through PostsProvider, which lists only what visitors may open. Drafts and archived items never show.
 */

declare(strict_types=1);

namespace Slate\Module\StudioBuilder\Provider;

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Meta\PageMeta;

final class TestimonialItems
{
    /**
     * @param array{text: string, rating: string, meta: list<string>, date: string, photo: string} $map field keys
     * @return list<array{name: string, text: string, rating: ?float, meta: string, date: string, photo: string}>
     */
    public static function ofType(int $tenantId, string $type, array $map, string $order, int $minLength, int $limit): array
    {
        if ($tenantId <= 0 || $type === '') {
            return [];
        }
        $ids = PageMeta::pageIdsOfType($tenantId, $type);
        if ($ids === []) {
            return [];
        }
        $sort = match ($order) {
            'oldest' => 'p.published_at ASC, p.id ASC',
            'title'  => 'p.title ASC, p.id ASC',
            default  => 'p.published_at DESC, p.id DESC',
        };
        $rows = Database::rows(
            "SELECT p.id, p.title FROM studiobuilder_pages p
             WHERE p.tenant_id = ? AND p.status = 'published' AND p.published_revision_id IS NOT NULL
               AND p.id IN (" . implode(',', array_map('intval', $ids)) . ") ORDER BY {$sort}",
            [$tenantId]
        );
        $out = [];
        foreach ($rows as $row) {
            $values = PageMeta::read($tenantId, (int) $row['id'])['values'];
            $item = self::item((string) $row['title'], $values, $map);
            if ($item !== null && mb_strlen($item['text']) >= $minLength) {
                $out[] = $item;
            }
        }
        if ($order === 'longest') {
            // A stable sort: reviews of the same length keep their newest-first order.
            $keyed = array_map(null, $out, array_keys($out));
            usort($keyed, static fn(array $a, array $b): int => [mb_strlen($b[0]['text']), $a[1]] <=> [mb_strlen($a[0]['text']), $b[1]]);
            $out = array_column($keyed, 0);
        }
        return array_slice($out, 0, max(1, $limit));
    }

    /**
     * One testimonial from an item's title and field values; null without text.
     *
     * @param array<string, mixed> $values
     * @param array{text: string, rating: string, meta: list<string>, date: string, photo: string} $map
     * @return array{name: string, text: string, rating: ?float, meta: string, date: string, photo: string}|null
     */
    public static function item(string $name, array $values, array $map): ?array
    {
        $plain = static fn(mixed $v): string => is_scalar($v)
            ? trim((string) preg_replace('/[ \t]+/u', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", (string) $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8')))
            : '';
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $plain($values[$map['text']] ?? '')));
        if ($text === '') {
            return null;
        }
        $rating = $map['rating'] !== '' && is_numeric($values[$map['rating']] ?? null) ? max(0.0, min(5.0, (float) $values[$map['rating']])) : null;
        $meta = [];
        foreach ($map['meta'] as $key) {
            $v = $plain($values[$key] ?? '');
            if ($v !== '') {
                $meta[] = $v;
            }
        }
        $photo = $map['photo'] !== '' && is_string($values[$map['photo']] ?? null) ? trim($values[$map['photo']]) : '';
        return [
            'name'   => trim($name),
            'text'   => $text,
            'rating' => $rating,
            'meta'   => implode(' · ', $meta),
            'date'   => $map['date'] !== '' ? $plain($values[$map['date']] ?? '') : '',
            'photo'  => $photo,
        ];
    }
}
