<?php
/**
 * Remote license check-in — run daily via cron (matches the server's
 * next_check_after default of 86400 seconds).
 *
 * Cleanly no-ops when remote licensing isn't configured for this install
 * (the default for every install until an operator deliberately sets it
 * up) — most deployments, and every deployment before this is configured,
 * simply skip this every run rather than erroring.
 *
 * Configuration is environment-only (.env / hosting panel env vars),
 * matching APP_SECRET/DB credentials elsewhere in this app — never
 * committed, never in the database:
 *
 *   LICENSE_SERVER_URL         e.g. https://license.yourcompany.com
 *   LICENSE_SERVER_PUBLIC_KEY  the server's Ed25519 public key (base64)
 *   LICENSE_PRODUCT            product slug, e.g. "kohevo"
 *   LICENSE_KEY                this install's own license key
 *   Installation identity is read from the Phase 1 installation_identity
 *   table; it is never operator-supplied or regenerated.
 *
 * Run:  php bin/license-check.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config.php';
require __DIR__ . '/../plugins/licensing/client/RemoteLicenseClient.php';

use Slate\Services\Installation\InstallationService;
use Slate\Services\Licensing\SlateLicenseCacheStore;

$serverUrl = env('LICENSE_SERVER_URL', '');
$publicKey = env('LICENSE_SERVER_PUBLIC_KEY', '');
$product   = env('LICENSE_PRODUCT', '');
$licenseKey = env('LICENSE_KEY', '');

if ($serverUrl === '' || $publicKey === '' || $product === '' || $licenseKey === '') {
    echo "Remote licensing is not configured for this install -- skipping.\n";
    exit(0);
}

$installId = InstallationService::currentInstallationId();
if ($installId === null) {
    echo "Remote licensing is not configured for this install -- missing installation identity.\n";
    exit(0);
}

$parts = parse_url(SLATE_URL);
$domain = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
$port = isset($parts['port']) ? (int) $parts['port'] : null;
if (($parts['scheme'] ?? '') === 'http' && $port === 80 || ($parts['scheme'] ?? '') === 'https' && $port === 443) $port = null;
if ($port !== null) $domain .= ':' . $port;

$store  = new SlateLicenseCacheStore((int) TENANT_ID);
$client = new RemoteLicenseClient([
    'server_url'  => $serverUrl,
    'public_key'  => $publicKey,
    'product'     => $product,
    'license_key' => $licenseKey,
    'install_id'  => $installId,
    'domain'      => $domain,
    'app_version' => SLATE_VERSION,
], $store);

$ok = $client->checkIn();
echo $ok
    ? "Check-in succeeded -- local cache updated.\n"
    : "Check-in failed -- last known status left untouched (this is safe: silence never restricts anything).\n";
