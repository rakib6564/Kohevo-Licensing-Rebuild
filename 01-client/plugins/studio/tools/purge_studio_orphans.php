<?php
/**
 * Purge studio rows whose contact no longer exists, and optionally detach a
 * family from the studio without deleting the person behind it.
 *
 * Why this is needed at all: cleanup_companyb.php finds what to delete by
 * collecting ids where JSON_EXTRACT(meta,'$.demo')='companyb' and then removing
 * dependents of those id sets. That works exactly once. If the tagged CONTACTS
 * are deleted first — by hand, by a partial run, by anything — the tool can no
 * longer reach the studio rows that referenced them, and they are stranded
 * forever. Company B's tenant reached 22 of 29 active enrolments pointing at
 * students with no contacts row, which the fee generator then happily billed.
 *
 * So this tool keys off referential reality (LEFT JOIN … IS NULL) rather than
 * off a tag, and can therefore clean up after any deletion path.
 *
 * --detach-family=ID removes a family's studio rows (family, members,
 * enrolments, roles for its members) while LEAVING contacts alone. That is the
 * right shape for a test account: contact #3 is a real customer with a login,
 * and deleting it would break an account rather than clean data.
 *
 * DRY RUN BY DEFAULT.
 *   php plugins/studio/tools/purge_studio_orphans.php
 *   php plugins/studio/tools/purge_studio_orphans.php --apply
 *   php plugins/studio/tools/purge_studio_orphans.php --detach-family=34 --detach-family=44 --apply
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

$APPLY = in_array('--apply', $argv, true);
$TID   = defined('TENANT_ID') ? TENANT_ID : 1;

$detach = [];
foreach ($argv as $a) {
    if (str_starts_with($a, '--detach-family=')) { $detach[] = (int) substr($a, 16); }
}
$detach = array_values(array_filter(array_unique($detach)));

echo $APPLY ? "APPLYING — tenant {$TID}\n\n" : "DRY RUN (nothing deleted) — tenant {$TID}\n\n";

/** Orphan probes, in child-before-parent order. */
$probes = [
    'studio_costumes' => "SELECT co.id FROM studio_costumes co
                            LEFT JOIN contacts c ON c.id = co.student_id
                           WHERE co.tenant_id = {$TID} AND co.student_id IS NOT NULL AND c.id IS NULL",
    'studio_fees' => "SELECT f.id FROM studio_fees f
                        LEFT JOIN contacts c ON c.id = f.student_id
                       WHERE f.tenant_id = {$TID} AND f.student_id IS NOT NULL AND c.id IS NULL",
    'studio_attendance' => "SELECT a.id FROM studio_attendance a
                              LEFT JOIN contacts c ON c.id = a.student_id
                             WHERE a.tenant_id = {$TID} AND c.id IS NULL",
    'studio_enrollments' => "SELECT e.id FROM studio_enrollments e
                               LEFT JOIN contacts c ON c.id = e.student_id
                              WHERE e.tenant_id = {$TID} AND c.id IS NULL",
    'studio_family_members' => "SELECT fm.id FROM studio_family_members fm
                                  LEFT JOIN contacts c ON c.id = fm.contact_id
                                 WHERE fm.tenant_id = {$TID} AND c.id IS NULL",
    'studio_contact_roles' => "SELECT r.id FROM studio_contact_roles r
                                 LEFT JOIN contacts c ON c.id = r.contact_id
                                WHERE r.tenant_id = {$TID} AND c.id IS NULL",
    'studio_families' => "SELECT f.id FROM studio_families f
                            LEFT JOIN contacts c ON c.id = f.primary_parent_id
                           WHERE f.tenant_id = {$TID} AND c.id IS NULL",
];

$total = 0;
echo "orphaned rows (contact no longer exists):\n";
foreach ($probes as $table => $sql) {
    $ids = array_column(Database::rows($sql), 'id');
    printf("  %-26s %d\n", $table, count($ids));
    $total += count($ids);
    if ($APPLY && $ids) {
        Database::query("DELETE FROM `{$table}` WHERE tenant_id = {$TID} AND id IN ("
            . implode(',', array_map('intval', $ids)) . ")");
    }
}
printf("  %-26s %d\n\n", 'TOTAL', $total);

// ── Detach named families (studio rows only; contacts survive) ────────
$detached = 0;
if ($detach) {
    echo "detaching families (contacts are NOT deleted):\n";
    foreach ($detach as $fid) {
        $fam = Database::row(
            "SELECT f.id, f.primary_parent_id, c.display_name
               FROM studio_families f LEFT JOIN contacts c ON c.id = f.primary_parent_id
              WHERE f.tenant_id = ? AND f.id = ?", [$TID, $fid]);
        if (!$fam) { printf("  fam#%-4s not found\n", $fid); continue; }

        $members = array_column(Database::rows(
            "SELECT contact_id FROM studio_family_members WHERE tenant_id = ? AND family_id = ?",
            [$TID, $fid]), 'contact_id');
        $people = array_values(array_unique(array_merge(
            $members, [(int) $fam['primary_parent_id']])));

        printf("  fam#%-4s %-18s members=%d  (contacts kept: %s)\n",
            $fid, "'" . (string) $fam['display_name'] . "'", count($members),
            implode(',', array_map(static fn ($p) => '#' . $p, $people)));

        if ($APPLY) {
            $in = $people ? '(' . implode(',', array_map('intval', $people)) . ')' : '(0)';
            Database::query("DELETE FROM studio_attendance     WHERE tenant_id={$TID} AND student_id IN {$in}");
            Database::query("DELETE FROM studio_costumes       WHERE tenant_id={$TID} AND student_id IN {$in}");
            Database::query("DELETE FROM studio_fees           WHERE tenant_id={$TID} AND (family_id={$fid} OR student_id IN {$in})");
            Database::query("DELETE FROM studio_enrollments    WHERE tenant_id={$TID} AND student_id IN {$in}");
            Database::query("DELETE FROM studio_family_members WHERE tenant_id={$TID} AND family_id={$fid}");
            Database::query("DELETE FROM studio_contact_roles  WHERE tenant_id={$TID} AND contact_id IN {$in}");
            Database::query("DELETE FROM studio_families       WHERE tenant_id={$TID} AND id={$fid}");
        }
        $detached++;
    }
    echo "\n";
}

if ($APPLY) {
    // Re-probe: the tenant must come out referentially clean.
    $left = 0;
    foreach ($probes as $sql) { $left += count(Database::rows($sql)); }
    echo $left === 0
        ? "✓ no orphaned studio rows remain\n"
        : "!! {$left} orphaned rows still present — investigate\n";
    printf("families detached: %d\n", $detached);
} else {
    echo "Dry run only. Re-run with --apply to delete.\n";
}
