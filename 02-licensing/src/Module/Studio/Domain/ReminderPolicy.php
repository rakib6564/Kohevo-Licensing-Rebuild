<?php
/**
 * Studio — ReminderPolicy (pure).
 *
 * Decides when an outstanding fee should prompt an email, and guarantees it
 * prompts once. Chasing money is the feature a studio owner asks about first,
 * and it is also the one that most easily turns into spam — so the rules are
 * narrow and the state is explicit.
 *
 * Steps are days relative to the due date:
 *
 *   -7   "due in a week"      a nudge before anyone is late
 *    0   "due today"
 *   +7   "a week overdue"
 *   +14  "two weeks overdue"
 *   +30  "a month overdue"
 *
 * Two rules do the real work:
 *
 *  1. The step chosen is the LARGEST one at or below today's offset that has
 *     not already been sent. A fee that slipped unnoticed for 40 days sends
 *     the "a month overdue" step — not the "due in a week" one it technically
 *     also qualifies for. Catching up must not mean replaying the sequence.
 *
 *  2. A step already sent never sends again. The caller records what went out
 *     on the fee itself, so this stays a pure function of (due, today, sent)
 *     and is testable without a mailer or a clock.
 *
 * Deliberately NOT here: who to email, how to group, what to say. This answers
 * only "is this fee due a nudge, and which one" — StudioAPI batches the answers
 * per family so a parent with eleven costume instalments gets one email.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class ReminderPolicy
{
    /** Days relative to the due date, ascending. */
    public const STEPS = [-7, 0, 7, 14, 30];

    /** @var int[] */
    private array $steps;

    /** @param int[] $steps */
    private function __construct(array $steps)
    {
        sort($steps);
        $this->steps = array_values(array_unique($steps));
    }

    public static function default(): self
    {
        return new self(self::STEPS);
    }

    /**
     * Build from a stored setting: "-7,0,7,14,30". Anything unparseable falls
     * back to the default rather than producing an empty schedule, because an
     * empty schedule silently stops all chasing — the failure mode a studio
     * would notice only when the money never arrived.
     */
    public static function fromString(?string $csv): self
    {
        $csv = trim((string) $csv);
        if ($csv === '') { return self::default(); }

        $out = [];
        foreach (explode(',', $csv) as $part) {
            $part = trim($part);
            if ($part === '' || !preg_match('/^-?\d{1,3}$/', $part)) { continue; }
            $out[] = (int) $part;
        }
        return $out === [] ? self::default() : new self($out);
    }

    /** @return int[] */
    public function steps(): array
    {
        return $this->steps;
    }

    /**
     * Whole days from $due to $today. Positive means overdue.
     *
     * Compared at date granularity in UTC so a reminder run at 23:00 and one at
     * 01:00 the next day do not disagree about what "today" is by a few hours.
     */
    public static function offsetDays(string $due, string $today): ?int
    {
        $d = self::parseDate($due);
        $t = self::parseDate($today);
        if ($d === null || $t === null) { return null; }
        return (int) round(($t - $d) / 86400);
    }

    /**
     * The step to send now, or null for nothing.
     *
     * @param string   $due    'Y-m-d'
     * @param string   $today  'Y-m-d'
     * @param int[]    $sent   steps already sent for this fee
     */
    public function stepFor(string $due, string $today, array $sent = []): ?int
    {
        $offset = self::offsetDays($due, $today);
        if ($offset === null) { return null; }

        $sent = array_map('intval', $sent);

        // Largest step at or below today's offset, skipping anything sent.
        $best = null;
        foreach ($this->steps as $step) {
            if ($step > $offset) { break; }          // steps are sorted
            if (in_array($step, $sent, true)) { continue; }
            $best = $step;
        }
        return $best;
    }

    /**
     * Human label for a step, used for the subject line and the tone of the
     * message. Kept here so the wording cannot drift from the schedule.
     */
    public static function label(int $step): string
    {
        if ($step < 0)  { return 'upcoming'; }
        if ($step === 0) { return 'due'; }
        return 'overdue';
    }

    /** Midnight UTC timestamp for 'Y-m-d', or null. */
    private static function parseDate(string $d): ?int
    {
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) { return null; }
        $ts = gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
        return $ts === false ? null : $ts;
    }
}
