<?php
/**
 * Studio — TuitionPlan (pure).
 *
 * When tuition is charged, and in how many pieces.
 *
 * Studio only ever supported one shape: the whole term, once, at sign-up. Real
 * studios bill monthly, and they disagree about WHICH month a payment covers —
 * Class Manager exposes exactly three answers and so does this:
 *
 *   advance   charge in August for September      (money before the teaching)
 *   current   charge in September for September   (money during)
 *   arrears   charge in September for August      (money after)
 *
 * The distinction is not cosmetic. It decides whether a studio holds a term's
 * fees before it has paid a teacher, and whether a family who leaves in
 * October owes anything. Getting it wrong by a month is a real dispute.
 *
 * Two invariants the tests pin, both about money:
 *
 *  1. The instalments sum to EXACTLY the total. Percentages and division both
 *     shed pennies; the remainder is pushed onto the FIRST instalment so a
 *     studio never bills a cent more or less than it quoted.
 *  2. An instalment is never zero. Splitting $85 into twelve would produce
 *     $7.08 lines and a $7.12 one, but splitting $5 into twelve produces
 *     nothing worth invoicing — so the count is reduced until each part is at
 *     least MIN_INSTALMENT_CENTS rather than emitting $0.00 rows a parent
 *     cannot pay.
 *
 * Pure and DB-free: StudioAPI reads the settings and the term, and asks here.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class TuitionPlan
{
    public const CADENCES = ['advance', 'current', 'arrears'];

    /** Below this, an instalment is not worth invoicing. */
    public const MIN_INSTALMENT_CENTS = 500;

    /** A term is never split more finely than this. */
    public const MAX_INSTALMENTS = 12;

    private string $cadence;
    private int $instalments;

    private function __construct(string $cadence, int $instalments)
    {
        $this->cadence     = $cadence;
        $this->instalments = $instalments;
    }

    /** Pay the term in full at sign-up — what Studio did before this existed. */
    public static function inFull(): self
    {
        return new self('current', 1);
    }

    public static function make(?string $cadence, int $instalments = 1): self
    {
        $c = strtolower(trim((string) $cadence));
        if (!in_array($c, self::CADENCES, true)) { $c = 'current'; }
        $n = max(1, min(self::MAX_INSTALMENTS, $instalments));
        return new self($c, $n);
    }

    public function cadence(): string
    {
        return $this->cadence;
    }

    public function instalments(): int
    {
        return $this->instalments;
    }

    public function isInFull(): bool
    {
        return $this->instalments === 1;
    }

    /**
     * How many instalments this total can actually carry.
     *
     * Reduced until each part clears MIN_INSTALMENT_CENTS, so a cheap class on
     * a twelve-month plan bills in a few sensible pieces rather than twelve
     * unpayable ones. Never below one.
     */
    public function usableInstalments(int $totalCents): int
    {
        $totalCents = max(0, $totalCents);
        if ($totalCents === 0) { return 1; }
        $n = $this->instalments;
        while ($n > 1 && intdiv($totalCents, $n) < self::MIN_INSTALMENT_CENTS) {
            $n--;
        }
        return $n;
    }

    /**
     * The dated charges for one term.
     *
     * Instalments land on the same day-of-month as the term start, one month
     * apart, then the cadence shifts the whole run: advance pulls it a month
     * earlier, arrears pushes it a month later.
     *
     * @return array<int,array{due_date:string,amount_cents:int,seq:int,of:int,label:string}>
     */
    public function schedule(int $totalCents, string $termStart): array
    {
        $totalCents = max(0, $totalCents);
        if ($totalCents === 0) { return []; }

        $anchor = self::parse($termStart);
        if ($anchor === null) { return []; }

        $n = $this->usableInstalments($totalCents);

        // Even split, remainder on the first — never lose or invent a cent.
        $base  = intdiv($totalCents, $n);
        $parts = array_fill(0, $n, $base);
        $parts[0] += $totalCents - ($base * $n);

        $offset = match ($this->cadence) {
            'advance' => -1,
            'arrears' => 1,
            default   => 0,
        };

        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'due_date'     => self::addMonths($anchor, $i + $offset),
                'amount_cents' => $parts[$i],
                'seq'          => $i + 1,
                'of'           => $n,
                'label'        => $n === 1
                    ? 'Tuition'
                    : sprintf('Tuition (%d of %d)', $i + 1, $n),
            ];
        }
        return $out;
    }

    /** Plain-English summary for the settings screen and the checkout. */
    public function describe(): string
    {
        $when = match ($this->cadence) {
            'advance' => 'a month in advance',
            'arrears' => 'a month in arrears',
            default   => 'for the current month',
        };
        return $this->isInFull()
            ? 'Paid in full at sign-up'
            : sprintf('%d monthly payments, billed %s', $this->instalments, $when);
    }

    /**
     * Add whole months, clamping the day so 31 January + 1 month is 28/29
     * February rather than rolling into March — PHP's native +1 month does
     * roll, which would silently move a due date past a month boundary.
     */
    private static function addMonths(\DateTimeImmutable $d, int $months): string
    {
        $day   = (int) $d->format('j');
        $first = $d->modify('first day of this month')->modify(sprintf('%+d months', $months));
        $last  = (int) $first->format('t');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last))
                     ->format('Y-m-d');
    }

    private static function parse(string $d): ?\DateTimeImmutable
    {
        $d = trim($d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) { return null; }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) { return null; }
        return new \DateTimeImmutable(sprintf('%s-%s-%s 00:00:00', $m[1], $m[2], $m[3]),
            new \DateTimeZone('UTC'));
    }
}
