<?php
/**
 * Slate — anti-drift guard (CORE-1).
 *
 * Fails CI when code reaches around one of the five helpers that exist
 * precisely so a whole class of bug cannot recur. Each rule here corresponds
 * to a defect that was found in the wild, in this codebase, more than once:
 * a lookup written five times with one copy missing the tenant filter, a
 * signature keyed on a constant that is always defined and usually empty, a
 * timestamp written on one clock and compared against another.
 *
 * Rules (see --list for the current set):
 *   TENANT     a query touching a tenant-scoped table without naming tenant_id
 *   SECRET     a raw defined('APP_SECRET') outside the sanctioned accessor
 *   CLOCK      a PHP-clock timestamp written into a datetime column
 *   TOKEN      a bare manage_token lookup outside BookingAPI::findByManageToken
 *   CUSTOMERS  a direct write to the legacy `customers` table
 *   HMAC       a hash_hmac() not routed through slate_sign()
 *   ENTRY      a plugin public/storefront entry point with no activation gate (SEC-9)
 *
 * TENANT is the priority rule. The others protect correctness; that one
 * protects the boundary between tenants, and it has to be sound before
 * multi-tenant single-install lands.
 *
 * Ratchet, not a cliff. The tree has pre-existing violations that cannot all
 * be fixed in one change without unreviewable churn, so known ones live in a
 * baseline and only NEW ones fail the build. The baseline is a debt register:
 * it should shrink, and nothing should ever be added to it without a reason.
 *
 *   php bin/anti-drift.php                    check (exit 1 on a new violation)
 *   php bin/anti-drift.php --update-baseline  re-record (review the diff!)
 *   php bin/anti-drift.php --list             show rules and baseline counts
 *   php bin/anti-drift.php --all              report baselined violations too
 *
 * RE-ANCHORING AFTER A DETECTOR CHANGE — read this before editing the rules.
 *
 * The TENANT ratchet refuses to write a baseline with a higher count than the
 * one recorded. That is correct for normal use and WRONG the moment you change
 * how detection works, because the two purposes collide:
 *
 *   - as a ratchet, a rising count means someone added unscoped queries
 *   - after a detector change, a rising count can just mean the detector
 *     started seeing things it used to miss
 *
 * --update-baseline cannot tell those apart, so it caps the rewrite at the old
 * ceiling and silently keeps stale entries. This has already happened here: a
 * bug in sql_literals() was both hiding real violations and falsely flagging
 * correctly-scoped code, and the re-record after fixing it preserved two dead
 * entries and an anchor measured by the broken detector. The count looked
 * stable and was measuring nothing.
 *
 * So after ANY change to the rules, sql_literals(), scoped_tables() or the
 * skip logic, the only way to get an honest baseline is:
 *
 *   rm bin/anti-drift-baseline.txt && php bin/anti-drift.php --update-baseline
 *
 * With no file there is no ceiling, so the count is whatever the current
 * detector actually finds. Then READ THE DIFF: entries that vanished are
 * either genuinely fixed or newly invisible to a weakened detector, and those
 * two look identical in the numbers. Deleting the file is deliberately not
 * automated — re-anchoring should be a decision someone makes and explains in
 * a commit message, not a side effect of running a tool.
 *
 * Suppressing one line, when a violation is genuinely correct:
 *   $sql = "SELECT ..."; // anti-drift-ignore: TENANT — cross-tenant by design, see X
 * The reason is not optional; a bare ignore is itself reported.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/anti-drift.php\n");
    exit(1);
}

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$mode = in_array('--update-baseline', $args, true) ? 'update'
      : (in_array('--list', $args, true) ? 'list' : 'check');
$showAll      = in_array('--all', $args, true);
$baselineFile = $root . '/bin/anti-drift-baseline.txt';

// ── Which directories are ours to police ─────────────────────
// vendor/ is third-party. tests/ fixtures deliberately contain bad SQL.
// tools/ and bin/ are standalone CLI that never boot the helpers.
$SCAN_DIRS = ['admin', 'customer', 'includes', 'src', 'plugins', 'api'];
$SKIP_RE   = '#/(vendor|node_modules|\.git|tests|tools|bin)/#';

// ── The tenant-scoped table list, derived from the schema ────
// Generated rather than hardcoded so a new table with a tenant_id column is
// policed the day it lands, without anyone remembering to update this file.
function scoped_tables(string $root): array {
    $sql = '';
    foreach (['/db/schema.sql'] as $f) {
        if (is_file($root . $f)) $sql .= file_get_contents($root . $f) . "\n";
    }
    foreach (glob($root . '/plugins/*/install.sql') ?: [] as $f)          $sql .= file_get_contents($f) . "\n";
    foreach (glob($root . '/plugins/*/migrations/*.sql') ?: [] as $f)     $sql .= file_get_contents($f) . "\n";
    // Runtime CREATE TABLE inside PHP (ensureSchema patterns) counts too.
    foreach (array_merge(glob($root . '/db/migrations/*.php') ?: [],
                         glob($root . '/src/Services/*/*.php') ?: [],
                         glob($root . '/plugins/*/*.php') ?: []) as $f) {
        $c = file_get_contents($f);
        if (stripos($c, 'CREATE TABLE') !== false) $sql .= $c . "\n";
    }

    $tables = [];
    if (preg_match_all(
        '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*?)(?:\)\s*ENGINE|\)\s*;)/is',
        $sql, $m, PREG_SET_ORDER)) {
        foreach ($m as $hit) {
            if (stripos($hit[2], 'tenant_id') !== false) $tables[strtolower($hit[1])] = true;
        }
    }
    return $tables;
}

