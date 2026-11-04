<?php
/**
 * Restaurant — pre-order menus (a holiday menu, a weekend special) for online ordering.
 *
 * A menu category can be marked pre-order (columns on restaurant_menu_categories):
 *   preorder        1 = this category is a pre-order menu
 *   preorder_from   ordering opens (site time, NULL = already open)
 *   preorder_until  ordering closes (site time, NULL = open until the last pickup date has passed)
 *   preorder_dates  the pickup dates, one per line: "2026-11-25" or "2026-11-25 10:00-14:00, 16:00-18:00"
 *   preorder_note   the line shown over its items on the storefront (empty = a note built from the dates)
 *
 * Rules:
 *  - While the window is open (and a pickup time is still ahead) its items show first on the storefront under the
 *    note; outside it they are hidden and cannot be added or checked out.
 *  - A pickup date with times offers those times, every `online_slot_min` minutes, after the kitchen's lead time.
 *    A date on its own uses that weekday's opening hours; with no opening hours that day it is one "all day" choice.
 *  - Pickup dates are explicit, so closed dates (RestaurantHours) do not block them: a holiday can be closed to
 *    regular orders and still hand out pre-orders. The days-ahead limit does not apply either.
 *  - A cart is either regular items or the items of one pre-order menu: they are never mixed (they are picked up on
 *    different days and the kitchen plans pre-orders separately). The storefront says so when a customer tries.
 *
 * The date and slot maths is pure (category row + hours config + "now" in) and unit tested without a database.
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }
require_once __DIR__ . '/hours.php';

final class RestaurantPreorder
{
    /**
     * The pickup dates as typed. Returns date => windows ([] = "use that day's opening hours"), sorted by date, and
     * the lines that could not be read.
     *
     * @return array{dates:array<string,list<array{0:int,1:int}>>, bad:list<string>}
     */
    public static function parseDates(string $text): array
    {
        $dates = [];
        $bad = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));
            if ($line === '') continue;
            $parts = preg_split('/\s+/', $line, 2) ?: [];
            $day = RestaurantHours::date((string) ($parts[0] ?? ''));
            $win = isset($parts[1]) ? RestaurantHours::parseDay($parts[1]) : [];
            if ($day === null || $win === null) { $bad[] = $line; continue; }
            $key = $day->format('Y-m-d');
            if (isset($dates[$key])) {
                // The same date twice: its times join, and must still not overlap.
                $merged = RestaurantHours::parseDay(implode(', ', array_map(
                    static fn(array $w): string => RestaurantHours::hhmm($w[0]) . '-' . RestaurantHours::hhmm($w[1]),
                    array_merge($dates[$key], $win))));
                if ($merged === null) { $bad[] = $line; continue; }
                $win = $merged;
            }
            $dates[$key] = $win;
        }
        ksort($dates);
        return ['dates' => $dates, 'bad' => $bad];
    }

    /** The dates back to text for the category form (one per line, times as H:MM-H:MM). */
    public static function datesText(array $dates): string
    {
        $out = [];
        foreach ($dates as $d => $win) {
            $out[] = $d . ($win === [] ? '' : ' ' . implode(', ', array_map(
                static fn(array $w): string => RestaurantHours::hhmm($w[0]) . '-' . RestaurantHours::hhmm($w[1]), $win)));
        }
        return implode("\n", $out);
    }

    /** A stored "Y-m-d H:i[:s]" (site time) as a DateTime in the zone of $now, or null. */
    public static function at(?string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '' || str_starts_with($value, '0000')) return null;
        $t = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', strlen($value) === 16 ? $value . ':00' : $value, $tz)
          ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
        return $t ?: null;
    }

    /** Is $now inside the category's ordering window (ignoring whether pickup times are left)? */
    public static function inWindow(array $cat, \DateTimeImmutable $now): bool
    {
        if (empty($cat['preorder'])) return false;
        $from  = self::at($cat['preorder_from'] ?? null, $now->getTimezone());
        $until = self::at($cat['preorder_until'] ?? null, $now->getTimezone());
        return ($from === null || $now >= $from) && ($until === null || $now < $until);
    }

    /** Open for orders now: a pre-order category inside its window with at least one pickup time still on offer. */
    public static function isOpen(array $cat, array $hours, \DateTimeImmutable $now): bool
    {
        return self::inWindow($cat, $now) && self::slots($cat, $hours, $now) !== [];
    }

    /**
     * The pickup times a pre-order cart may choose, in date order. Values are "Y-m-d H:i", or "Y-m-d" for an
     * all-day choice.
     *
     * @return list<array{value:string, label:string, at:\DateTimeImmutable, all_day:bool}>
     */
    public static function slots(array $cat, array $hours, \DateTimeImmutable $now): array
    {
        $out = [];
        $ready = $now->modify('+' . (int) ($hours['lead'] ?? 0) . ' minutes');
        $today = $now->setTime(0, 0);
        foreach (self::parseDates((string) ($cat['preorder_dates'] ?? ''))['dates'] as $d => $win) {
            $day = new \DateTimeImmutable($d . ' 00:00:00', $now->getTimezone());
            if ($day < $today) continue;
            $windows = [];
            foreach ($win as [$a, $b]) $windows[] = [$day->modify("+{$a} minutes"), $day->modify("+{$b} minutes")];
            if ($windows === []) $windows = RestaurantHours::weekWindows($hours, $day);
            if ($windows === []) {
                $out[] = ['value' => $d, 'label' => sprintf(__('restaurant_po_all_day', '%s · any time'), RestaurantHours::dayLabel($day, $now)),
                          'at' => $day, 'all_day' => true];
                continue;
            }
            foreach ($windows as [$open, $close]) {
                foreach (RestaurantHours::stepTimes($open, $close, $ready, (int) ($hours['step'] ?? 15)) as $t) {
                    $out[] = ['value' => $t->format('Y-m-d H:i'), 'label' => RestaurantHours::label($t, $now), 'at' => $t, 'all_day' => false];
                }
            }
        }
        return $out;
    }

    /** What the order keeps as its requested time (the kitchen reads it later): "Pre-order · Wed Nov 25, 11:00 AM". */
    public static function savedText(array $slot): string
    {
        $when = $slot['all_day'] ? $slot['at']->format('D M j') : $slot['at']->format('D M j, g:i A');
        return mb_substr(__('restaurant_po_tag', 'Pre-order') . ' · ' . $when, 0, 40);
    }

    /** The line over the category's items: its own note, or "Pre-order · order by Sun Nov 22 · pickup Wed Nov 25". */
    public static function note(array $cat, array $hours, \DateTimeImmutable $now): string
    {
        $own = trim((string) ($cat['preorder_note'] ?? ''));
        if ($own !== '') return $own;
        $days = [];
        foreach (self::slots($cat, $hours, $now) as $s) $days[$s['at']->format('Y-m-d')] = $s['at']->format('D M j');
        $days = array_values($days);
        $pickup = count($days) <= 3 ? implode(', ', $days) : $days[0] . ' – ' . end($days);
        $until = self::at($cat['preorder_until'] ?? null, $now->getTimezone());
        return $until !== null
            ? sprintf(__('restaurant_po_note_until', 'Pre-order · order by %1$s · pickup %2$s'), $until->format('D M j, g:i A'), $pickup)
            : sprintf(__('restaurant_po_note', 'Pre-order · pickup %s'), $pickup);
    }

    /**
     * What a cart holds: regular items, the items of one pre-order menu, or a mix (never allowed).
     * $catOf maps item id => its category row (or null). Pure, so the rule is unit tested.
     *
     * @param callable(int):?array $catOf
     * @return array{kind:string, category:?array}
     */
    public static function group(array $cart, callable $catOf): array
    {
        $kind = null;
        $cat = null;
        foreach ($cart as $line) {
            $c = $catOf((int) ($line['item_id'] ?? 0));
            $isPre = $c !== null && !empty($c['preorder']);
            $k = $isPre ? 'preorder:' . (int) $c['id'] : 'regular';
            if ($kind === null) { $kind = $k; $cat = $isPre ? $c : null; continue; }
            if ($kind !== $k) return ['kind' => 'mixed', 'category' => null];
        }
        if ($kind === null) return ['kind' => 'empty', 'category' => null];
        return ['kind' => $cat !== null ? 'preorder' : 'regular', 'category' => $cat];
    }

    /** The message when an item would mix a pre-order with something else. */
    public static function mixMessage(): string
    {
        return __('restaurant_po_mix', 'Pre-orders are placed on their own, for their pickup date. Check out your cart first, or empty it to start this order.');
    }

    // ── Database side ────────────────────────────────────────

    /** The category row of an item (null when uncategorised). */
    public static function categoryOfItem(int $itemId): ?array
    {
        static $cache = [];
        $tid = current_tenant_id();
        if (!array_key_exists($tid . ':' . $itemId, $cache)) {
            $cache[$tid . ':' . $itemId] = Database::row(
                "SELECT c.* FROM restaurant_items i JOIN restaurant_menu_categories c ON c.id = i.category_id AND c.tenant_id = i.tenant_id
                  WHERE i.id = ? AND i.tenant_id = ?", [$itemId, $tid]) ?: null;
        }
        return $cache[$tid . ':' . $itemId];
    }

    /** The group of a cart, from the database. */
    public static function cartGroup(array $cart): array
    {
        return self::group($cart, [self::class, 'categoryOfItem']);
    }

    /**
     * Why an item cannot go into this cart now, or null when it can: a pre-order menu that is not open, or a mix.
     */
    public static function addError(array $cart, int $itemId, array $hours, \DateTimeImmutable $now): ?string
    {
        $cat = self::categoryOfItem($itemId);
        if ($cat !== null && !empty($cat['preorder']) && !self::isOpen($cat, $hours, $now)) {
            return __('restaurant_po_closed', 'Pre-orders for this menu are closed.');
        }
        $cart[] = ['item_id' => $itemId];
        return self::cartGroup($cart)['kind'] === 'mixed' ? self::mixMessage() : null;
    }
}
