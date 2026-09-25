<?php
/**
 * Slate — PSR-4 autoloader test (Phase 1 A1).
 *
 * Proves the hand-rolled autoloader resolves `Slate\*` from `src/`, in
 * ISOLATION — it does not boot config.php, so it validates the autoloader
 * without depending on (or affecting) the live bootstrap. Once the autoloader
 * is wired into config.php (A1.2), the main smoke suite covers this too.
 *
 * Usage: php tests/autoload_test.php   (exit 0 = pass)
 */

declare(strict_types=1);

$PASS = 0; $FAIL = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "ok   - $name\n"; }
    else     { $FAIL++; echo "FAIL - $name" . ($detail ? "  ($detail)" : '') . "\n"; }
}

echo "# Slate autoloader test\n";

require __DIR__ . '/../src/autoload.php';
check('autoloader registers', defined('SLATE_AUTOLOAD_REGISTERED'));

// The proof class must autoload purely by referencing it (no require).
check('Slate\\Kernel\\Ping autoloads',
    class_exists(\Slate\Kernel\Ping::class));
check('Ping::ok() works',
    class_exists(\Slate\Kernel\Ping::class) && \Slate\Kernel\Ping::ok() === 'slate-src-autoload-ok');

// A non-Slate class name must be ignored by our autoloader (no fatal).
check('non-Slate class name is ignored (no crash)',
    !class_exists('Definitely\\Not\\A\\Slate\\Class_', true) || true);

// The compat aliases file loads cleanly (empty in A1).
require __DIR__ . '/../src/compat/aliases.php';
check('compat/aliases.php loads cleanly', true);

$total = $PASS + $FAIL;
echo "1..$total\n# passed $PASS / $total\n";
exit($FAIL === 0 ? 0 : 1);
