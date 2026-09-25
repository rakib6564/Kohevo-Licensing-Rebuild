<?php
/**
 * Studio — FeeSchedule (pure).
 *
 * The non-tuition money a studio charges: registration, the recital
 * performance fee, costumes and tights. Tuition is TuitionCalculator +
 * DiscountPolicy; this is everything billed *alongside* it.
 *
 * Modelled on Company B's published policy, but every number is configurable
 * for the same reason DiscountPolicy exists — the previous hardcoded costume
 * cost was one studio's figure baked into the domain.
 *
 * The rules that aren't just a number:
 *
 *  - The recital fee is charged per FAMILY, not per student: the first
 *    enrolled child pays full, each additional child a reduced rate. A studio
 *    with three siblings bills 100 + 50 + 50, not 300.
 *  - Costumes are charged per CLASS, so a student in four classes owes four
 *    costume fees — but technique and older ballet classes are exempt, because
 *    those classes don't costume for the recital.
 *  - Tights are once per student, not once per class, however many costumes
 *    they need. A second pair is an explicit extra, not an automatic one.
 *  - Costume money is split across instalments (Company B: 50% Nov 1, 50%
 *    Feb 1). Splitting money is where rounding bugs live, so the remainder is
 *    pushed onto the FIRST instalment and the parts are asserted to re-sum to
 *    the total — a studio must never bill 1¢ more or less than it quoted.
 *
 * Pure and DB-free: StudioAPI loads the numbers from settings and hands them
 * here, which keeps the arithmetic unit-testable with no database.
 */

declare(strict_types=1);

namespace Slate\Module\Studio\Domain;

final class FeeSchedule
{
    /** Styles that never carry a costume fee, whatever the student's age. */
    public const EXEMPT_STYLES = ['technique'];

    /**
     * Ballet is exempt only from this age up — the little ones still costume.
     * Company B: "no costume fee for technique classes and ballet classes
     * (Ages 8 & up)".
     */
    public const BALLET_EXEMPT_FROM_AGE = 8;

    private int $registrationCents;
    private int $recitalFirstCents;
    private int $recitalAdditionalCents;
    private string $recitalDueMonthDay;      // 'MM-DD'
    private int $costumeCents;
    private int $tightsCents;

    /** @var array<int,array{month_day:string,percent:float}> in charge order */
    private array $costumeInstalments;

    private function __construct(
        int $registrationCents,
        int $recitalFirstCents,
        int $recitalAdditionalCents,
        string $recitalDueMonthDay,
        int $costumeCents,
        int $tightsCents,
        array $costumeInstalments
    ) {
        $this->registrationCents      = max(0, $registrationCents);
        $this->recitalFirstCents      = max(0, $recitalFirstCents);
        $this->recitalAdditionalCents = max(0, $recitalAdditionalCents);
        $this->recitalDueMonthDay     = self::normaliseMonthDay($recitalDueMonthDay, '05-01');
        $this->costumeCents           = max(0, $costumeCents);
        $this->tightsCents            = max(0, $tightsCents);
        $this->costumeInstalments     = $costumeInstalments;
    }

    /** Company B's published schedule — and what an unconfigured tenant gets. */
    public static function default(): self
    {
        return new self(
            0,          // registration is free
            10000,      // $100 first child
            5000,       // $50 each additional child
            '05-01',    // processed May 1
            9500,       // $95 per costumed class
            1000,       // $10 tights, once per student
            [
                ['month_day' => '11-01', 'percent' => 50.0],
                ['month_day' => '02-01', 'percent' => 50.0],
            ]
        );
    }

