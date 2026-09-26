<?php
/**
 * Licensing plugin — data access.
 *
 * Phase 1 scope: schema only. ensureSchema() keeps an already-active install
 * current after an upgrade ships new CREATE TABLE IF NOT EXISTS statements —
 * same self-heal idiom Membership/Booking/Stripe already use, replaying
 * install.sql itself rather than duplicating DDL in PHP.
 */

declare(strict_types=1);

class LicensingAPI {

    private static bool $schemaChecked = false;

    public static function ensureSchema(): void {
        if (self::$schemaChecked) return;
        self::$schemaChecked = true;

        $files = [__DIR__ . '/install.sql'];
        foreach ((array) glob(__DIR__ . '/migrations/*.sql') as $migration) {
            $files[] = $migration;
        }
        foreach ($files as $file) {
            if (!is_file($file)) continue;
            $sql = (string) file_get_contents($file);
            $sql = preg_replace('/^--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt === '') continue;
                try {
                    Database::query($stmt);
                } catch (\Throwable $e) {
                    slate_log('Licensing schema statement failed (' . basename($file) . '): ' . $e->getMessage(), 'error');
                }
            }
        }
    }

    // ── Phase 2: Ed25519 signing ────────────────────────────────
    //
    // Every response this server sends a client install must be verifiable
    // with ONLY the public key — the private key never leaves this server,
    // and client code (see client/LicenseSignatureVerifier.php) never
    // touches it. sign()/verify() are pure functions with no Slate
    // dependency beyond ext-sodium, so the exact same call also works
    // inside that standalone client file.

    /**
     * Generate a new Ed25519 keypair. Returns raw (not yet stored) keys as
     * base64 strings — the caller decides how/whether to persist them.
     * Never logs or echoes the secret key itself.
     */
    public static function generateSigningKeypair(): array {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /** Sign an arbitrary payload with a base64-encoded Ed25519 secret key. */
    public static function sign(string $payload, string $secretKeyB64): string {
        $secretKey = base64_decode($secretKeyB64, true);
        if ($secretKey === false) {
            throw new \InvalidArgumentException('Malformed secret key.');
        }
        return base64_encode(sodium_crypto_sign_detached($payload, $secretKey));
    }

    /** Verify a payload's signature with ONLY a base64-encoded public key. */
    public static function verify(string $payload, string $signatureB64, string $publicKeyB64): bool {
        $signature = base64_decode($signatureB64, true);
        $publicKey = base64_decode($publicKeyB64, true);
        if ($signature === false || $publicKey === false) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
        } catch (\SodiumException $e) {
            return false; // malformed signature/key length — never a fatal
        }
    }

    // ── Key storage ──────────────────────────────────────────────
    //
    // Public key: stored as plain text — it's meant to be embedded in every
    // client install, there is nothing to protect. Secret key: stored
    // through slate_encrypt_secret() (AES-256-GCM keyed off APP_SECRET),
    // the same at-rest encryption already used for Stripe/Twilio/SMTP
    // credentials elsewhere in this app — never persisted in plain text.

    private const SETTING_PUBLIC_KEY = 'licensing.signing_public_key';
    private const SETTING_SECRET_KEY = 'licensing.signing_secret_key';

    public static function hasSigningKeypair(): bool {
        return (string) Database::setting(self::SETTING_PUBLIC_KEY) !== '';
    }

    public static function storeSigningKeypair(array $keypair): void {
        Database::setSetting(self::SETTING_PUBLIC_KEY, $keypair['public']);
        Database::setSetting(self::SETTING_SECRET_KEY, slate_encrypt_secret($keypair['secret']));
    }

    public static function signingPublicKey(): ?string {
        $val = (string) Database::setting(self::SETTING_PUBLIC_KEY);
        return $val !== '' ? $val : null;
    }

    public static function signingSecretKey(): ?string {
        $raw = (string) Database::setting(self::SETTING_SECRET_KEY);
        if ($raw === '') return null;
        return slate_decrypt_secret($raw);
    }

