<?php
/**
 * Studio — SeasonShift (pure).
 *
 * Works out where each class lands when a season is duplicated.
 *
 * The whole design turns on one constraint: a Monday class must stay on
 * Monday. Parents arrange childcare around a weekday, teachers are contracted
 * for it, and rooms are booked on it. So the shift is measured in WHOLE WEEKS,
 * never in days — moving a term "126 days later" happens to preserve weekdays,
 * but "130 days later" silently moves every class in the studio to a Friday.
 *
 * Rounding to the nearest week is therefore not a convenience, it is the
 * correctness rule. If an owner picks a new start date that is a Wednesday
 * when the old season began on a Monday, the honest answer is "the term shifts
 * by N whole weeks and your Monday classes stay on Monday" — not "everything
 * is now two days out". delta() reports the rounding so the UI can say so.
 *
 * Pure and DB-free: StudioAPI reads the season and its classes, asks here
 * where they go, and writes the result.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class SeasonShift
{
    /**
     * Whole weeks between two dates, rounded to nearest.
     *
     * Returns null when either date is unusable — a caller must not silently
     * treat "no idea" as "no shift", which would stack a new season directly
     * on top of the old one.
     */
    public static function weeksBetween(string $from, string $to): ?int
    {
        $a = self::ts($from);
        $b = self::ts($to);
        if ($a === null || $b === null) { return null; }
        return (int) round(($b - $a) / (7 * 86400));
    }

    /** Move a date by whole weeks, preserving its weekday exactly. */
    public static function shift(string $date, int $weeks): ?string
    {
        $ts = self::ts($date);
        if ($ts === null) { return null; }
        return gmdate('Y-m-d', $ts + ($weeks * 7 * 86400));
    }

    /**
     * How far off the requested start the shifted season actually lands.
     *
     * Zero when the new start falls on the same weekday as the old. Otherwise
     * the number of days the UI should own up to, positive meaning the shifted
     * term begins after the date the owner typed.
     */
    public static function driftDays(string $oldStart, string $newStart): ?int
    {
        $weeks = self::weeksBetween($oldStart, $newStart);
        if ($weeks === null) { return null; }
        $landed = self::shift($oldStart, $weeks);
        $a = self::ts($landed ?? '');
        $b = self::ts($newStart);
        if ($a === null || $b === null) { return null; }
        return (int) round(($a - $b) / 86400);
    }

    /**
     * Where each class lands.
     *
     * Every class shifts by the SAME number of weeks — the season's shift —
     * rather than each being re-anchored to the new start. That preserves the
     * shape of the term: a class that ran three weeks late last season still
     * starts three weeks late, and an intensive that finished early still
     * finishes early.
     *
     * @param  array<int,array> $classes rows with session_start / session_end
     * @return array<int,array>          the same rows with dates replaced, and
     *                                   `_shift_weeks` recorded for the caller
     */
    public static function plan(array $classes, int $weeks): array
    {
        $out = [];
        foreach ($classes as $c) {
            if (!is_array($c)) { continue; }
            $start = self::shift((string) ($c['session_start'] ?? ''), $weeks);
            $end   = self::shift((string) ($c['session_end']   ?? ''), $weeks);

            // A class with unusable dates is carried through unshifted rather
            // than dropped: losing a class silently during a clone is worse
            // than one that needs its dates fixed by hand afterwards.
            $c['session_start'] = $start ?? ($c['session_start'] ?? null);
            $c['session_end']   = $end   ?? ($c['session_end']   ?? null);
            $c['_shift_weeks']  = ($start === null || $end === null) ? 0 : $weeks;
            $c['_shift_failed'] = ($start === null || $end === null);
            $out[] = $c;
        }
        return $out;
    }

    /**
     * A suggested name for the copy: "Fall 2026" → "Fall 2026 (copy)", and
     * anything already ending in (copy) gets a number rather than stacking
     * suffixes into "Fall 2026 (copy) (copy) (copy)".
     */
    public static function copyName(string $name): string
    {
        $name = trim($name);
        if ($name === '') { return 'Untitled season (copy)'; }
        if (preg_match('/^(.*)\((?:copy)(?:\s+(\d+))?\)$/i', $name, $m)) {
            $base = rtrim($m[1]);
            $n    = isset($m[2]) && $m[2] !== '' ? ((int) $m[2] + 1) : 2;
            return "{$base} (copy {$n})";
        }
        return "{$name} (copy)";
    }

    /** Midnight UTC timestamp for 'Y-m-d', or null. */
    private static function ts(string $d): ?int
    {
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) { return null; }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
        $ts = gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        return $ts === false ? null : $ts;
    }
}
