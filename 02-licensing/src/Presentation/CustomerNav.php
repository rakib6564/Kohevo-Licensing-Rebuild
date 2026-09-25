<?php
/**
 * Slate — customer portal navigation resolver.
 *
 * The customer-side counterpart to the admin sidebar's item pipeline in
 * admin/partials/header.php: collect contributions, drop the invalid ones,
 * sort by `order`, group, and split off a short list for the mobile tab bar.
 *
 * The one deliberate difference from admin is gating. Admin items carry a
 * `perm` key and the renderer drops anything the signed-in user can't reach
 * (`Auth::can($item['perm'])`). Customers have no permission system, so there
 * is nothing here to check against — instead each plugin decides in its own
 * `customer_nav_items` callback whether the item applies to *this* customer:
 *
 *     if (!CoachingAPI::isEnrolled($customerId)) return $items;   // Coaching.php
 *
 * That keeps the rule next to the data that decides it (a parent with no
 * enrollments never sees "My Studio") at the cost of core being unable to
 * enforce it centrally. A `perm` key is still honoured if a caller sets one,
 * so the shape stays compatible with the admin item vocabulary.
 *
 * Pure: no Hook, no Auth, no Database — the renderer in includes/portal_shell.php
 * supplies the raw array, which is what makes this unit-testable with no DB.
 */

declare(strict_types=1);

namespace Slate\Presentation;

final class CustomerNav
{
    /** Items without an explicit order sort here — same default as admin nav. */
    public const DEFAULT_ORDER = 500;

    /** Ungrouped items collect under this key, mirroring the admin default. */
    public const DEFAULT_GROUP = 'plugins';

    /**
     * Mobile tab-bar capacity. Five matches the member app's existing bar
     * (Home / Plans / Card / Schedule / Profile), which is the widest that
     * stays tappable at 320px.
     */
    public const MAX_TABS = 5;

    /**
     * Normalise contributions into renderable items.
     *
     * Drops entries that aren't arrays or lack a slug/href — a plugin
     * returning a malformed item should lose that item, not fatal the whole
     * portal. Duplicate slugs collapse to the first occurrence so a plugin
     * registered twice can't double up the bar.
     *
     * @param  array<mixed>            $items    Raw `customer_nav_items` payload.
     * @param  string|null             $active   Slug to mark `is_active`.
     * @return array<int,array<string,mixed>>    Sorted, normalised items.
     */
    public static function resolve(array $items, ?string $active = null): array
    {
        $out  = [];
        $seen = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = isset($item['slug']) ? trim((string) $item['slug']) : '';
            $href = isset($item['href']) ? trim((string) $item['href']) : '';
            if ($slug === '' || $href === '') {
                continue;
            }
            if (isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;

            $label = isset($item['label']) ? trim((string) $item['label']) : '';

            $out[] = [
                'slug'       => $slug,
                'label'      => $label !== '' ? $label : $slug,
                'href'       => $href,
                'icon'       => isset($item['icon']) ? (string) $item['icon'] : 'file',
                'group'      => self::normaliseGroup($item['group'] ?? ''),
                'order'      => isset($item['order']) ? (int) $item['order'] : self::DEFAULT_ORDER,
                // null = always on the bar if there's room; true = force on;
                // false = never (a rarely-used destination like "Billing history").
                'mobile_tab' => array_key_exists('mobile_tab', $item) ? (bool) $item['mobile_tab'] : null,
                'badge'      => isset($item['badge']) && $item['badge'] !== '' ? (string) $item['badge'] : '',
                'is_active'  => $active !== null && $active !== '' && $slug === $active,
            ];
        }

        // Stable: PHP 8's sort is stable, so equal orders keep registration order
        // and a plugin's own items stay in the sequence it declared them.
        usort($out, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $out;
    }

    /**
     * Bucket resolved items by group, preserving first-seen group order.
     *
     * @param  array<int,array<string,mixed>> $items Output of resolve().
     * @return array<string,array<int,array<string,mixed>>>
     */
    public static function group(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = (string) ($item['group'] ?? self::DEFAULT_GROUP);
            $groups[$key][] = $item;
        }
        return $groups;
    }

    /**
     * Split resolved items into the mobile tab bar and the overflow menu.
     *
     * Order of preference: items forcing themselves on (`mobile_tab => true`),
     * then everything still eligible, both already in `order` sequence. Items
     * opting out (`mobile_tab => false`) never reach the bar but always appear
     * in `more`, so no destination becomes unreachable on a phone.
     *
     * @param  array<int,array<string,mixed>> $items Output of resolve().
     * @return array{tabs: array<int,array<string,mixed>>, more: array<int,array<string,mixed>>}
     */
    public static function split(array $items, int $max = self::MAX_TABS): array
    {
        if ($max < 0) {
            $max = 0;
        }

        $forced   = [];
        $eligible = [];
        foreach ($items as $item) {
            if (($item['mobile_tab'] ?? null) === false) {
                continue;
            }
            if (($item['mobile_tab'] ?? null) === true) {
                $forced[] = $item;
            } else {
                $eligible[] = $item;
            }
        }

        $tabs   = array_slice(array_merge($forced, $eligible), 0, $max);
        $onBar  = array_fill_keys(array_column($tabs, 'slug'), true);
        $more   = array_values(array_filter(
            $items,
            static fn (array $i): bool => !isset($onBar[$i['slug']])
        ));

        return ['tabs' => $tabs, 'more' => $more];
    }

    /** Lowercase, slug-safe group key; empty or non-string falls back. */
    private static function normaliseGroup(mixed $group): string
    {
        if (!is_string($group) || trim($group) === '') {
            return self::DEFAULT_GROUP;
        }
        // Trim the separator off both ends of the RESULT, not just for the
        // emptiness check: "My Studio!" would otherwise yield "my-studio-",
        // which buckets separately from a plain "studio".
        $key = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', trim($group)));
        $key = trim($key, '-');
        return $key !== '' ? $key : self::DEFAULT_GROUP;
    }
}