    // ── Phase 3: the check-in endpoint ────────────────────────────
    //
    // Business logic lives here, not in public/check.php, so it can be
    // tested directly (a real request/response cycle) without needing a
    // live HTTP server. public/check.php is a thin shim: read the body,
    // call handleCheckIn(), emit the status + JSON it returns.
    //
    // Response shape is deliberately a signed ENVELOPE, not a flat signed
    // object: {"payload": "<exact JSON string that was signed>",
    // "signature": "..."}. `payload` is a JSON string, not a nested
    // object — the client verifies the signature against that exact byte
    // string first, and only decodes it into fields after verification
    // succeeds. A flat object would force the client to re-serialize the
    // fields identically to verify, which is exactly the kind of
    // canonicalization mismatch that quietly breaks signature schemes.
    //
    // An invalid product slug and an invalid license key return the
    // IDENTICAL error (same HTTP status, same body) — matching this
    // app's existing "unknown email vs. wrong password fail identically"
    // anti-enumeration precedent (Auth::attemptLogin()). A caller must
    // never be able to tell "wrong key" from "wrong product" from the
    // response alone.

    /**
     * QA Fix Round 1 (Phase 4, Fix 2): the canonical Installation ID format
     * — lowercase hex, exactly 32 characters. Mirrors
     * InstallationService::INSTALLATION_ID_PATTERN exactly (kept as its own
     * constant here rather than a cross-file reference so this file's own
     * very first validation step — before any product/license lookup even
     * happens — has no load-order dependency on the plugin's other files).
     */
    private const INSTALLATION_ID_PATTERN = '/^[a-f0-9]{32}$/';

    /**
     * QA Fix Round 1 (Phase 4, Fix 3): dispatches to whichever of the two
     * check-in table families the presented license_key actually belongs
     * to — never both, never neither treated as authoritative over the
     * other. This is the explicit, safe "compatibility bridge" the fix
     * calls for:
     *
     *   - A key hashing into `licensing_licenses` (issued via
     *     LicenseService::issue() / admin/licenses.php, the Phase 2/3
     *     commercial authority) is handled by handleCommercialCheckIn(),
     *     which operates EXCLUSIVELY through LicenseService/
     *     InstallationService -- never a second, competing implementation
     *     of the same lifecycle/binding rules.
     *   - A key hashing into the legacy `licensing_installs` table
     *     (issued via LicensingAPI::issueInstall() / admin/installs.php,
     *     still-supported per 13-MIGRATION-STRATEGY.md) is handled by
     *     handleLegacyCheckIn() -- byte-for-byte the same logic this
     *     method used to contain directly, entirely unchanged, so every
     *     existing legacy-path behavior (and its test coverage) is
     *     preserved exactly.
     *
     * A license_key's SHA-256 hash can, for all practical purposes, only
     * ever exist as a row in one of these two tables (they are issued by
     * two entirely separate `issue`/`generateLicenseKey` call sites) -- so
     * this dispatch is a lookup, not a policy choice between two sources of
     * truth for the same license.
     */
    public static function handleCheckIn(array $input, string $ip): array {
        try {
            $productSlug = trim((string) ($input['product'] ?? ''));
            $licenseKey  = (string) ($input['license_key'] ?? '');
            // QA Fix Round 2 (Fix 1): the RAW supplied value is validated
            // below, with no normalization applied first -- lower-casing or
            // trimming before validation would let an uppercase or
            // whitespace-padded install_id slip through format validation.
            // Only after the strict format check succeeds is this exact
            // validated value ever used.
            $installationId = (string) ($input['install_id'] ?? '');
            $domain      = self::normalizeDomain((string) ($input['domain'] ?? ''));
            $appVersion  = trim((string) ($input['app_version'] ?? ''));

            if ($productSlug === '' || $licenseKey === '' || $installationId === '' || $domain === null) {
                return self::checkInError(400, 'invalid_request');
            }
            // Fix 2A: format-validated before ANY database lookup or
            // mutation -- a malformed install_id (wrong length, uppercase,
            // mixed-case, non-hex, whitespace-padded, SQL-shaped, etc.) is
            // rejected exactly like any other malformed required field,
            // never normalized-and-retried.
            if (preg_match(self::INSTALLATION_ID_PATTERN, $installationId) !== 1) {
                return self::checkInError(400, 'invalid_request');
            }

            $product = Database::row('SELECT id FROM licensing_products WHERE slug = ?', [$productSlug]);
            if (!$product) return self::checkInError(404, 'invalid_request');

            $secretKey = self::signingSecretKey();
            if ($secretKey === null) {
                slate_log('Licensing check-in: no signing keypair provisioned', 'error');
                return self::checkInError(500, 'server_error');
            }

            $keyHash = hash('sha256', $licenseKey);

            $commercialLicense = Database::row(
                'SELECT * FROM licensing_licenses WHERE product_id = ? AND license_key_hash = ?',
                [$product['id'], $keyHash]
            );
            if ($commercialLicense !== null) {
                return self::handleCommercialCheckIn($commercialLicense, $installationId, $domain, $appVersion, $ip, $secretKey);
            }

            $install = Database::row(
                'SELECT * FROM licensing_installs WHERE product_id = ? AND license_key_hash = ?',
                [$product['id'], $keyHash]
            );
            if ($install !== null) {
                return self::handleLegacyCheckIn($install, $installationId, $domain, $appVersion, $ip, $secretKey);
            }

            // Neither table has this key -- identical shape/status to every
            // other "invalid" outcome above (anti-enumeration).
            return self::checkInError(404, 'invalid_request');
        } catch (\Throwable $e) {
            slate_log('Licensing check-in failed: ' . $e->getMessage(), 'error');
            return self::checkInError(500, 'server_error');
        }
    }