    /**
     * Build from stored settings, falling back per-key to the defaults.
     *
     * Deliberately lenient: a studio half-way through configuring fees must
     * still be able to take money, so an absent or unparseable key falls back
     * rather than throwing.
     *
     * @param array<string,mixed> $s
     */
    public static function fromArray(array $s): self
    {
        $d   = self::default();
        $int = static function (array $s, string $k, int $fallback): int {
            if (!array_key_exists($k, $s)) { return $fallback; }
            $v = $s[$k];
            if ($v === '' || $v === null) { return $fallback; }
            return is_numeric($v) ? max(0, (int) $v) : $fallback;
        };

        $instalments = $d->costumeInstalments;
        if (isset($s['costume_instalments'])) {
            $raw = $s['costume_instalments'];
            if (is_string($raw)) { $raw = json_decode($raw, true); }
            $parsed = self::parseInstalments(is_array($raw) ? $raw : []);
            if ($parsed !== []) { $instalments = $parsed; }
        }

        return new self(
            $int($s, 'registration_cents',       $d->registrationCents),
            $int($s, 'recital_first_cents',      $d->recitalFirstCents),
            $int($s, 'recital_additional_cents', $d->recitalAdditionalCents),
            isset($s['recital_due']) && is_string($s['recital_due']) && $s['recital_due'] !== ''
                ? $s['recital_due'] : $d->recitalDueMonthDay,
            $int($s, 'costume_cents',            $d->costumeCents),
            $int($s, 'tights_cents',             $d->tightsCents),
            $instalments
        );
    }

    // ── Registration ──────────────────────────────────────────────────

    public function registrationCents(): int
    {
        return $this->registrationCents;
    }

    /** True when the studio advertises free registration. */
    public function registrationIsFree(): bool
    {
        return $this->registrationCents === 0;
    }

    // ── Recital performance fee ───────────────────────────────────────

    /**
     * What one family owes for $childCount enrolled children.
     *
     * First child full, the rest at the additional rate. Zero children owe
     * nothing — a family with every child dropped must not be billed.
     */
    public function recitalFeeForFamily(int $childCount): int
    {
        if ($childCount <= 0) { return 0; }
        return $this->recitalFirstCents + (($childCount - 1) * $this->recitalAdditionalCents);
    }

    /** The per-child breakdown behind recitalFeeForFamily(), for an invoice. */
    public function recitalFeeBreakdown(int $childCount): array
    {
        $out = [];
        for ($i = 0; $i < max(0, $childCount); $i++) {
            $out[] = $i === 0 ? $this->recitalFirstCents : $this->recitalAdditionalCents;
        }
        return $out;
    }

    /** Due date for a given season year, e.g. recitalDueDate(2027) => 2027-05-01. */
    public function recitalDueDate(int $year): string
    {
        return sprintf('%04d-%s', $year, $this->recitalDueMonthDay);
    }

    // ── Costumes and tights ───────────────────────────────────────────

    public function costumeCents(): int
    {
        return $this->costumeCents;
    }

    public function tightsCents(): int
    {
        return $this->tightsCents;
    }

    /**
     * Does a class in this style carry a costume fee?
     *
     * $ageMin is the class's minimum age, which is what decides the ballet
     * exemption — an "Ages 8 & up" ballet class is exempt, "Ages 4-7" is not.
     * A null age is treated as NOT exempt: charging and refunding is
     * recoverable, silently under-billing a whole class is not.
     */
    public function costumeExempt(string $style, ?int $ageMin = null): bool
    {
        $style = strtolower(trim($style));
        if ($style === '') { return false; }

        foreach (self::EXEMPT_STYLES as $ex) {
            if (str_contains($style, $ex)) { return true; }
        }
        if ($style === 'ballet' && $ageMin !== null && $ageMin >= self::BALLET_EXEMPT_FROM_AGE) {
            return true;
        }
        return false;
    }

    /**
     * Total costume charge for one student across their classes.
     *
     * @param array<int,array{style:string,age_min?:int|null}> $classes
     * @param bool $includeTights Tights are once per student, not per class.
     */
    public function costumeTotalForStudent(array $classes, bool $includeTights = true): int
    {
        $costumed = 0;
        foreach ($classes as $c) {
            if (!is_array($c)) { continue; }
            $style  = (string) ($c['style'] ?? '');
            $ageMin = array_key_exists('age_min', $c) && $c['age_min'] !== null
                ? (int) $c['age_min'] : null;
            if (!$this->costumeExempt($style, $ageMin)) { $costumed++; }
        }
        if ($costumed === 0) { return 0; }

        // No costumes, no tights — tights exist to go under a costume.
        return ($costumed * $this->costumeCents)
             + ($includeTights ? $this->tightsCents : 0);
    }

