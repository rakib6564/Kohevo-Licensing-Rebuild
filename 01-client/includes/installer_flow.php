<?php
/**
 * Phase 5 — client installer rebuild: the resume/resolve logic behind
 * install.php's page-per-step rendering, plus a few small install-time
 * helpers. Factored out of install.php itself (a superglobal-driven,
 * HTML-emitting entry point) so it can be exercised directly by tests the
 * same way error_page.php's slate_render_error()/slate_license_gate() are,
 * with zero HTTP/superglobal coupling.
 */

declare(strict_types=1);

if (!function_exists('installer_resolve_step')) {
    /**
     * The single, server-side source of truth for "which installer step is
     * this deployment actually at right now" -- computed fresh from disk/DB
     * state on every call, NEVER from a client-supplied query parameter.
     * install.php uses this both to decide what a GET renders and to refuse
     * a POST aimed at any step other than the one this function currently
     * returns, closing every "jump ahead / replay an old step" attempt
     * (docs/02-architecture/05-INSTALLATION-ACTIVATION.md §5's resume
     * table; the Phase 5 brief's retry Scenarios A-G).
     *
     *   1 = Database Configuration   -- no .env yet
     *   2 = Install Application      -- .env exists, no installation identity yet
     *   3 = License Key              -- identity exists, no verified license that
     *                                   permits installation yet (installer_license_usable())
     *   4 = Create Admin Account     -- license verified, no admin user yet
     *   5 = Finish                   -- admin exists, .installed not yet written
     *
     * Assumes config.php has already been loaded by the caller whenever
     * .env exists -- install.php only calls this once that precondition
     * holds, so Database/TENANT_ID/the autoloader are all available.
     */
    function installer_resolve_step(): int
    {
        if (!file_exists(SLATE_ROOT . '/.env')) {
            return 1;
        }

        try {
            $installationId = \Slate\Services\Installation\InstallationService::currentInstallationId();
        } catch (\Throwable $e) {
            // Schema not migrated yet (fresh .env, Step 2 never completed)
            // or the database is unreachable -- either way, not installed.
            $installationId = null;
        }
        if ($installationId === null) {
            return 2;
        }

        try {
            $store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
            $usable = installer_license_usable($store->readTrustState());
        } catch (\Throwable $e) {
            $usable = false;
        }
        if (!$usable) {
            return 3;
        }

        try {
            $hasAdmin = (int) \Database::value('SELECT COUNT(*) FROM users') > 0;
        } catch (\Throwable $e) {
            $hasAdmin = false;
        }
        if (!$hasAdmin) {
            return 4;
        }

        return 5;
    }
}

if (!function_exists('installer_license_usable')) {
    /**
     * Phase 12: whether a verified license state lets the installer move
     * past the License step. 05 §1 Step 4: the Central validation "MUST
     * succeed (HTTP 200, valid signature, status in {trial, active})". A
     * trusted cache is not enough on its own: since Phase 10 a BOUND
     * installation also receives a signed expired/suspended/revoked
     * payload (11 §7), which is correctly cached so the runtime Guard can
     * enforce it — but it must never let a reinstall create an admin or
     * finish. The same CommercialLicenseWindow the Guard uses must also
     * allow the state, so an install never completes into a locked app.
     *
     * @param array{found:bool,trusted:bool,data:?array} $trust SlateLicenseCacheStore::readTrustState()
     */
    function installer_license_usable(array $trust): bool
    {
        if (empty($trust['trusted']) || !is_array($trust['data'] ?? null)) {
            return false;
        }
        if (!in_array($trust['data']['status'] ?? null, ['trial', 'active'], true)) {
            return false;
        }
        return \Slate\Services\Licensing\CommercialLicenseWindow::evaluate($trust, time())['allowed'];
    }
}

if (!function_exists('installer_normalize_license_key')) {
    /**
     * Client-side "plausible shape" check ONLY
     * (docs/02-architecture/05-INSTALLATION-ACTIVATION.md §1, Step 3) --
     * this installer has no local authority to judge whether a key is
     * actually valid, only whether it is even worth sending to the Central
     * Server at all.
     *
     * @return array{value:string, error:?string}
     */
    function installer_normalize_license_key(string $raw): array
    {
        $value = trim($raw);
        if ($value === '') {
            return ['value' => '', 'error' => 'Enter your license key.'];
        }
        if (strlen($value) > 200) {
            return ['value' => '', 'error' => 'That license key is too long to be valid.'];
        }
        return ['value' => $value, 'error' => null];
    }
}

if (!function_exists('installer_domain_from_url')) {
    /**
     * The same host[:port] normalization bin/license-check.php already
     * performs for its own outbound check-in. Duplicated deliberately
     * rather than shared: both call sites are short, independent, and
     * already exist -- introducing a shared dependency between the
     * installer and the cron script for five lines of URL parsing is not
     * worth the coupling.
     */
    function installer_domain_from_url(string $url): string
    {
        $parts  = parse_url($url);
        $domain = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port   = isset($parts['port']) ? (int) $parts['port'] : null;
        $scheme = (string) ($parts['scheme'] ?? '');
        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }
        return $port !== null ? $domain . ':' . $port : $domain;
    }
}

if (!function_exists('installer_set_env_line')) {
    /**
     * Replace-or-append a single KEY=value line in a raw .env file body.
     * Idempotent: calling it again with the same value leaves the
     * surrounding content unchanged. Used for every value the installer
     * itself resolves and must durably persist outside the database
     * (TENANT_ID, INSTALLATION_ID) -- see
     * docs/02-architecture/15-PHASE-1-DECISIONS.md D18 for why
     * INSTALLATION_ID specifically must survive a database wipe.
     */
    function installer_set_env_line(string $envBody, string $key, string $value): string
    {
        $line = $key . '=' . $value;
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $envBody)) {
            return (string) preg_replace($pattern, $line, $envBody);
        }
        return $envBody . ($envBody !== '' && !str_ends_with($envBody, "\n") ? "\n" : '') . $line . "\n";
    }
}
