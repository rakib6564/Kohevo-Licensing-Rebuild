<?php
/**
 * React Site Bridge — create a site record from the CLI.
 *
 * The admin "New site" form is a login-gated web page; this is its CLI
 * counterpart, for bringing up a site on a box where no admin session is
 * available. It calls ReactSiteBridgeAPI::createSite() directly, so it runs
 * the exact same validation and normalization the UI does. The site is
 * created in 'draft' status with no hosted release — upload the release ZIP
 * afterward from Admin → React Site Bridge → (site) → Hosted release, since
 * that step requires a real HTTP file upload and has no CLI equivalent.
 *
 * IDEMPOTENT: matched on the normalized site key, so re-running after the
 * key already exists reports it instead of failing or duplicating it.
 *
 * Run:  php bin/create-react-site.php "<Site Name>" [site-key]
 * e.g.: php bin/create-react-site.php "Peoria Hardwood Floors"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/create-react-site.php \"<Site Name>\" [site-key]\n");
    exit(1);
}

require __DIR__ . '/../config.php';
require_once __DIR__ . '/../plugins/react-site-bridge/ReactSiteBridgeAPI.php';

$args = array_slice($argv, 1);
if (!$args) {
    fwrite(STDERR, "Usage: php bin/create-react-site.php \"<Site Name>\" [site-key]\n");
    exit(1);
}

$name = (string)$args[0];
$siteKeyArg = isset($args[1]) ? (string)$args[1] : $name;
$siteKey = trim((string)preg_replace('/[^a-z0-9-]+/', '-', strtolower($siteKeyArg)), '-');

ReactSiteBridgeAPI::ensureSchema();

$existing = Database::row(
    'SELECT id, name, status FROM reactsitebridge_sites WHERE tenant_id = ? AND site_key = ?',
    [current_tenant_id(), $siteKey]
);

if ($existing) {
    echo "  = {$existing['name']} — already exists (id #{$existing['id']}, status {$existing['status']})\n";
    exit(0);
}

try {
    $siteId = ReactSiteBridgeAPI::createSite($name, $siteKey);
    echo "  + $name — created (id #$siteId, key \"$siteKey\")\n";
    echo "\nNext: log in to Admin -> React Site Bridge -> $name -> Hosted release, and upload the release ZIP.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "  x $name — " . $e->getMessage() . "\n");
    exit(1);
}
