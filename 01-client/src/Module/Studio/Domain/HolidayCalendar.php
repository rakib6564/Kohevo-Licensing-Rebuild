<?php
/**
 * Studio — HolidayCalendar (pure).
 *
 * The dates a studio does not run. Company B "follow the Seaford School
 * District for holiday and inclement weather closing", which in practice means
 * an owner types a handful of dates and ranges at the start of a season and
 * expects the timetable to skip them.
 *
 * Accepts what a person actually types, not a strict format:
 *
 *   2026-12-24                 one date
 *   2026-12-24..2027-01-02     a range, inclusive of both ends
 *   2026-12-24 Christmas       a date with a label
 *   one per line, or comma-separated
 *
 * Being lenient matters more than being tidy here. A studio owner pasting
 * dates from an email is the actual use case, and a parser that rejects the
 * whole list because line four has a stray comma means the holidays silently
 * do not apply — classes get generated on Christmas Day and nobody notices
 * until a parent turns up.
 *
 * Ranges are capped (MAX_RANGE_DAYS) so a typo like 2026..2027 cannot expand
 * into a hundred thousand dates and hang the generator.
 *
 * Pure and DB-free: StudioAPI loads the setting and hands the text here.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class HolidayCalendar
{
    /** A single range longer than this is a typo, not a holiday. */
    public const MAX_RANGE_DAYS = 120;

    /** @var array<string,string> 'Y-m-d' => label */
    private array $dates;

    /** @param array<string,string> $dates */
    private function __construct(array $dates)
    {
        $this->dates = $dates;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Parse the stored holiday text. Unparseable lines are skipped, never
     * fatal — see the note above on why leniency wins here.
     */
    public static function fromText(?string $text): self
    {
        $text = trim((string) $text);
        if ($text === '') { return new self([]); }

        $out = [];
        foreach (preg_split('/[\r\n,]+/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') { continue; }

            // "2026-12-24..2027-01-02 Winter break" — split date part from label.
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?:\s*\.\.\s*(\d{4}-\d{2}-\d{2}))?\s*(.*)$/', $line, $m)) {
                continue;
            }
            $from  = $m[1];
            $to    = $m[2] !== '' ? $m[2] : $m[1];
            $label = trim($m[3]);

            if (!self::validDate($from) || !self::validDate($to)) { continue; }
            if ($to < $from) { [$from, $to] = [$to, $from]; }   // typed backwards

            $cursor = new \DateTimeImmutable($from);
            $end    = new \DateTimeImmutable($to);
            $days   = 0;
            while ($cursor <= $end && $days <= self::MAX_RANGE_DAYS) {
                $out[$cursor->format('Y-m-d')] = $label;
                $cursor = $cursor->modify('+1 day');
                $days++;
            }
        }
        ksort($out);
        return new self($out);
    }

    public function isHoliday(string $date): bool
    {
        return isset($this->dates[substr(trim($date), 0, 10)]);
    }

    /** The label for a date, or '' when it is not a holiday or has no label. */
    public function labelFor(string $date): string
    {
        return $this->dates[substr(trim($date), 0, 10)] ?? '';
    }

    /** @return array<string,string> 'Y-m-d' => label, ascending */
    public function all(): array
    {
        return $this->dates;
    }

    public function count(): int
    {
        return count($this->dates);
    }

    /**
     * Holidays from $from onward, for showing an owner what is still ahead.
     * A season's past closures are noise once they have happened.
     *
     * @return array<string,string>
     */
    public function upcoming(string $from, int $limit = 20): array
    {
        $from = substr(trim($from), 0, 10);
        $out  = [];
        foreach ($this->dates as $d => $label) {
            if ($d < $from) { continue; }
            $out[$d] = $label;
            if (count($out) >= $limit) { break; }
        }
        return $out;
    }

    /** Real calendar date, not merely well-shaped — 2026-02-30 must not pass. */
    private static function validDate(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) { return false; }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