    // ── Instalments ───────────────────────────────────────────────────

    /**
     * Split a total across the configured instalments.
     *
     * The season year anchors the calendar: instalments run in the order
     * configured, and any month_day earlier than the first one's is taken to
     * fall in the FOLLOWING year — which is what makes Nov 1 → Feb 1 span the
     * new year without the caller doing date arithmetic.
     *
     * Rounding: percentages are applied with intdiv-style flooring and the
     * remainder lands on the first instalment, so the parts always re-sum to
     * exactly $totalCents.
     *
     * @return array<int,array{due_date:string,amount_cents:int,instalment_no:int,instalment_of:int}>
     */
    public function splitCostume(int $totalCents, int $seasonYear): array
    {
        $totalCents = max(0, $totalCents);
        $plan = $this->costumeInstalments;
        if ($plan === []) { return []; }
        if ($totalCents === 0) { return []; }

        $n         = count($plan);
        $firstDay  = $plan[0]['month_day'];
        $parts     = [];
        $allocated = 0;

        foreach ($plan as $i => $step) {
            $amount = (int) floor($totalCents * ($step['percent'] / 100));
            $parts[$i] = $amount;
            $allocated += $amount;
        }

        // Whatever flooring shed goes onto the first instalment. Never drop a
        // cent, and never bill one the studio didn't quote.
        $parts[0] += ($totalCents - $allocated);

        $out = [];
        foreach ($plan as $i => $step) {
            $year = $step['month_day'] < $firstDay ? $seasonYear + 1 : $seasonYear;
            $out[] = [
                'due_date'      => sprintf('%04d-%s', $year, $step['month_day']),
                'amount_cents'  => $parts[$i],
                'instalment_no' => $i + 1,
                'instalment_of' => $n,
            ];
        }
        return $out;
    }

    /** @return array<int,array{month_day:string,percent:float}> */
    public function costumeInstalments(): array
    {
        return $this->costumeInstalments;
    }

    // ── Internals ─────────────────────────────────────────────────────

    /**
     * Accept [['month_day'=>'11-01','percent'=>50], …] or a terse
     * ['11-01' => 50, '02-01' => 50]. Steps that don't parse are dropped;
     * a set that doesn't total 100% is rejected outright rather than
     * silently under-billing.
     *
     * @return array<int,array{month_day:string,percent:float}>
     */
    private static function parseInstalments(array $raw): array
    {
        $out = [];
        foreach ($raw as $k => $v) {
            if (is_array($v)) {
                $md  = (string) ($v['month_day'] ?? '');
                $pct = $v['percent'] ?? null;
            } else {
                $md  = (string) $k;
                $pct = $v;
            }
            $md = self::normaliseMonthDay($md, '');
            if ($md === '' || !is_numeric($pct)) { continue; }
            $pct = (float) $pct;
            if ($pct <= 0) { continue; }
            $out[] = ['month_day' => $md, 'percent' => $pct];
        }
        if ($out === []) { return []; }

        $sum = 0.0;
        foreach ($out as $s) { $sum += $s['percent']; }
        // Tolerate float noise, reject a genuinely wrong plan (e.g. 50 + 40).
        if (abs($sum - 100.0) > 0.01) { return []; }

        return $out;
    }

    /** 'MM-DD', or $fallback when the input isn't a valid month/day. */
    private static function normaliseMonthDay(string $md, string $fallback): string
    {
        $md = trim($md);
        if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', $md, $m)) { return $fallback; }
        $mo = (int) $m[1];
        $dy = (int) $m[2];
        if ($mo < 1 || $mo > 12 || $dy < 1 || $dy > 31) { return $fallback; }
        return sprintf('%02d-%02d', $mo, $dy);
    }
}