// ── Pull real string literals out of PHP ─────────────────────
// token_get_all() rather than a regex: it handles heredocs, escapes and
// multi-line strings correctly, and a SQL statement in this codebase is
// routinely all three.
function sql_literals(string $code): array {
    $out = [];
    $tokens = @token_get_all($code);
    if (!$tokens) return $out;

    $buf = '';
    $line = 0;
    $flush = function () use (&$buf, &$line, &$out) {
        if ($buf !== '') $out[] = ['sql' => $buf, 'line' => $line];
        $buf = ''; $line = 0;
    };

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $piece = trim($t[1], "\"'");
            // Do not begin a statement on a fragment that is not SQL; that is
            // how unrelated array values get concatenated into a fake query.
            if ($buf === '' && !preg_match('/\b(SELECT|INSERT|UPDATE|DELETE|FROM|JOIN)\b/i', $piece)) {
                continue;
            }
            if ($buf === '') $line = $t[2];
            $buf .= $piece;
            // A '.' between two literals continues one statement; anything
            // else ends it. A variable in the middle is kept as a gap, which
            // is why an interpolated table name is not silently swallowed.
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            if ($j < $n && $tokens[$j] === '.') {
                // Only keep the statement open when a STRING literal follows the
                // dot. If the concatenation continues into a function call or a
                // variable, the statement is finished as far as static reading
                // goes — flushing here is what preserves the trailing "AND",
                // which is the signal that the scope clause is supplied
                // elsewhere. Keeping the buffer open swallowed that "AND" into
                // the next unrelated literal and reported the fixed line.
                $k = $j + 1;
                while ($k < $n && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k++;
                $nextIsLiteral = $k < $n && is_array($tokens[$k])
                    && in_array($tokens[$k][0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true);
                if ($nextIsLiteral) { $i = $j; $buf .= ' '; continue; }
            }
            $flush();
        } elseif (is_array($t) && $t[0] === T_START_HEREDOC) {
            $line = $t[2]; $buf = '';
            for ($j = $i + 1; $j < $n; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_END_HEREDOC) { $i = $j; break; }
                if (is_array($tokens[$j])) $buf .= $tokens[$j][1];
            }
            $flush();
        }
    }
    $flush();
    return $out;
}

function looks_like_sql(string $s): bool {
    return (bool) preg_match('/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|FROM|JOIN)\b/i', $s);
}

/** Stable id for a violation, so the baseline survives line-number churn. */
function fingerprint(string $rule, string $file, string $evidence): string {
    $norm = strtolower(preg_replace('/\s+/', ' ', trim($evidence)));
    return $rule . ':' . $file . ':' . substr(sha1($norm), 0, 12);
}

// ── Scan ─────────────────────────────────────────────────────
$SCOPED   = scoped_tables($root);
$found    = [];   // fingerprint => [rule, file, line, message]
$badIgnore = [];

$files = [];
foreach ($SCAN_DIRS as $dir) {
    if (!is_dir($root . '/' . $dir)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->getExtension() !== 'php') continue;
        $p = $f->getPathname();
        if (preg_match($SKIP_RE, $p)) continue;
        $files[] = $p;
    }
}
sort($files);

