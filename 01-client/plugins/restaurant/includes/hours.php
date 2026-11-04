<?php
/**
 * Restaurant — opening hours for online ordering.
 *
 * Settings (all under restaurant.*):
 *   online_hours_on   '1' to limit online orders to the hours below ('0' or unset: any time, as before)
 *   online_hours      JSON {mon: "12:00-14:30, 17:00-20:00", tue: "", ...}; empty = closed that day
 *   online_lead_min   minutes the kitchen needs before the first pickup (default 20)
 *   online_slot_min   minutes between pickup times offered (default 15)
 *   online_days_ahead days ahead a customer may order for (default 0 = today only; pre-orders use more)
 *
 * The maths is pure (config + "now" in, slots/status out) so it is unit tested without a database; the times are in
 * the site's time zone (Settings > Date & Time, slate_timezone()).
 */
if (!defined('SLATE_ROOT')) { http_response_code(404); exit; }

final class RestaurantHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    public const MAX_DAYS_AHEAD = 30;

    /**
     * One day's text ("12:00-14:30, 5pm-8pm") as [[startMinute, endMinute], ...], or null when a part cannot be read.
     * An empty string is a closed day ([]).
     *
     * @return list<array{0:int,1:int}>|null
     */
    public static function parseDay(string $text): ?array
    {
        $text = trim($text);
        if ($text === '' || preg_match('/^(closed|-)$/i', $text) === 1) return [];
        $out = [];
        foreach (preg_split('/\s*[,;]\s*/', $text) ?: [] as $part) {
            if ($part === '') continue;
            $bits = preg_split('/\s*(?:-|–|to)\s*/i', $part);
            if (!is_array($bits) || count($bits) !== 2) return null;
            $a = self::minute($bits[0]);
            $b = self::minute($bits[1]);
            // "5-9pm": a bare start takes the end's am/pm when that reads as the earlier time.
            if (preg_match('/(am|pm)$/i', trim($bits[1]), $ap) === 1 && preg_match('/^\s*\d{1,2}(?::\d{2})?\s*$/', $bits[0]) === 1) {
                $a2 = self::minute(trim($bits[0]) . $ap[1]);
                if ($a2 !== null && $b !== null && $a2 < $b) $a = $a2;
            }
            if ($a === null || $b === null || $b <= $a) return null;
            $out[] = [$a, $b];
        }
        usort($out, static fn(array $x, array $y): int => $x[0] <=> $y[0]);
        for ($i = 1; $i < count($out); $i++) {
            if ($out[$i][0] < $out[$i - 1][1]) return null;   // overlapping windows
        }
        return $out;
    }

    /** "17:30", "5:30pm", "5 pm", "12" -> minutes after midnight (24:00 allowed as an end), or null. */
    public static function minute(string $t): ?int
    {
        $t = strtolower(str_replace(' ', '', trim($t)));
        if (preg_match('/^(\d{1,2})(?::(\d{2}))?(am|pm)?$/', $t, $m) !== 1) return null;
        $h = (int) $m[1];
        $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
        $ap = $m[3] ?? '';
        if ($min > 59) return null;
        if ($ap !== '') {
            if ($h < 1 || $h > 12) return null;
            $h = $h % 12 + ($ap === 'pm' ? 12 : 0);
        }
        $total = $h * 60 + $min;
        return $total <= 24 * 60 ? $total : null;
    }

    /** Minutes back to "H:MM" (24-hour), as the settings screen shows them. */
    public static function hhmm(int $minute): string
    {
        return sprintf('%d:%02d', intdiv($minute, 60), $minute % 60);
    }

    /**
     * The saved config, cleaned.
     *
     * @return array{on:bool, week:array<string,list<array{0:int,1:int}>>, lead:int, step:int, ahead:int}
     */
    public static function config(): array
    {
        $raw = json_decode((string) RestaurantAPI::setting('online_hours', ''), true);
        $week = [];
        foreach (self::DAYS as $d) {
            $week[$d] = self::parseDay(is_array($raw) && is_string($raw[$d] ?? null) ? $raw[$d] : '') ?? [];
        }
        return self::clean([
            'on'    => (string) RestaurantAPI::setting('online_hours_on', '0') === '1',
            'week'  => $week,
            'lead'  => (int) RestaurantAPI::setting('online_lead_min', '20'),
            'step'  => (int) RestaurantAPI::setting('online_slot_min', '15'),
            'ahead' => (int) RestaurantAPI::setting('online_days_ahead', '0'),
        ]);
    }

    /** Bounds for the numbers (used by config() and by the settings screen on save). */
    public static function clean(array $c): array
    {
        $c['lead']  = max(0, min(24 * 60, (int) ($c['lead'] ?? 20)));
        $c['step']  = in_array((int) ($c['step'] ?? 15), [5, 10, 15, 20, 30, 60], true) ? (int) $c['step'] : 15;
        $c['ahead'] = max(0, min(self::MAX_DAYS_AHEAD, (int) ($c['ahead'] ?? 0)));
        $c['on']    = !empty($c['on']);
        return $c;
    }

    public static function tz(): \DateTimeZone
    {
        return new \DateTimeZone(function_exists('slate_timezone') ? slate_timezone() : (date_default_timezone_get() ?: 'UTC'));
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::tz());
    }

    /** The windows of a date, as [start, end] DateTimes in that date's zone. */
    private static function windows(array $c, \DateTimeImmutable $day): array
    {
        $key = self::DAYS[(int) $day->format('N') - 1];
        $midnight = $day->setTime(0, 0);
        $out = [];
        foreach ($c['week'][$key] ?? [] as [$a, $b]) {
            $out[] = [$midnight->modify("+{$a} minutes"), $midnight->modify("+{$b} minutes")];
        }
        return $out;
    }

    /**
     * Pickup times a customer may choose: ASAP while open (when the kitchen can still make it before closing), then
     * every `step` minutes from now + lead time, inside the windows, today and `ahead` days after.
     *
     * @return list<array{value:string, label:string, at:\DateTimeImmutable}>
     */
    public static function slots(array $c, \DateTimeImmutable $now): array
    {
        $out = [];
        $ready = $now->modify('+' . $c['lead'] . ' minutes');
        $step = $c['step'] * 60;
        $status = self::status($c, $now);
        if ($status['open'] && $status['until'] !== null && $ready <= $status['until']) {
            $out[] = ['value' => 'asap', 'label' => sprintf(__('restaurant_sf_asap_n', 'ASAP (about %d min)'), max(5, $c['lead'])), 'at' => $ready];
        }
        for ($d = 0; $d <= $c['ahead']; $d++) {
            $day = $now->setTime(0, 0)->modify("+{$d} days");
            foreach (self::windows($c, $day) as [$open, $close]) {
                $t = $open;
                if ($t < $ready) {
                    // First step-aligned time (counted from the window's opening) at or after the ready time.
                    $gap = $ready->getTimestamp() - $open->getTimestamp();
                    $t = $open->modify('+' . (int) (ceil($gap / $step) * $c['step']) . ' minutes');
                }
                for (; $t <= $close; $t = $t->modify('+' . $c['step'] . ' minutes')) {
                    $out[] = ['value' => $t->format('Y-m-d H:i'), 'label' => self::label($t, $now), 'at' => $t];
                }
            }
        }
        return $out;
    }

    /** "Today 12:15 PM", "Tomorrow 5:00 PM", "Thu Nov 6, 12:15 PM" (fits the order's 40-character time field). */
    public static function label(\DateTimeImmutable $t, \DateTimeImmutable $now): string
    {
        $days = self::daysFrom($t, $now);
        $time = $t->format('g:i A');
        if ($days === 0) return sprintf(__('restaurant_sf_today_at', 'Today %s'), $time);
        if ($days === 1) return sprintf(__('restaurant_sf_tomorrow_at', 'Tomorrow %s'), $time);
        return $t->format('D M j') . ', ' . $time;
    }

    /** "Today", "Tomorrow" or "Thu Nov 6": the heading over a day's pickup times. */
    public static function dayLabel(\DateTimeImmutable $t, \DateTimeImmutable $now): string
    {
        $days = self::daysFrom($t, $now);
        if ($days === 0) return __('restaurant_sf_today', 'Today');
        if ($days === 1) return __('restaurant_sf_tomorrow', 'Tomorrow');
        return $t->format('D M j');
    }

    private static function daysFrom(\DateTimeImmutable $t, \DateTimeImmutable $now): int
    {
        return (int) $now->setTime(0, 0)->diff($t->setTime(0, 0))->format('%r%a');
    }

    /**
     * Open right now? With the end of the current window, or the start of the next one (within 8 days).
     *
     * @return array{open:bool, until:?\DateTimeImmutable, next:?\DateTimeImmutable}
     */
    public static function status(array $c, \DateTimeImmutable $now): array
    {
        foreach (self::windows($c, $now) as [$a, $b]) {
            if ($now >= $a && $now < $b) return ['open' => true, 'until' => $b, 'next' => null];
        }
        for ($d = 0; $d <= 8; $d++) {
            foreach (self::windows($c, $now->setTime(0, 0)->modify("+{$d} days")) as [$a]) {
                if ($a > $now) return ['open' => false, 'until' => null, 'next' => $a];
            }
        }
        return ['open' => false, 'until' => null, 'next' => null];
    }

    /** The slot a posted value stands for, or null when it is not on offer now. */
    public static function pick(array $slots, string $value): ?array
    {
        foreach ($slots as $s) {
            if ($s['value'] === $value) return $s;
        }
        return null;
    }

    /** One line for the menu: "Open now · until 2:30 PM" / "Closed · opens Thu 12:00 PM". */
    public static function line(array $c, \DateTimeImmutable $now): string
    {
        $s = self::status($c, $now);
        if ($s['open']) return sprintf(__('restaurant_sf_open_until', 'Open now · until %s'), $s['until']->format('g:i A'));
        if ($s['next'] === null) return __('restaurant_sf_closed', 'Closed');
        return sprintf(__('restaurant_sf_closed_opens', 'Closed · opens %s'), self::label($s['next'], $now));
    }
}
