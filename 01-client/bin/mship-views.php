<?php
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/mship-views.php\n");
    exit(1);
}

define('SLATE_CLI', true);
require dirname(__DIR__) . '/config.php';

$row = Database::row("SELECT customer_id FROM membership_subscriptions ORDER BY id DESC LIMIT 1");
$cid = $row ? (int)$row['customer_id'] : 0;
$tid = current_tenant_id();
$profile = MembershipAPI::ensureProfile($cid);
$selfUrl = SLATE_URL . '/member';

foreach (['plans','schedule','profile'] as $view) {
    ob_start();
    try {
        require dirname(__DIR__) . "/plugins/membership/public/views/$view.php";
        $html = ob_get_clean();
        echo str_pad($view, 10), " OK — ", strlen($html), " bytes\n";
    } catch (\Throwable $e) {
        ob_end_clean();
        echo str_pad($view, 10), " ERROR: ", $e->getMessage(), " @ ", basename($e->getFile()), ":", $e->getLine(), "\n";
    }
}
