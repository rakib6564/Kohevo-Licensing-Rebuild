<?php
/**
 * Studio — ScheduleConflict (pure).
 *
 * A studio's timetable is a weekly grid: each class series repeats on one
 * weekday between two clock times. Two things must never collide on that grid:
 *
 *   room        two classes cannot share Studio A at 4pm
 *   instructor  Ryann cannot teach ballet and tap simultaneously
 *
 * Deliberately NOT reusing BookingAPI::freeResourceId(). That sums party_size
 * against a resource's capacity, because several appointments legitimately
 * share a room — a 10-seat room holds ten one-to-one bookings. A dance class
 * occupies the studio exclusively for its hour regardless of how many dancers
 * are in it, so capacity is the wrong axis entirely. The rule here is
 * exclusive occupancy, which is a different question with a different answer.
 *
 * Back-to-back is not a clash. A 10:00–11:00 followed by 11:00–12:00 in the
 * same room is how a studio actually runs, so the comparison is strict:
 * `startA < endB && startB < endA`. Using <= would flag every clean handover
 * in the timetable and make the whole feature noise.
 *
 * Pure and DB-free: StudioAPI reads the series rows and hands them here, so
 * the overlap arithmetic is unit-testable with no database.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class ScheduleConflict
{
    public const KIND_ROOM       = 'room';
    public const KIND_INSTRUCTOR = 'instructor';

    /**
     * Minutes past midnight for "16:00", "16:00:00" or "9:30".
     * Returns null for anything unparseable — a malformed time must drop out
     * of the comparison rather than land at midnight and clash with everything.
     */
    public static function minutes(?string $time): ?int
    {
        $time = trim((string) $time);
        if ($time === '' || !preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $m)) {
            return null;
        }
        $h = (int) $m[1];
        $i = (int) $m[2];
        if ($h > 23 || $i > 59) { return null; }
        return ($h * 60) + $i;
    }

    /**
     * Do two blocks occupy overlapping time on the same weekday?
     *
     * Strict inequality on both sides, so touching endpoints (a class ending
     * exactly as the next begins) is not an overlap.
     */
    public static function overlaps(array $a, array $b): bool
    {
        if ((int) ($a['day_of_week'] ?? -1) !== (int) ($b['day_of_week'] ?? -2)) {
            return false;
        }
        $aStart = self::minutes($a['start_time'] ?? null);
        $aEnd   = self::minutes($a['end_time'] ?? null);
        $bStart = self::minutes($b['start_time'] ?? null);
        $bEnd   = self::minutes($b['end_time'] ?? null);

        if ($aStart === null || $aEnd === null || $bStart === null || $bEnd === null) {
            return false;
        }
        // A zero- or negative-length block cannot collide with anything.
        if ($aEnd <= $aStart || $bEnd <= $bStart) { return false; }

        return $aStart < $bEnd && $bStart < $aEnd;
    }

    /**
     * Conflicts between one proposed block and a set of existing ones.
     *
     * A block is compared against its own id, which is what makes this usable
     * when EDITING a class: the row being saved is in $existing, and comparing
     * it to itself would report every class as clashing with itself.
     *
     * @param  array $candidate            day_of_week, start_time, end_time,
     *                                     room_id, instructor_id, id, name
     * @param  array<int,array> $existing  the same shape
     * @return array<int,array{kind:string,with:array}>
     */
    public static function against(array $candidate, array $existing): array
    {
        $out = [];
        $selfId = isset($candidate['id']) ? (int) $candidate['id'] : 0;

        $room  = isset($candidate['room_id'])       && $candidate['room_id']       !== null
               ? (int) $candidate['room_id'] : null;
        $instr = isset($candidate['instructor_id']) && $candidate['instructor_id'] !== null
               ? (int) $candidate['instructor_id'] : null;

        foreach ($existing as $other) {
            if (!is_array($other)) { continue; }
            if ($selfId > 0 && (int) ($other['id'] ?? 0) === $selfId) { continue; }
            if (!self::overlaps($candidate, $other)) { continue; }

            // An unassigned room is not a conflict: plenty of studios schedule
            // first and allocate space later, and reporting "both unassigned"
            // as a clash would make every new class look broken.
            $otherRoom = isset($other['room_id']) && $other['room_id'] !== null
                       ? (int) $other['room_id'] : null;
            if ($room !== null && $otherRoom !== null && $room === $otherRoom) {
                $out[] = ['kind' => self::KIND_ROOM, 'with' => $other];
            }

            $otherInstr = isset($other['instructor_id']) && $other['instructor_id'] !== null
                        ? (int) $other['instructor_id'] : null;
            if ($instr !== null && $otherInstr !== null && $instr === $otherInstr) {
                $out[] = ['kind' => self::KIND_INSTRUCTOR, 'with' => $other];
            }
        }
        return $out;
    }

    /**
     * Every conflicting pair across a whole timetable, each reported once.
     *
     * Pairs are keyed by the lower id first so A-clashes-with-B and
     * B-clashes-with-A collapse into a single finding — an admin wants one row
     * per problem, not two rows describing the same problem from each side.
     *
     * @param  array<int,array> $blocks
     * @return array<int,array{kind:string,a:array,b:array}>
     */
    public static function findAll(array $blocks): array
    {
        $blocks = array_values(array_filter($blocks, 'is_array'));
        $seen   = [];
        $out    = [];

        foreach ($blocks as $i => $a) {
            foreach ($blocks as $j => $b) {
                if ($j <= $i) { continue; }
                if (!self::overlaps($a, $b)) { continue; }

                $aId = (int) ($a['id'] ?? $i);
                $bId = (int) ($b['id'] ?? $j);
                [$lo, $hi] = $aId <= $bId ? [$a, $b] : [$b, $a];

                foreach ([
                    self::KIND_ROOM       => 'room_id',
                    self::KIND_INSTRUCTOR => 'instructor_id',
                ] as $kind => $field) {
                    $av = isset($a[$field]) && $a[$field] !== null ? (int) $a[$field] : null;
                    $bv = isset($b[$field]) && $b[$field] !== null ? (int) $b[$field] : null;
                    if ($av === null || $bv === null || $av !== $bv) { continue; }

                    $key = $kind . ':' . min($aId, $bId) . ':' . max($aId, $bId);
                    if (isset($seen[$key])) { continue; }
                    $seen[$key] = true;
                    $out[] = ['kind' => $kind, 'a' => $lo, 'b' => $hi];
                }
            }
        }
        return $out;
    }

    /** Human summary for one conflict, e.g. "Studio A · Mon 16:00–17:00". */
    public static function describe(array $conflict): string
    {
        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $b    = $conflict['with'] ?? $conflict['b'] ?? [];
        $day  = $days[(int) ($b['day_of_week'] ?? 0)] ?? '';
        $from = substr((string) ($b['start_time'] ?? ''), 0, 5);
        $to   = substr((string) ($b['end_time'] ?? ''), 0, 5);
        return trim(sprintf('%s · %s %s–%s', (string) ($b['name'] ?? ''), $day, $from, $to), ' ·');
    }
}
