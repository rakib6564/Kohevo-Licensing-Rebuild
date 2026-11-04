<?php
/**
 * Raise a season's costume, tights and recital fees for every enrolled family.
 *
 * This is the one studio tool that creates money a parent actually owes, so it
 * is built to be run twice by mistake without consequence:
 *
 *  - Every row goes through StudioAPI::raiseFee(), whose dedupe_key carries a
 *    UNIQUE index. A second run finds the existing row and returns its id.
 *  - The preview computes from FeeSchedule (pure) rather than from the
 *    generators, so the printed total is an INDEPENDENT calculation. After
 *    --apply it re-reads the ledger and asserts the two agree; a mismatch is
 *    reported loudly rather than left for a parent to find.
 *
 * Season year anchors the calendar: costumes for season 2026 fall due Nov 1
 * 2026 and Feb 1 2027. The recital fee is dated from the recital's own year.
 *
 * DRY RUN BY DEFAULT.
 *   php plugins/studio/tools/raise_season_fees.php
 *   php plugins/studio/tools/raise_season_fees.php --apply
 *   php plugins/studio/tools/raise_season_fees.php --season=2026 --recital=7 --apply
 */
// Command line only: it ships inside the Studio plugin, whose folder a web server may reach.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

$ROOT = dirname(__DIR__, 3);
foreach (file($ROOT . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2); $k = trim($k); $v = trim($v);
    if (str_starts_with($k, 'DB_')) define($k, $v);
    if ($k === 'TENANT_ID') define('TENANT_ID', (int) $v);
}
require $ROOT . '/src/autoload.php';
class_alias(\Slate\Data\Database::class, 'Database');
require $ROOT . '/includes/helpers.php';
require $ROOT . '/plugins/studio/StudioAPI.php';

$APPLY  = in_array('--apply', $argv, true);
$TID    = defined('TENANT_ID') ? TENANT_ID : 1;
$season = (int) date('Y');
$recital = 0;
foreach ($argv as $a) {
    if (str_starts_with($a, '--season='))  { $season  = (int) substr($a, 9); }
    if (str_starts_with($a, '--recital=')) { $recital = (int) substr($a, 10); }
}

$fs    = StudioAPI::feeSchedule();
$money = static fn (int $c): string => '$' . number_format($c / 100, 2);

// Default to the soonest published recital if none was named.
if ($recital === 0) {
    $r = Database::row(
        "SELECT id, name, recital_date FROM studio_recitals
          WHERE tenant_id = ? AND status = 'published'
          ORDER BY recital_date LIMIT 1",
        [$TID]
    );
    if ($r) { $recital = (int) $r['id']; }
}
$recRow = $recital ? Database::row(
    "SELECT id, name, recital_date FROM studio_recitals WHERE tenant_id = ? AND id = ?",
    [$TID, $recital]
) : null;
$recYear = $recRow && $recRow['recital_date']
    ? (int) date('Y', strtotime((string) $recRow['recital_date']))
    : $season + 1;

echo $APPLY ? "APPLYING — tenant {$TID}\n" : "DRY RUN (no charges raised) — tenant {$TID}\n";
echo "season {$season}   costumes due "
   . implode(' / ', array_map(
        static fn ($p) => date('M j Y', strtotime($p['due_date'])),
        $fs->splitCostume(1000, $season)
     )) . "\n";
echo $recRow
    ? "recital #{$recRow['id']} \"{$recRow['name']}\" — fee due " . $fs->recitalDueDate($recYear) . "\n\n"
    : "no published recital — recital fees will be SKIPPED\n\n";

// Families with at least one actively enrolled child.
$families = Database::rows(
    "SELECT DISTINCT f.id, f.primary_parent_id, c.display_name
       FROM studio_families f
       JOIN studio_family_members fm ON fm.family_id = f.id AND fm.tenant_id = f.tenant_id
       JOIN studio_enrollments e     ON e.student_id = fm.contact_id AND e.tenant_id = f.tenant_id
       JOIN studio_class_series s    ON s.id = e.series_id AND s.tenant_id = e.tenant_id
       LEFT JOIN contacts c          ON c.id = f.primary_parent_id
      WHERE f.tenant_id = ? AND e.status = 'active' AND s.is_active = 1
      ORDER BY c.display_name",
    [$TID]
);

