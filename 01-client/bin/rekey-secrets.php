<?php
/**
 * Re-encrypt every stored secret after rotating APP_SECRET.
 *
 * Secrets are stored as enc:v1 envelopes whose key is derived from
 * APP_SECRET, so changing APP_SECRET makes all of them unreadable (SMTP,
 * OAuth tokens, Stripe, CAPTCHA, Twilio, AI and translation keys, backups).
 * Rotate like this:
 *
 *   1. Back up the database.
 *   2. Put the NEW secret in APP_SECRET (.env / hosting env).
 *   3. OLD_APP_SECRET='<previous secret>' php bin/rekey-secrets.php
 *      -> dry run: lists what would be re-encrypted, changes nothing.
 *   4. OLD_APP_SECRET='<previous secret>' php bin/rekey-secrets.php --apply
 *
 * The old secret is read from the environment, never from argv (argv shows
 * up in shell history and process lists). Every enc:v1 value in every text
 * column of every table with an `id` primary key is checked:
 *   - decrypts with the new secret  -> already current, left alone;
 *   - decrypts with the old secret  -> re-encrypted with the new one;
 *   - decrypts with neither         -> reported; with any such value the run
 *     stops and changes nothing (--apply included), so a wrong old secret
 *     cannot half-rotate the database.
 * --apply refuses to run if an affected table is not transactional (only
 * InnoDB is accepted), writes a backup of the affected envelopes
 * (ciphertext only, never plaintext) to data/rekey-backup-<timestamp>.json
 * with mode 0600, then writes every change in one transaction, each guarded
 * on the value it read; any failure rolls the whole run back. Values are
 * never printed. Rerunning is safe: values already under the new secret are
 * skipped.
 *
 * Rollback, either way:
 *   - Reverse run: put the previous secret back in APP_SECRET and run the
 *     tool with OLD_APP_SECRET set to the secret you rotated to.
 *   - From the backup: put the previous secret back in APP_SECRET and run
 *     php bin/rekey-secrets.php --restore=data/rekey-backup-<ts>.json
 *     It refuses unless every backed-up envelope opens with the APP_SECRET
 *     now configured, and writes them back in one transaction.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/rekey-secrets.php [--apply | --restore=<backup.json>]\n");
    exit(1);
}

require __DIR__ . '/../config.php';

$args    = array_slice($argv, 1);
$apply   = in_array('--apply', $args, true);
$restore = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--restore=')) $restore = substr($a, 10);
}
$new = slate_has_app_secret() ? (string) APP_SECRET : '';
if ($new === '') {
    fwrite(STDERR, "APP_SECRET (the new secret) is not configured.\n");
    exit(1);
}
$pdo = Database::get();

/** Only InnoDB tables can be changed all-or-nothing here. */
$nonTransactional = static function (array $tables): array {
    if (!$tables) return [];
    $in = implode(',', array_fill(0, count($tables), '?'));
    // anti-drift-ignore: TENANT — schema metadata, not tenant data
    $rows = Database::rows("SELECT TABLE_NAME AS t, ENGINE AS e FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($in)", array_values($tables));
    return array_values(array_map(static fn(array $r): string => "{$r['t']} ({$r['e']})", array_filter($rows, static fn(array $r): bool => strcasecmp((string) $r['e'], 'InnoDB') !== 0)));
};