foreach ($files as $path) {
    $rel  = ltrim(str_replace($root, '', $path), '/');
    $code = file_get_contents($path);
    $lines = explode("\n", $code);

    // Lines that are wholly comment. A docblock explaining why not to write
    // date('Y-m-d H:i:s') should not itself be reported for writing it.
    $commentLines = [];
    foreach (@token_get_all($code) ?: [] as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            $n = substr_count($t[1], "\n");
            for ($k = 0; $k <= $n; $k++) $commentLines[$t[2] + $k] = true;
        }
    }

    // Per-line suppressions, with a mandatory reason.
    $ignores = [];
    foreach ($lines as $i => $l) {
        if (preg_match('/anti-drift-ignore:\s*([A-Z]+)(.*)$/', $l, $m)) {
            $reason = trim($m[2], " \t-–—");
            if ($reason === '') {
                $badIgnore[] = [$rel, $i + 1, $m[1]];
            } else {
                $ignores[$i + 1][strtoupper($m[1])] = true;
            }
        }
    }
    $skip = function (int $line) use ($commentLines, $lines): bool {
        if (isset($commentLines[$line])) return true;
        $t = ltrim($lines[$line - 1] ?? '');
        return $t === '' || str_starts_with($t, '*') || str_starts_with($t, '//') || str_starts_with($t, '#');
    };
    // An ignore marker governs the next line of actual code. Written on the
    // first line of an explanatory comment block — which is where a reason
    // long enough to be worth reading naturally goes — it must still reach
    // past the rest of that block to the statement it is about.
    $governs = [];
    foreach ($ignores as $markerLine => $rules) {
        for ($l = $markerLine; $l <= $markerLine + 12 && $l <= count($lines); $l++) {
            $t = ltrim($lines[$l - 1] ?? '');
            $isComment = $t === '' || str_starts_with($t, '//') || str_starts_with($t, '*')
                      || str_starts_with($t, '/*') || str_starts_with($t, '#');
            if ($l > $markerLine && $isComment) continue;   // still inside the block
            foreach ($rules as $r => $_) $governs[$l][$r] = true;
            if ($l > $markerLine) break;                    // reached the code line
        }
    }
    $suppressed = function (int $line, string $rule) use ($governs): bool {
        // The marker's own line, the statement it governs, or a couple of
        // lines into a multi-line statement.
        for ($l = $line; $l >= $line - 2; $l--) {
            if (!empty($governs[$l][$rule])) return true;
        }
        return !empty($governs[$line][$rule]);
    };

    // ── SECRET ──
    if ($rel !== 'includes/helpers.php') {
        foreach ($lines as $i => $l) {
            if (str_contains($l, "defined('APP_SECRET')") || str_contains($l, 'defined("APP_SECRET")')) {
                if ($skip($i + 1) || $suppressed($i + 1, 'SECRET')) continue;
                $fp = fingerprint('SECRET', $rel, $l);
                $found[$fp] = ['SECRET', $rel, $i + 1,
                    "raw defined('APP_SECRET') — APP_SECRET is always defined, so this guard never fires. Use slate_has_app_secret() / slate_app_secret() / slate_sign()."];
            }
        }
    }

    // ── CLOCK ──
    foreach ($lines as $i => $l) {
        if (preg_match("/date\(\s*['\"]Y-m-d H:i:s['\"]\s*\)/", $l)) {
            if ($skip($i + 1) || $suppressed($i + 1, 'CLOCK')) continue;
            $fp = fingerprint('CLOCK', $rel, $l);
            $found[$fp] = ['CLOCK', $rel, $i + 1,
                'PHP-clock timestamp in MySQL DATETIME format. PHP and MySQL do not share a timezone here; use slate_db_now() (string) or slate_db_time() (int).'];
        }
        // time() arithmetic compared against a column value
        if (preg_match('/\btime\(\)/', $l) && preg_match('/strtotime\s*\(\s*\$/', $l)) {
            if ($skip($i + 1) || $suppressed($i + 1, 'CLOCK')) continue;
            $fp = fingerprint('CLOCK', $rel, $l);
            $found[$fp] = ['CLOCK', $rel, $i + 1,
                'time() compared against a stored timestamp. Use slate_db_time() so both sides are on the database clock.'];
        }
    }

    // ── TOKEN ──
    if (!str_ends_with($rel, 'BookingAPI.php')) {
        foreach ($lines as $i => $l) {
            if (preg_match('/manage_token\s*=\s*\?/', $l)) {
                if ($skip($i + 1) || $suppressed($i + 1, 'TOKEN')) continue;
                $fp = fingerprint('TOKEN', $rel, $l);
                $found[$fp] = ['TOKEN', $rel, $i + 1,
                    'bare manage_token lookup. Use BookingAPI::findByManageToken(), which scopes by tenant — a hand-written copy of this query already shipped without the filter once.'];
            }
        }
    }

    // ── CUSTOMERS ──
    foreach ($lines as $i => $l) {
        if (preg_match("/(INSERT\s+INTO\s+`?customers`?|UPDATE\s+`?customers`?\s+SET|DELETE\s+FROM\s+`?customers`?|Database::(insert|update|delete)\(\s*['\"]customers['\"])/i", $l)) {
            if ($skip($i + 1) || $suppressed($i + 1, 'CUSTOMERS')) continue;
            $fp = fingerprint('CUSTOMERS', $rel, $l);
            $found[$fp] = ['CUSTOMERS', $rel, $i + 1,
                'direct write to the legacy `customers` table. Identity writes belong behind the identity spine (Slate\\Services\\Identity\\ContactRepository) so the canonical record and the legacy one cannot diverge.'];
        }
    }

    // ── ENTRY (SEC-9) ──
    // .htaccess deliberately allows plugins/*/public/ and plugins/*/storefront/
    // to be fetched directly by URL, which means the file runs regardless of
    // whether PublicRouter's dispatch — the only place that checks a plugin is
    // active before handing it a request — ever gets involved. A top-level
    // file in either directory therefore has to gate on activation itself.
    // Views and shared includes one level deeper are not entry points and are
    // not scanned here.
    if (preg_match('#^plugins/[^/]+/(public|storefront)/[^/]+\.php$#', $rel)) {
        $hasGuard = str_contains($code, 'slate_public_entry(') || str_contains($code, 'PluginLoader::isActive(');
        $entryIgnored = false;
        foreach ($ignores as $rules) {
            if (!empty($rules['ENTRY'])) { $entryIgnored = true; break; }
        }
        if (!$hasGuard && !$entryIgnored) {
            $fp = fingerprint('ENTRY', $rel, $rel);
            $found[$fp] = ['ENTRY', $rel, 1,
                "public entry point with no plugin-activation gate. Call slate_public_entry('<slug>') (includes/helpers.php) as the first statement after requiring config.php — a direct URL fetch of this file bypasses PublicRouter's dispatch entirely, so a check that only runs there is not enough."];
        }
    }

    // ── HMAC ──
    // slate_sign() returns NULL when no secret is configured, and a caller that
    // ignores that gets no signature. hash_hmac() has no such guard: hand it a
    // null or empty key and it cheerfully signs with the empty string, which is
    // the original APP_SECRET bug reincarnated one layer down. The point of this
    // rule is that the mistake should be unavailable, not merely absent today.
    if ($rel !== 'includes/helpers.php') {
        foreach ($lines as $i => $l) {
            if (!str_contains($l, 'hash_hmac')) continue;
            if ($skip($i + 1) || $suppressed($i + 1, 'HMAC')) continue;
            $fp = fingerprint('HMAC', $rel, $l);
            $found[$fp] = ['HMAC', $rel, $i + 1,
                'raw hash_hmac(). Use slate_sign() / slate_sign_equals(), which return null and false when no secret is configured instead of signing with an empty key. If this genuinely keys on a different secret, annotate it: // anti-drift-ignore: HMAC — <which key, and why it cannot be empty>'];
        }
    }

    // ── HMAC ──
    // slate_sign() returns NULL when no secret is configured, and a caller that
    // ignores that gets no signature. hash_hmac() has no such protection: pass
    // it a null key and PHP coerces to '', producing a real-looking signature
    // under a key anyone can reproduce. That is the original APP_SECRET bug
    // with the guard removed, so the call itself is what has to be unavailable.
    //
    // Legitimate exceptions exist — TOTP and Stripe both specify an algorithm
    // over a key that is not APP_SECRET — and they annotate with a reason.
    if ($rel !== 'includes/helpers.php') {
        foreach ($lines as $i => $l) {
            if (str_contains($l, 'hash_hmac')) {
                if ($skip($i + 1) || $suppressed($i + 1, 'HMAC')) continue;
                $fp = fingerprint('HMAC', $rel, $l);
                $found[$fp] = ['HMAC', $rel, $i + 1,
                    'raw hash_hmac(). Use slate_sign() / slate_sign_equals(), which return null and false when no secret is configured; hash_hmac() coerces a null key to \'\' and signs anyway. If this genuinely keys on something other than APP_SECRET, annotate it: // anti-drift-ignore: HMAC — <which key and why>'];
            }
        }
    }

    // ── TENANT (priority) ──
    foreach (sql_literals($code) as $lit) {
        $sql = $lit['sql'];
        if (!looks_like_sql($sql)) continue;
        if (stripos($sql, 'tenant_id') !== false) continue;
        if (stripos($sql, 'slate_tenant_clause') !== false) continue;
        // CREATE TABLE / DDL is not a scoping question.
        if (preg_match('/\b(CREATE\s+TABLE|ALTER\s+TABLE|DROP\s+TABLE|SHOW\s+|DESCRIBE\s+|INFORMATION_SCHEMA)/i', $sql)) continue;

        // The statement is finished by a variable ("... WHERE " . implode(...)),
        // so the scoping clause may well be in that variable. Cannot decide
        // statically; do not guess. These are the ones a reviewer must read.
        if (preg_match('/\b(WHERE|AND|OR|,)\s*$/i', rtrim($sql))) continue;

        foreach (array_keys($SCOPED) as $tbl) {
            // The table must appear in TABLE POSITION — after FROM/JOIN/INTO/
            // UPDATE — not merely somewhere in the text. Without this a config
            // array that happens to contain a table-like word is reported.
            if (!preg_match('/\b(FROM|JOIN|INTO|UPDATE)\s+`?' . preg_quote($tbl, '/') . '`?\b/i', $sql)) continue;
            if ($suppressed($lit['line'], 'TENANT')) break;
            $fp = fingerprint('TENANT', $rel, $sql);
            $found[$fp] = ['TENANT', $rel, $lit['line'],
                "query touches tenant-scoped table `$tbl` without naming tenant_id. Add " . 'slate_tenant_clause()' . " and bind slate_tenant_id()."];
            break;
        }
    }
}