    /**
     * QA Fix Round 1 (Phase 4, Fix 3): the Phase 2/3 commercial licensing
     * path -- `licensing_licenses` + `licensing_installations` +
     * `licensing_license_modules` are the ONLY tables this touches. Every
     * lifecycle/binding rule is delegated to LicenseService/
     * InstallationService (Do NOT create a second commercial licensing
     * path) rather than re-implemented here; this method's own job is
     * strictly the HTTP-shaped orchestration: resolve -> gate on lifecycle
     * state -> bind-or-refresh -> sign.
     *
     * No outer transaction wraps this method: InstallationService::
     * activate() already opens and manages its own (with its own
     * `SELECT ... FOR UPDATE` row lock -- the existing, tested Phase 2 race
     * protection), and wrapping a second transaction around a call into it
     * would attempt a non-reentrant nested `beginTransaction()`. The
     * "refresh" branch below needs no transaction of its own: it is a
     * single guarded UPDATE (InstallationService::touch()), atomic by
     * construction.
     */
    private static function handleCommercialCheckIn(
        array $license, string $installationId, string $domain, string $appVersion, string $ip, string $secretKey
    ): array {
        // Lazy expiry sync (LicenseService::syncExpiry() manages its own
        // transaction internally; must not be called from inside one of
        // ours). Reuses the EXISTING lifecycle authority -- never a second,
        // check-in-specific expiry computation (requirement #14).
        $license = LicenseService::syncExpiry((int) $license['id']);
        $activatable = in_array($license['status'], LicenseService::ACTIVATABLE_STATUSES, true);

        $installation = InstallationService::findByInstallationId($installationId);

        // Requirement #5/#15: an expired/suspended/revoked/cancelled License
        // must never GAIN a binding merely because check-in is public --
        // with no bound Installation there is no "current state" to sign
        // for (11-LICENSING-API-CONTRACT.md §13's first-activation case).
        // Reuses LicenseService's own activatable-state definition rather
        // than a second, parallel one.
        if ($installation === null && !$activatable) {
            slate_log('Licensing check-in rejected (commercial): license=' . $license['id'] . ' reason=inactive status=' . $license['status'], 'warning');
            return self::checkInError(403, 'invalid_request');
        }

        if ($installation !== null) {
            // Requirement #16: cross-license installation manipulation must
            // remain impossible -- an installation_id already bound to a
            // DIFFERENT license (or no longer active) is a binding
            // mismatch, not "not found", but reported identically to one
            // (anti-enumeration).
            if ((int) $installation['license_id'] !== (int) $license['id'] || (string) $installation['status'] !== 'active') {
                slate_log('Licensing check-in rejected (commercial): binding_mismatch install=' . $installationId, 'warning');
                return self::checkInError(404, 'invalid_request');
            }
            // Requirement #4, D9 default: hard-block a domain that no
            // longer matches this Installation's bound domain.
            if ((string) $installation['domain_normalized'] !== $domain) {
                slate_log('Licensing check-in rejected (commercial): domain_mismatch install=' . $installationId, 'warning');
                return self::checkInError(404, 'invalid_request');
            }
            // An ordinary routine refresh -- never re-activate, never a
            // second 'activate' event (requirement #11's "exactly ONE").
            InstallationService::touch((int) $installation['id'], $appVersion !== '' ? $appVersion : null, $ip !== '' ? $ip : null);

            // Phase 10 (11-LICENSING-API-CONTRACT.md §7, LOCKED -- resolves
            // F-02): a correctly bound Installation whose License is
            // expired/suspended/revoked/cancelled receives a normal HTTP 200
            // SIGNED payload carrying that actual status -- never a 403 with
            // no payload. This is the only way Suspend/Revoke/Expire can
            // reach the client as authoritative state (08 §3) instead of
            // looking like a transient network failure that leaves the last
            // cached "active" snapshot in force.
            if (!$activatable) {
                slate_log('Licensing check-in (commercial): delivering signed status=' . $license['status'] . ' license=' . $license['id'], 'info');
            }
        } else {
            // Requirements #6-#10: bind through the existing, tested
            // commercial installation architecture -- InstallationService::
            // activate() performs the row lock, the transaction, the
            // activation_limit enforcement (1 License = 1 active
            // Installation, requirement #6), the Unactivated -> Active
            // transition (requirement #10), and the single 'activate' audit
            // event (requirement #11), all in one place, never duplicated
            // here.
            try {
                $newId = InstallationService::activate((int) $license['id'], [
                    'installation_id'   => $installationId,
                    'domain'            => $domain,
                    'installed_version' => $appVersion !== '' ? $appVersion : null,
                    'last_seen_ip'      => $ip !== '' ? $ip : null,
                ]);
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'activation_limit') {
                    slate_log('Licensing check-in rejected (commercial): activation_limit license=' . $license['id'], 'warning');
                    return self::checkInError(403, 'invalid_request');
                }
                slate_log('Licensing check-in (commercial) unexpected failure: ' . $e->getMessage(), 'error');
                return self::checkInError(500, 'server_error');
            } catch (\InvalidArgumentException $e) {
                // License no longer activatable (raced between the gate
                // above and activate()'s own row lock) or a domain that
                // fails validation -- never surfaced verbatim to a public
                // caller.
                slate_log('Licensing check-in rejected (commercial): ' . $e->getMessage(), 'warning');
                return self::checkInError(403, 'invalid_request');
            } catch (\Throwable $e) {
                // Requirement #17 / security requirement: concurrent
                // check-ins racing to bind the SAME installation_id can
                // still surface a genuine PDOException (the
                // uniq_installation_identity unique key) even though the
                // License-level FOR UPDATE lock already prevents duplicate
                // ACTIVE bindings per license -- never let raw SQL/PDO
                // detail reach a public caller.
                slate_log('Licensing check-in (commercial) activation failed: ' . $e->getMessage(), 'error');
                return self::checkInError(500, 'server_error');
            }
            $installation = InstallationService::find($newId);
            // activate() may have just transitioned Unactivated -> Active;
            // re-read so the signed payload reflects the license's actual
            // current status, not the pre-activation snapshot. Through
            // syncExpiry(), not a plain find(): the lazy expiry sync above
            // skipped the license while it was still Unactivated, so a
            // License issued with an expires_at that has already passed
            // would otherwise be stored and SIGNED as 'active' (Phase 12,
            // 11 §7: the payload carries the actual commercial state).
            $license = LicenseService::syncExpiry((int) $license['id']);
        }