/** Write [table, column, id, expected-current-value, new-value] rows in one transaction, or nothing. */
$writeAll = static function (array $writes) use ($pdo): void {
    $pdo->beginTransaction();
    try {
        foreach ($writes as [$t, $c, $id, $was, $to]) {
            // anti-drift-ignore: TENANT — platform-wide maintenance over every table, by design
            $sql = $was === null
                ? "UPDATE `{$t}` SET `{$c}` = ? WHERE id = ?"
                : "UPDATE `{$t}` SET `{$c}` = ? WHERE id = ? AND `{$c}` = ?";
            $params = $was === null ? [$to, $id] : [$to, $id, $was];
            $n = Database::query($sql, $params)->rowCount();
            if ($n !== 1 && !($was === null && $n === 0 && Database::value("SELECT `{$c}` FROM `{$t}` WHERE id = ?", [$id]) === $to)) {
                throw new RuntimeException("{$t}.{$c} id={$id} changed during the run or no longer exists");
            }
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
};

// ── Restore from a backup file ───────────────────────────────────────────
if ($restore !== null) {
    $raw  = @file_get_contents($restore);
    $rows = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($rows)) {
        fwrite(STDERR, "Cannot read the backup file {$restore}.\n");
        exit(1);
    }
    $writes = []; $bad = []; $tables = [];
    foreach ($rows as $r) {
        $t = str_replace('`', '', (string) ($r['table'] ?? ''));
        $c = str_replace('`', '', (string) ($r['column'] ?? ''));
        $id = (int) ($r['id'] ?? 0);
        $env = (string) ($r['envelope'] ?? '');
        if ($t === '' || $c === '' || $id <= 0 || slate_reencrypt_envelope($env, $new, $new) === null) {
            $bad[] = "{$t}.{$c} id={$id}";
            continue;
        }
        $tables[$t] = $t;
        $writes[] = [$t, $c, $id, null, $env];
    }
    printf("backup entries: %d, restorable with the current APP_SECRET: %d, not: %d\n", count($rows), count($writes), count($bad));
    foreach ($bad as $b) echo "  NOT RESTORABLE {$b}\n";
    if ($bad) {
        fwrite(STDERR, "Stopped: put back the APP_SECRET the backup was made under (or fix the file). Nothing was changed.\n");
        exit(2);
    }
    if ($nt = $nonTransactional($tables)) {
        fwrite(STDERR, 'Stopped: not transactional: ' . implode(', ', $nt) . ". Nothing was changed.\n");
        exit(4);
    }
    try {
        $writeAll($writes);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'Rolled back, nothing changed: ' . $e->getMessage() . "\n");
        exit(3);
    }
    if (class_exists('AuditLog')) AuditLog::record('system.secrets_restored', '', ['count' => count($writes)]);
    echo 'Restored ' . count($writes) . " value(s) from the backup.\n";
    exit(0);
}

// ── Rotate ───────────────────────────────────────────────────────────────
$old = (string) getenv('OLD_APP_SECRET');
if ($old === '') {
    fwrite(STDERR, "Set OLD_APP_SECRET to the previous secret in the environment.\n");
    exit(1);
}
if (hash_equals($old, $new)) {
    fwrite(STDERR, "OLD_APP_SECRET is the same as APP_SECRET: nothing to rotate.\n");
    exit(1);
}

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

$plan = []; $current = 0; $unreadable = []; $tables = [];
foreach ($cols as $col) {
    $t = str_replace('`', '', $col['t']); $c = str_replace('`', '', $col['c']);
    // anti-drift-ignore: TENANT — platform-wide maintenance over every table, by design
    foreach (Database::rows("SELECT id, `{$c}` AS v FROM `{$t}` WHERE `{$c}` LIKE 'enc:v1:%'") as $row) {
        $v = (string) $row['v'];
        if (slate_reencrypt_envelope($v, $new, $new) !== null) { $current++; continue; }
        $re = slate_reencrypt_envelope($v, $old, $new);
        if ($re === null) { $unreadable[] = "{$t}.{$c} id={$row['id']}"; continue; }
        $plan[] = [$t, $c, $row['id'], $v, $re];
        $tables[$t] = $t;
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
if (!$plan) {
    echo "Nothing to do.\n";
    exit(0);
}
if ($nt = $nonTransactional($tables)) {
    fwrite(STDERR, 'Stopped: these tables are not transactional, so a failure could leave them half-rotated: ' . implode(', ', $nt) . ". Nothing was changed.\n");
    exit(4);
}

$backup = dirname(__DIR__) . '/data/rekey-backup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.json';
if (!is_dir(dirname($backup))) mkdir(dirname($backup), 0700, true);
$rows = array_map(static fn(array $p): array => ['table' => $p[0], 'column' => $p[1], 'id' => $p[2], 'envelope' => $p[3]], $plan);
$old_umask = umask(0077);
$wrote = file_put_contents($backup, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
umask($old_umask);
if ($wrote === false) {
    fwrite(STDERR, "Could not write the backup file {$backup}. Nothing was changed.\n");
    exit(3);
}
chmod($backup, 0600);
echo "Backup of the previous envelopes (ciphertext only): {$backup}\n";

try {
    $writeAll($plan);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Rolled back, nothing changed: ' . $e->getMessage() . "\n");
    exit(3);
}
if (class_exists('AuditLog')) AuditLog::record('system.secrets_rekeyed', '', ['count' => count($plan)]);
echo "Re-encrypted " . count($plan) . " value(s).\n";
exit(0);