// ── Baseline ─────────────────────────────────────────────────
$baseline = [];
$ceiling  = null;   // recorded high-water mark for TENANT; may only fall
if (is_file($baselineFile)) {
    foreach (file($baselineFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        $l = trim($l);
        if ($l === '') continue;
        if ($l[0] === '#') {
            if (preg_match('/^#\s*ratchet\s+TENANT\s*=\s*(\d+)/i', $l, $m)) $ceiling = (int) $m[1];
            continue;
        }
        $baseline[$l] = true;
    }
}
$tenantNow = 0;
foreach ($found as $v) if ($v[0] === 'TENANT') $tenantNow++;

if ($mode === 'update') {
    // The ratchet is the whole point: re-recording must never be a way to
    // legalise more unscoped queries. Fewer is always allowed.
    if ($ceiling !== null && $tenantNow > $ceiling) {
        fwrite(STDERR,
            "refusing to write the baseline: TENANT would rise from {$ceiling} to {$tenantNow}.\n" .
            "The baseline is the multi-tenant migration backlog and may only shrink.\n" .
            "Scope the new queries, or annotate them with a reason:\n" .
            "    // anti-drift-ignore: TENANT — why this one is safe\n");
        exit(1);
    }
    $lines = [
        '# Slate anti-drift baseline (CORE-1) — pre-existing violations, one per line.',
        '# Regenerate: php bin/anti-drift.php --update-baseline',
        '#',
        '# This is a DEBT REGISTER, not a config file. It should only ever shrink.',
        '# A new entry means someone reached around a helper and recorded it instead',
        '# of fixing it — that needs a reason in review, not a rubber stamp.',
        '#',
        '# Generated ' . gmdate('Y-m-d') . ' — ' . count($found) . ' entries.',
        '#',
        '# The line below is a RATCHET, not a comment. TENANT is the multi-tenant',
        '# migration backlog: every entry is a query that must be scoped before',
        '# one install can serve more than one tenant. The count may fall and',
        '# never rise — bin/anti-drift.php refuses to re-record a higher one.',
        '# ratchet TENANT=' . $tenantNow,
        '',
    ];
    // Sort the ENTRIES only. Sorting the whole array shuffles the header
    // comments in among them, which makes a file whose entire job is to be
    // read in review unreadable.
    $entries = array_keys($found);
    sort($entries);
    file_put_contents($baselineFile, implode("\n", array_merge($lines, $entries)) . "\n");
    echo "Baseline written: " . count($found) . " entries → bin/anti-drift-baseline.txt\n";
    exit(0);
}

$byRule = [];
foreach ($found as $fp => $v) $byRule[$v[0]][] = $fp;

if ($mode === 'list') {
    echo "Anti-drift rules (CORE-1)\n\n";
    foreach (['TENANT' => 'tenant-scoped table queried without tenant_id  [priority]',
              'SECRET' => "raw defined('APP_SECRET')",
              'CLOCK'  => 'PHP clock written to a DB datetime',
              'TOKEN'  => 'bare manage_token lookup',
              'CUSTOMERS' => 'direct write to legacy customers',
              'HMAC'      => 'hash_hmac() not routed through slate_sign()',
              'ENTRY'     => 'public entry point with no plugin-activation gate'] as $r => $d) {
        $tot = count($byRule[$r] ?? []);
        $new = count(array_filter($byRule[$r] ?? [], fn($fp) => !isset($baseline[$fp])));
        printf("  %-10s %-52s total %3d   new %d\n", $r, $d, $tot, $new);
    }
    echo "\nBaseline: " . count($baseline) . " recorded.\n";
    exit(0);
}

// ── Report ───────────────────────────────────────────────────
$new = [];
foreach ($found as $fp => $v) if (!isset($baseline[$fp])) $new[$fp] = $v;

$ci = getenv('GITHUB_ACTIONS') === 'true';
foreach ($badIgnore as [$f, $ln, $rule]) {
    $msg = "anti-drift-ignore: $rule with no reason. State why, on the same line.";
    echo $ci ? "::error file=$f,line=$ln::$msg\n" : "  $f:$ln  $msg\n";
}

if ($showAll) {
    foreach ($found as $fp => [$rule, $f, $ln, $msg]) {
        if (isset($new[$fp])) continue;
        echo "  (baselined) [$rule] $f:$ln  $msg\n";
    }
}

// ── TENANT ratchet ───────────────────────────────────────────
// Reported on every run, pass or fail: a number nobody sees is a number
// nobody moves.
$ratchetFailed = false;
if ($ceiling !== null) {
    if ($tenantNow > $ceiling) {
        $over = $tenantNow - $ceiling;
        $msg  = "TENANT backlog rose from {$ceiling} to {$tenantNow} (+{$over}). "
              . 'This baseline is the multi-tenant migration backlog and may only shrink.';
        echo $ci ? "::error title=anti-drift TENANT ratchet::$msg\n" : "\n  $msg\n";
        $ratchetFailed = true;
    } elseif ($tenantNow < $ceiling) {
        $down = $ceiling - $tenantNow;
        echo "anti-drift: TENANT backlog {$tenantNow}/{$ceiling} (down {$down}). "
           . "Re-record with --update-baseline to lock it in.\n";
    } else {
        echo "anti-drift: TENANT backlog {$tenantNow}/{$ceiling} (unchanged).\n";
    }
}

if (!$new && !$badIgnore && !$ratchetFailed) {
    $n = count($found);
    echo "anti-drift: clean. No new violations ($n baselined).\n";
    exit(0);
}
if (!$new && !$badIgnore) exit(1);   // ratchet breach with no new fingerprints

// Priority rule first, then the rest.
uasort($new, fn($a, $b) => [$a[0] !== 'TENANT', $a[1], $a[2]] <=> [$b[0] !== 'TENANT', $b[1], $b[2]]);

echo "\nanti-drift: " . count($new) . " NEW violation(s).\n\n";
foreach ($new as [$rule, $f, $ln, $msg]) {
    if ($ci) {
        echo "::error file=$f,line=$ln,title=anti-drift $rule::$msg\n";
    } else {
        printf("  [%s] %s:%d\n      %s\n\n", $rule, $f, $ln, $msg);
    }
}
echo "\nIf a violation is genuinely correct, annotate the line:\n";
echo "    // anti-drift-ignore: RULE — why this one is safe\n";
echo "Do not run --update-baseline to clear a new finding; that is what the annotation is for.\n";

exit(1);
