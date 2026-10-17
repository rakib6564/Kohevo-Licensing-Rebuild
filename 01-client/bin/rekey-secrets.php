<?php
/**
 * Re-encrypt every stored secret after rotating APP_SECRET.
 *
 * Secrets are stored as enc:v1 envelopes whose key is derived from
 * APP_SECRET, so changing APP_SECRET makes all of them unreadable (SMTP,
 * OAuth tokens, Stripe, CAPTCHA, Twilio, AI and translation keys, backups).
 * Rotate like this:
 *
 *   1. Put the NEW secret in APP_SECRET (.env / hosting env).
 *   2. OLD_APP_SECRET='<previous secret>' php bin/rekey-secrets.php
 *      -> dry run: lists what would be re-encrypted, changes nothing.
 *   3. OLD_APP_SECRET='<previous secret>' php bin/rekey-secrets.php --apply
 *
 * The old secret is read from the environment, never from argv (argv shows
 * up in shell history and process lists). Every enc:v1 value in every text
 * column of every table with an `id` primary key is checked:
 *   - decrypts with the new secret  -> already current, left alone;
 *   - decrypts with the old secret  -> re-encrypted with the new one;
 *   - decrypts with neither         -> reported; with any such value the run
 *     stops and changes nothing (--apply included), so a wrong old secret
 *     cannot half-rotate the database.
 * --apply first writes a backup of the affected envelopes (ciphertext only,
 * never plaintext) to data/rekey-backup-<timestamp>.json with mode 0600,
 * then writes every change in one transaction. Values are never printed.
 * Rerunning is safe: values already under the new secret are skipped.
 *
 * Rollback: the tool is symmetric. Put the previous secret back in
 * APP_SECRET and run it with OLD_APP_SECRET set to the secret you rotated
 * to; every value is re-encrypted back. The backup file holds the exact
 * previous envelopes (readable with the previous secret) for a manual
 * restore if ever needed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/rekey-secrets.php [--apply]\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$apply = in_array('--apply', array_slice($argv, 1), true);
$old   = (string) getenv('OLD_APP_SECRET');
$new   = slate_has_app_secret() ? (string) APP_SECRET : '';
if ($new === '') {
    fwrite(STDERR, "APP_SECRET (the new secret) is not configured.\n");
    exit(1);
}
if ($old === '') {
    fwrite(STDERR, "Set OLD_APP_SECRET to the previous secret in the environment.\n");
    exit(1);
}
if (hash_equals($old, $new)) {
    fwrite(STDERR, "OLD_APP_SECRET is the same as APP_SECRET: nothing to rotate.\n");
    exit(1);
}

$pdo = Database::get();
// anti-drift-ignore: TENANT — platform-wide maintenance over every table, by design
$cols = Database::rows(
    "SELECT c.TABLE_NAME AS t, c.COLUMN_NAME AS c
       FROM information_schema.COLUMNS c
       JOIN information_schema.COLUMNS k
         ON k.TABLE_SCHEMA = c.TABLE_SCHEMA AND k.TABLE_NAME = c.TABLE_NAME AND k.COLUMN_NAME = 'id' AND k.COLUMN_KEY = 'PRI'
      WHERE c.TABLE_SCHEMA = DATABASE()
        AND c.DATA_TYPE IN ('char','varchar','text','tinytext','mediumtext','longtext','json')
   ORDER BY c.TABLE_NAME, c.COLUMN_NAME"
);

$plan = []; $current = 0; $unreadable = [];
foreach ($cols as $col) {
    $t = str_replace('`', '', $col['t']); $c = str_replace('`', '', $col['c']);
    // anti-drift-ignore: TENANT — platform-wide maintenance over every table, by design
    foreach (Database::rows("SELECT id, `{$c}` AS v FROM `{$t}` WHERE `{$c}` LIKE 'enc:v1:%'") as $row) {
        $v = (string) $row['v'];
        if (slate_reencrypt_envelope($v, $new, $new) !== null) { $current++; continue; }
        $re = slate_reencrypt_envelope($v, $old, $new);
        if ($re === null) { $unreadable[] = "{$t}.{$c} id={$row['id']}"; continue; }
        $plan[] = [$t, $c, $row['id'], $v, $re];
    }
}

printf("already current: %d, to re-encrypt: %d, unreadable with either secret: %d\n", $current, count($plan), count($unreadable));
foreach ($plan as [$t, $c, $id]) echo "  re-encrypt {$t}.{$c} id={$id}\n";
foreach ($unreadable as $u) echo "  UNREADABLE {$u}\n";
if ($unreadable) {
    fwrite(STDERR, "Stopped: some values decrypt with neither secret (wrong OLD_APP_SECRET?). Nothing was changed.\n");
    exit(2);
}
if (!$apply) {
    echo $plan ? "Dry run: nothing changed. Re-run with --apply to write.\n" : "Nothing to do.\n";
    exit(0);
}

$backup = dirname(__DIR__) . '/data/rekey-backup-' . date('Ymd-His') . '.json';
if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
$rows = array_map(static fn(array $p): array => ['table' => $p[0], 'column' => $p[1], 'id' => $p[2], 'envelope' => $p[3]], $plan);
if (file_put_contents($backup, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    fwrite(STDERR, "Could not write the backup file {$backup}. Nothing was changed.\n");
    exit(3);
}
chmod($backup, 0600);
echo "Backup of the previous envelopes (ciphertext only): {$backup}\n";

$pdo->beginTransaction();
try {
    foreach ($plan as [$t, $c, $id, $was, $re]) {
        // The WHERE on the old value makes a concurrent change win rather than be overwritten.
        // anti-drift-ignore: TENANT — platform-wide maintenance over every table, by design
        $n = Database::query("UPDATE `{$t}` SET `{$c}` = ? WHERE id = ? AND `{$c}` = ?", [$re, $id, $was])->rowCount();
        if ($n !== 1) throw new RuntimeException("{$t}.{$c} id={$id} changed during the run");
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Rolled back, nothing changed: ' . $e->getMessage() . "\n");
    exit(3);
}
if (class_exists('AuditLog')) AuditLog::record('system.secrets_rekeyed', '', ['count' => count($plan)]);
echo "Re-encrypted " . count($plan) . " value(s).\n";
exit(0);
