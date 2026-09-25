<?php
/**
 * Guards against a one-character bug that silently disables a form.
 *
 * csrf_field() and submit_token_field() RETURN their markup; they do not echo.
 * Written as `<?php csrf_field(); ?>` the return value is discarded, no hidden
 * input reaches the page, and every POST from that form fails csrf_verify().
 *
 * Nothing errors. The page renders, the button works, and the user gets
 * "Security check failed." forever — which is exactly how the Site theme
 * switcher in small-business-kit shipped broken and stayed broken.
 *
 * This is a static check: no database, no rendering, just the source.
 */

declare(strict_types=1);

unit('csrf_field() and submit_token_field() are always echoed, never called as statements', function (): void {
    $root = dirname(__DIR__, 2);

    $dirs = array_filter([
        $root . '/admin',
        $root . '/customer',
        $root . '/plugins',
        $root . '/includes',
    ], 'is_dir');

    $offenders = [];
    $scanned   = 0;

    foreach ($dirs as $dir) {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') continue;
            $src = (string) file_get_contents($file->getPathname());
            $scanned++;

            /* An open tag followed by a bare call and a close tag: the return
               value is thrown away. Spelled out rather than shown literally,
               because a close tag inside a // comment ends PHP mode. */
            if (preg_match(
                '/<\?php\s+(csrf_field|submit_token_field|csrf_token)\s*\(\s*\)\s*;?\s*\?>/',
                $src,
                $m
            )) {
                $offenders[] = str_replace($root . '/', '', $file->getPathname())
                             . ' — ' . trim($m[0]);
            }
        }
    }

    assert_true($scanned > 100, "expected to scan a real number of files, scanned {$scanned}");
    assert_eq(
        [],
        $offenders,
        "these call a returning helper as a statement, so no token reaches the form:\n  "
        . implode("\n  ", $offenders)
    );
});