$grandCostume = 0; $grandRecital = 0; $studentsBilled = 0;

printf("%-26s %-24s %-9s %-9s %s\n", 'FAMILY', 'STUDENT', 'COSTUME', 'TIGHTS', 'CLASSES BILLED');
echo str_repeat('-', 100), "\n";

foreach ($families as $f) {
    $kids = Database::rows(
        "SELECT DISTINCT fm.contact_id AS id, c.display_name
           FROM studio_family_members fm
           JOIN studio_enrollments e   ON e.student_id = fm.contact_id AND e.tenant_id = fm.tenant_id
           JOIN studio_class_series s  ON s.id = e.series_id AND s.tenant_id = e.tenant_id
           LEFT JOIN contacts c        ON c.id = fm.contact_id
          WHERE fm.tenant_id = ? AND fm.family_id = ? AND e.status = 'active'
            AND s.is_active = 1
          ORDER BY c.display_name",
        [$TID, (int) $f['id']]
    );

    foreach ($kids as $k) {
        // Mirrors the generator exactly, is_active included — a preview that
        // disagrees with what would be written is worse than no preview.
        $classes = Database::rows(
            "SELECT s.name, s.style, s.age_min
               FROM studio_enrollments e
               JOIN studio_class_series s ON s.id = e.series_id AND s.tenant_id = e.tenant_id
              WHERE e.tenant_id = ? AND e.student_id = ? AND e.status = 'active'
                AND s.is_active = 1",
            [$TID, (int) $k['id']]
        );

        $billed = [];
        foreach ($classes as $c) {
            $ageMin = $c['age_min'] === null ? null : (int) $c['age_min'];
            if (!$fs->costumeExempt((string) $c['style'], $ageMin)) { $billed[] = (string) $c['name']; }
        }

        $costume = count($billed) * $fs->costumeCents();
        $tights  = $billed ? $fs->tightsCents() : 0;
        $grandCostume += $costume + $tights;
        if ($billed) { $studentsBilled++; }

        printf("%-26s %-24s %-9s %-9s %s\n",
            mb_substr((string) ($f['display_name'] ?? ('family #' . $f['id'])), 0, 26),
            mb_substr((string) ($k['display_name'] ?? ('#' . $k['id'])), 0, 24),
            $money($costume), $money($tights),
            $billed ? implode(', ', array_map(static fn ($n) => mb_substr($n, 0, 22), $billed)) : '(all exempt)');

        if ($APPLY) { StudioAPI::generateCostumeFeesForStudent((int) $k['id'], $season); }
    }

    if ($recRow) {
        $rf = $fs->recitalFeeForFamily(count($kids));
        $grandRecital += $rf;
        printf("%-26s %-24s %-9s %-9s %s\n", '', '→ recital fee', $money($rf), '',
            count($kids) . ' child' . (count($kids) === 1 ? '' : 'ren'));
        if ($APPLY) { StudioAPI::generateRecitalFeeForFamily((int) $f['id'], $recital, $recYear); }
    }
}

echo str_repeat('-', 100), "\n";
printf("families %d   students costumed %d\n", count($families), $studentsBilled);
printf("costumes + tights  %s\n", $money($grandCostume));
printf("recital fees       %s\n", $money($grandRecital));
printf("TOTAL RAISED       %s\n", $money($grandCostume + $grandRecital));

if ($APPLY) {
    // Independent re-read: the ledger must agree with the preview above.
    $ledger = (int) Database::value(
        "SELECT COALESCE(SUM(amount_cents),0) FROM studio_fees
          WHERE tenant_id = ? AND status <> 'void'", [$TID]
    );
    $rows = (int) Database::value(
        "SELECT COUNT(*) FROM studio_fees WHERE tenant_id = ?", [$TID]);
    echo "\nledger now: {$rows} rows, " . $money($ledger) . "\n";
    $expected = $grandCostume + $grandRecital;
    echo $ledger === $expected
        ? "✓ ledger matches the preview exactly\n"
        : "!! MISMATCH — preview {$expected}, ledger {$ledger}. Investigate before billing.\n";
} else {
    echo "\nDry run only. Re-run with --apply to raise these charges.\n";
}
