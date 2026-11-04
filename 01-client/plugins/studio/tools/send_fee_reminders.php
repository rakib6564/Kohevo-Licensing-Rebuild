<?php
/**
 * Send payment reminders for outstanding studio fees.
 *
 * Built to run daily from cron. Everything about it assumes it will be run
 * more than once on the same day, by accident or by a retry:
 *
 *  - ReminderPolicy only ever returns a step that has not already been sent,
 *    and the step is recorded on the fee, so a second run the same day sends
 *    nothing.
 *  - A fee is stamped ONLY after the mailer accepts it, so a send that failed
 *    is retried next run rather than silently marked done.
 *  - Reminders are grouped per FAMILY: a parent with eleven costume
 *    instalments gets one email listing all of them, not eleven emails.
 *
 * DRY RUN BY DEFAULT — prints who would be emailed and sends nothing.
 *
 *   php plugins/studio/tools/send_fee_reminders.php
 *   php plugins/studio/tools/send_fee_reminders.php --send
 *   php plugins/studio/tools/send_fee_reminders.php --today=2026-11-08   # preview a future day
 *
 * Cron (daily at 09:00):
 *   0 9 * * * php /path/to/slate/plugins/studio/tools/send_fee_reminders.php --send
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
require $ROOT . '/config.php';
require_once $ROOT . '/plugins/studio/StudioAPI.php';
require_once $ROOT . '/plugins/studio/StudioMail.php';

$SEND  = in_array('--send', $argv, true);
$today = date('Y-m-d');
foreach ($argv as $a) {
    if (str_starts_with($a, '--today=')) { $today = substr($a, 8); }
}

$money  = static fn (int $c): string => '$' . number_format($c / 100, 2);
$policy = StudioAPI::reminderPolicy();

echo $SEND ? "SENDING — " : "DRY RUN (no email sent) — ";
echo "today {$today}, schedule [" . implode(', ', $policy->steps()) . "]\n";

if (!StudioMail::enabled()) {
    echo "\nParent notifications are switched OFF for this studio "
       . "(Settings → Parent notifications). Nothing will be sent.\n";
    if ($SEND) { exit(0); }
}

$groups = StudioAPI::feeRemindersDue($today);

if (!$groups) {
    echo "\nNothing due a reminder today.\n";
    exit(0);
}

printf("\n%-24s %-30s %-7s %-9s %s\n", 'FAMILY', 'EMAIL', 'STEP', 'TOTAL', 'FEES');
echo str_repeat('-', 108), "\n";

$sent = 0; $failed = 0; $lines = 0; $money_total = 0;

foreach ($groups as $g) {
    $total = 0;
    foreach ($g['fees'] as $f) { $total += (int) $f['amount_cents']; }
    $money_total += $total;
    $lines += count($g['fees']);

    $stepLabel = ($g['step'] > 0 ? '+' : '') . $g['step'] . 'd';
    printf("%-24s %-30s %-7s %-9s %s\n",
        mb_substr((string) $g['parent']['name'], 0, 24),
        mb_substr((string) $g['parent']['email'], 0, 30),
        $stepLabel, $money($total),
        implode(', ', array_map(
            static fn ($f) => mb_substr((string) $f['label'], 0, 26), $g['fees'])));

    if (!$SEND) { continue; }

    if (StudioMail::sendFeeReminder($g)) {
        // Stamp each fee with the step that was actually chosen for IT, not
        // the family's headline step — otherwise a fee at -7 inside a group
        // whose headline is +30 would have +30 recorded and skip its own
        // earlier nudges.
        foreach ($g['fees'] as $f) {
            StudioAPI::recordFeeReminder((int) $f['id'], (int) $f['step']);
        }
        $sent++;
    } else {
        $failed++;
        echo "    !! send failed — left unstamped, will retry next run\n";
    }
}

echo str_repeat('-', 108), "\n";
printf("%d famil%s, %d fee line(s), %s outstanding\n",
    count($groups), count($groups) === 1 ? 'y' : 'ies', $lines, $money($money_total));

if ($SEND) {
    printf("sent %d, failed %d\n", $sent, $failed);
} else {
    echo "\nDry run only. Re-run with --send to email these families.\n";
}
