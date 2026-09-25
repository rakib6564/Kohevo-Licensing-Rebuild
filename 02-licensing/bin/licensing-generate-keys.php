<?php
/**
 * Licensing plugin — generate and store the server's Ed25519 signing
 * keypair (Phase 2 of the remote license server build).
 *
 * Run once, on the dedicated license-server install, before issuing any
 * licenses: php bin/licensing-generate-keys.php
 *
 * The secret key is encrypted at rest (slate_encrypt_secret(), keyed off
 * APP_SECRET) and never printed. Only the public key is printed — copy it
 * into every client install's embedded LicenseSignatureVerifier config.
 *
 * Refuses to run again once a keypair already exists: rotating it would
 * invalidate every signature already trusted by installs in the field.
 * Use --force only if you understand that and are prepared to redeploy the
 * new public key everywhere first.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from the CLI: php bin/licensing-generate-keys.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';
// require_once, not require: when the licensing plugin is active, config.php
// (via PluginLoader::boot() -> Licensing::boot()) has already loaded this
// same file -- a plain require() here fatals on a class redeclaration.
require_once __DIR__ . '/../plugins/licensing/LicensingAPI.php';

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "ext-sodium is required and is not loaded on this PHP install.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);

if (LicensingAPI::hasSigningKeypair() && !$force) {
    fwrite(STDERR, "A signing keypair already exists. Re-run with --force to replace it —\n"
        . "doing so invalidates trust for every install still using the old public key\n"
        . "until you redeploy the new one everywhere.\n");
    exit(1);
}

$keypair = LicensingAPI::generateSigningKeypair();
LicensingAPI::storeSigningKeypair($keypair);

echo "Signing keypair generated and stored (secret key encrypted at rest).\n\n";
echo "Public key (embed this in every client install):\n\n";
echo "  " . $keypair['public'] . "\n\n";
echo "The secret key is not shown — it never leaves this server.\n";