        $planSlug = null;
        if (!empty($license['plan_id'])) {
            $slug = Database::value('SELECT slug FROM licensing_plans WHERE id = ?', [$license['plan_id']]);
            $planSlug = $slug !== null && $slug !== '' ? (string) $slug : null;
        }
        // Requirement #13: per-license commercial entitlements
        // (licensing_license_modules), never the legacy per-plan
        // entitlements_json source.
        $entitlements = LicenseService::modules((int) $license['id']);

        // Phase 10 (11 §2, 08 §3): warning_days/grace_days travel with every
        // payload, whatever the status, so the signed state is complete on
        // its own. installation_id comes from the resolved binding (the
        // validated value that matched licensing_installations above),
        // never from anything else in the request.
        $payload = [
            'installation_id' => (string) $installation['installation_id'],
            'status' => $license['status'], 'plan' => $planSlug, 'entitlements' => $entitlements,
            'expires_at' => $license['expires_at'],
            'warning_days' => (int) ($license['warning_days'] ?? 7), 'grace_days' => (int) ($license['grace_days'] ?? 7),
            'checked_at' => gmdate('c'), 'next_check_after' => 86400,
        ];
        $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = self::sign($payloadJson, $secretKey);
        return ['http_status' => 200, 'body' => ['payload' => $payloadJson, 'signature' => $signature]];
    }

    /**
     * The legacy check-in path -- `licensing_installs` +
     * `licensing_installation_bindings`. Deliberately byte-for-byte
     * identical to what handleCheckIn() used to do directly before the
     * Fix 3 dispatch was introduced; only ever reached for a license_key
     * that does NOT resolve against the Phase 2/3 commercial tables, so
     * every existing legacy-issued install keeps checking in exactly as it
     * always has (LEGACY COMPATIBILITY: not deleted, not migrated, not
     * silently made non-authoritative for keys that are actually theirs).
     */
    private static function handleLegacyCheckIn(
        array $install, string $installationId, string $domain, string $appVersion, string $ip, string $secretKey
    ): array {
        $pdo = Database::get();
        $pdo->beginTransaction();
        $transactionStarted = true;
        try {
            $install = Database::row('SELECT * FROM licensing_installs WHERE id = ? FOR UPDATE', [(int) $install['id']]);
            $expectedDomain = self::normalizedInstallDomain($install);
            if ($expectedDomain === null || !hash_equals($expectedDomain, $domain)) {
                self::recordRejectedCheckIn((int) $install['id'], $ip, $domain, $appVersion, 'binding_mismatch');
                $pdo->commit();
                $transactionStarted = false;
                return self::checkInError(404, 'invalid_request');
            }

            $status = self::effectiveInstallStatus($install);
            if (!in_array($status, ['trial', 'active'], true)) {
                self::recordRejectedCheckIn((int) $install['id'], $ip, $domain, $appVersion, 'inactive');
                $pdo->commit();
                $transactionStarted = false;
                return self::checkInError(403, 'invalid_request');
            }

            $binding = Database::row(
                'SELECT * FROM licensing_installation_bindings WHERE installation_id = ? FOR UPDATE',
                [$installationId]
            );
            if ($binding !== null && (int) $binding['install_id'] !== (int) $install['id']) {
                self::recordRejectedCheckIn((int) $install['id'], $ip, $domain, $appVersion, 'binding_mismatch');
                $pdo->commit();
                $transactionStarted = false;
                return self::checkInError(404, 'invalid_request');
            }

            if ($binding === null) {
                $activationLimit = max(1, (int) $install['activation_limit']);
                if ((int) $install['activation_count'] >= $activationLimit) {
                    self::recordRejectedCheckIn((int) $install['id'], $ip, $domain, $appVersion, 'activation_limit');
                    $pdo->commit();
                    $transactionStarted = false;
                    return self::checkInError(403, 'invalid_request');
                }
                Database::insert('licensing_installation_bindings', [
                    'install_id'        => (int) $install['id'],
                    'installation_id'  => $installationId,
                    'domain_normalized' => $domain,
                    'last_seen_ip'     => $ip !== '' ? $ip : null,
                    'status'            => 'active',
                ]);
                Database::update('licensing_installs', ['activation_count' => (int) $install['activation_count'] + 1], 'id = ?', [(int) $install['id']]);
            } else {
                if ((string) $binding['domain_normalized'] !== $domain || (string) $binding['status'] !== 'active') {
                    self::recordRejectedCheckIn((int) $install['id'], $ip, $domain, $appVersion, 'binding_mismatch');
                    $pdo->commit();
                    $transactionStarted = false;
                    return self::checkInError(404, 'invalid_request');
                }
                Database::update('licensing_installation_bindings', [
                    'last_seen_at' => slate_db_now(),
                    'last_seen_ip' => $ip !== '' ? $ip : null,
                ], 'id = ?', [(int) $binding['id']]);
            }

            Database::insert('licensing_checkins', [
                'install_id'       => $install['id'],
                'ip'               => $ip !== '' ? $ip : null,
                'reported_domain'  => $domain,
                'reported_version' => $appVersion !== '' ? $appVersion : null,
                'response_status'  => $status,
                'failure_code'     => null,
            ]);
            Database::update('licensing_installs', [
                'domain_normalized' => $expectedDomain,
                'last_checkin_at'   => slate_db_now(),
                'last_checkin_ip'   => $ip !== '' ? $ip : null,
                'installed_version' => $appVersion !== '' ? $appVersion : $install['installed_version'],
            ], 'id = ?', [$install['id']]);

            $planSlug = null;
            $entitlements = [];
            if (!empty($install['plan_id'])) {
                $plan = Database::row('SELECT slug, entitlements_json FROM licensing_plans WHERE id = ?', [$install['plan_id']]);
                if ($plan) {
                    $planSlug = $plan['slug'];
                    $entitlements = json_decode((string) $plan['entitlements_json'], true) ?: [];
                }
            }
            // Phase 4 (D14, 11-LICENSING-API-CONTRACT.md §2): bound to the
            // specific Installation this check-in resolved against -- see
            // the identical reasoning on the commercial path above.
            $payload = [
                'installation_id' => $installationId,
                'status' => $status, 'plan' => $planSlug, 'entitlements' => $entitlements,
                'expires_at' => $install['expires_at'], 'checked_at' => gmdate('c'), 'next_check_after' => 86400,
            ];
            $payloadJson = (string) json_encode($payload, JSON_UNESCAPED_SLASHES);
            $signature = self::sign($payloadJson, $secretKey);
            $pdo->commit();
            $transactionStarted = false;
            return ['http_status' => 200, 'body' => ['payload' => $payloadJson, 'signature' => $signature]];
        } catch (\Throwable $e) {
            if ($transactionStarted && $pdo->inTransaction()) $pdo->rollBack();
            slate_log('Licensing check-in (legacy) failed: ' . $e->getMessage(), 'error');
            return self::checkInError(500, 'server_error');
        }
    }

    /**
     * Canonical comparison form: optional http/https scheme is ignored;
     * hostname is lower-cased; trailing dot is removed; non-default ports
     * are retained; paths, queries and fragments are rejected. Subdomains
     * remain distinct and localhost/dev domains are accepted.
     */
    public static function normalizeDomain(string $value): ?string
    {
        $raw = trim($value);
        if ($raw === '') return null;
        $candidate = preg_match('#^https?://#i', $raw) ? $raw : 'http://' . $raw;
        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['host'])) return null;
        // Reject userinfo (user and/or pass), a query string, and a fragment
        // — each checked individually. A multi-argument isset($a, $b, $c, $d)
        // here previously required ALL FOUR to be present simultaneously to
        // reject anything (isset() with multiple arguments is `isset($a) &&
        // isset($b) && ...`, not "any of"), so a URL carrying only a query
        // string (`?x=1`), only a fragment (`#frag`), or only userinfo
        // (`user:pass@`) sailed through unrejected.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') return null;
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($host === '' || !self::isValidHostname($host)) return null;
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) $port = null;
        return $host . ($port !== null ? ':' . $port : '');
    }

    /**
     * Validates each dot-separated label independently: must be non-empty
     * and start/end with an alphanumeric character (a hyphen is only valid
     * between two alphanumerics). The previous single regex anchored only
     * the very first and very last character of the *whole* hostname
     * string, so a lone interior label ending in a hyphen
     * ("trailinghyphen-.example") or an empty label from consecutive dots
     * ("acme..example") both passed undetected.
     */
    private static function isValidHostname(string $host): bool
    {
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $label)) return false;
        }
        return true;
    }

    private static function normalizedInstallDomain(array $install): ?string
    {
        $stored = trim((string) ($install['domain_normalized'] ?? ''));
        if ($stored !== '') return $stored;
        $normalized = self::normalizeDomain((string) ($install['domain'] ?? ''));
        if ($normalized !== null) Database::update('licensing_installs', ['domain_normalized' => $normalized], 'id = ?', [(int) $install['id']]);
        return $normalized;
    }

    private static function recordRejectedCheckIn(int $installId, string $ip, string $domain, string $version, string $failureCode): void
    {
        Database::insert('licensing_checkins', [
            'install_id' => $installId, 'ip' => $ip !== '' ? $ip : null,
            'reported_domain' => $domain !== '' ? $domain : null,
            'reported_version' => $version !== '' ? $version : null,
            'response_status' => 'rejected', 'failure_code' => $failureCode,
        ]);
        slate_log('Licensing check-in rejected: install=' . $installId . ' reason=' . $failureCode, 'warning');
    }

    /** Lazy expiry, same reasoning as the local LicenseService::effectiveStatus(): expires_at
     *  in the past reports 'expired' immediately, without waiting for a sweep to persist it. */
    private static function effectiveInstallStatus(array $install): string {
        $status = (string) ($install['status'] ?? 'trial');
        if (in_array($status, ['trial', 'active'], true) && !empty($install['expires_at'])
            && strtotime((string) $install['expires_at']) <= time()) {
            return 'expired';
        }
        return $status;
    }

    private static function checkInError(int $httpStatus, string $code): array {
        return ['http_status' => $httpStatus, 'body' => ['error' => $code]];
    }

    /** Explicit administrator operation: release all bound identities and reset the counter. */
    public static function resetBindings(int $installId): void
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            Database::query('SELECT id FROM licensing_installs WHERE id = ? FOR UPDATE', [$installId])->fetch();
            Database::delete('licensing_installation_bindings', 'install_id = ?', [$installId]);
            Database::update('licensing_installs', ['activation_count' => 0], 'id = ?', [$installId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    // ── Admin UI support: issuing and rotating license keys ────────
    //
    // The raw key is generated here and handed back to the caller exactly
    // once — only its SHA-256 hash is ever persisted (same rule the
    // install.sql header states for `licensing_installs.license_key_hash`).
    // Centralized here rather than in admin/installs.php so the admin
    // screen and any future CLI/API path generate keys the same way.

    /** A readable, high-entropy key: PRODUCTSLUG-XXXX-XXXX-XXXX-XXXX-XXXX. */
    public static function generateLicenseKey(string $productSlug): string {
        $prefix = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $productSlug) ?? '', 0, 12));
        if ($prefix === '') $prefix = 'LIC';
        $raw = strtoupper(bin2hex(random_bytes(10)));
        return $prefix . '-' . implode('-', str_split($raw, 4));
    }

    /**
     * Create a new install/license row. $data keys: client_id, product_id,
     * plan_id (nullable), label, domain, status, expires_at (nullable),
     * activation_limit. Returns ['id' => int, 'license_key' => string] —
     * the caller must show license_key to the admin now; it cannot be
     * retrieved again afterward.
     */
    public static function issueInstall(array $data, string $productSlug): array {
        $licenseKey = self::generateLicenseKey($productSlug);
        $domain = self::normalizeDomain((string) ($data['domain'] ?? ''));
        if ($domain === null) throw new \InvalidArgumentException('Invalid license domain.');
        $id = Database::insert('licensing_installs', [
            'client_id'        => (int) $data['client_id'],
            'product_id'       => (int) $data['product_id'],
            'plan_id'          => !empty($data['plan_id']) ? (int) $data['plan_id'] : null,
            'label'            => (string) $data['label'],
            'domain'           => $domain,
            'domain_normalized' => $domain,
            'license_key_hash' => hash('sha256', $licenseKey),
            'status'           => (string) $data['status'],
            'expires_at'       => $data['expires_at'] !== '' ? $data['expires_at'] : null,
            'activation_limit' => max(1, (int) $data['activation_limit']),
        ]);
        return ['id' => $id, 'license_key' => $licenseKey];
    }

    /** Rotate an existing install's key. Old key stops working immediately. */
    public static function regenerateLicenseKey(int $installId, string $productSlug): string {
        $licenseKey = self::generateLicenseKey($productSlug);
        Database::update('licensing_installs',
            ['license_key_hash' => hash('sha256', $licenseKey)],
            'id = ?', [$installId]
        );
        return $licenseKey;
    }
}
