<?php
/**
 * Slate — install wizard.
 *
 * Phase 5 (client installer rebuild): the target commercial sequence --
 * Database Configuration -> Install Application -> License Key ->
 * Central Licensing Server Validation -> Activate Installation ->
 * Create Admin Account -> Finish -> Dashboard
 * (docs/02-architecture/05-INSTALLATION-ACTIVATION.md §1). "Central
 * Validation" and "Activate Installation" are one synchronous network call
 * (§2 of that document — the check-in call IS the activation event), so
 * this file models them as a single step (3).
 *
 * The step actually rendered/processed on every request is computed fresh
 * from disk/DB state by installer_resolve_step() (includes/installer_flow.php)
 * -- never trusted from the URL's `?step=` query parameter, which is only a
 * hint for a plain GET and is otherwise ignored. This is what makes the
 * installer safely resumable after any interruption (browser close, crash,
 * retry with a different license key) and closes every "jump ahead to a
 * later step" / "replay a stale step" attempt: a POST is only ever acted on
 * when its target step matches the server-resolved current step.
 *
 * Stops itself once .installed marker exists.
 */

define('SLATE_ROOT', __DIR__);
define('SLATE_VERSION', '1.0.0');
$installMarker = SLATE_ROOT . '/.installed';

// ── Already installed ───────────────────────────────────────
if (file_exists($installMarker)) {
    require __DIR__ . '/includes/helpers.php';
    ?>
    <!DOCTYPE html>
    <html lang="en"><head>
        <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Kohevo is already installed</title>
        <?php require_once __DIR__ . '/includes/ui_components.php'; slate_ui_emit_css(); ?>
    </head><body>
    <div style="max-width:520px;margin:60px auto;padding:0 20px">
        <h1>Kohevo is already installed</h1>
        <div class="alert alert-warning">
            To re-install, delete <code>.installed</code> from the project root
            and visit this page again.
        </div>
        <p><a href="admin/" class="btn btn-primary mt-3">Go to admin →</a></p>
    </div>
    </body></html>
    <?php
    exit;
}

require __DIR__ . '/includes/helpers.php';
require __DIR__ . '/includes/installer_flow.php';

$error = '';
$requestedStep = (int) ($_GET['step'] ?? 1);
if ($requestedStep < 1) {
    $requestedStep = 1;
}

// Steps 2+ need the DB connection step 1 itself just configured — guard
// against landing here (bookmark, back button) before .env exists rather
// than fataling on connect.
if ($requestedStep >= 2) {
    if (!file_exists(SLATE_ROOT . '/.env')) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?step=1');
        exit;
    }
    require __DIR__ . '/config.php';
    require __DIR__ . '/plugins/licensing/client/RemoteLicenseClient.php';
}

// The authoritative current step — see installer_flow.php. Never derived
// from $requestedStep beyond deciding whether config.php needed loading.
$step = installer_resolve_step();

// A GET whose requested step doesn't match the resolved step is either a
// stale bookmark/back-button (behind) or an attempt to skip ahead (ahead) —
// in both cases the resolved step is authoritative and wins.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestedStep !== $step) {
    header('Location: ' . $_SERVER['PHP_SELF'] . '?step=' . $step);
    exit;
}

$pluginsOnDisk = [];
$finishSummary = null;
$licenseSummary = null;

// ── POST dispatch — only for the step the server currently considers
// current. A POST body naming any other step is silently ignored (falls
// through to rendering the resolved step's own view) rather than acted on
// for a step that isn't actually reachable yet.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $requestedStep === $step) {
    if ($step >= 2 && !csrf_verify()) {
        $error = 'Your session expired. Please reload the page and try again.';
    } elseif ($step === 1) {
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbPort = trim($_POST['db_port'] ?? '');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = (string)($_POST['db_pass'] ?? '');
        $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

        if ($dbPort !== '' && !preg_match('/^\d{1,5}$/', $dbPort)) {
            $error = 'Database port must be numeric.';
        } elseif ($dbName === '' || $dbUser === '' || $appUrl === '') {
            $error = 'Please fill in all required fields.';
        } else {
            try {
                $dsn = "mysql:host=$dbHost" . ($dbPort !== '' ? ";port=$dbPort" : '') . ";dbname=$dbName;charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (PDOException $e) {
                $error = 'Could not connect to the database: ' . htmlspecialchars($e->getMessage());
                $pdo = null;
            }

            if ($pdo ?? null) {
                $appSecret  = bin2hex(random_bytes(32));
                $cronSecret = bin2hex(random_bytes(32));

                $envBody  = "APP_URL=$appUrl\n";
                $envBody .= "APP_SECRET=$appSecret\n";
                $envBody .= "CRON_SECRET=$cronSecret\n";
                $envBody .= "MCP_GATEWAY_ENABLED=0\n";
                $envBody .= "DB_HOST=$dbHost\n";
                if ($dbPort !== '') $envBody .= "DB_PORT=$dbPort\n";
                $envBody .= "DB_NAME=$dbName\nDB_USER=$dbUser\nDB_PASS=$dbPass\nDB_CHARSET=utf8mb4\n";

                if (@file_put_contents(SLATE_ROOT . '/.env', $envBody) === false) {
                    $error = 'Could not write .env. Make the project root writable temporarily, '
                           . 'or paste this into .env manually:<br><pre style="background:#f5f1e8;padding:12px;border-radius:8px;font-size:12px;overflow:auto">'
                           . htmlspecialchars($envBody) . '</pre>';
                } else {
                    @chmod(SLATE_ROOT . '/.env', 0640);
                    header('Location: ' . $_SERVER['PHP_SELF'] . '?step=2');
                    exit;
                }
            }
        }
    } elseif ($step === 2) {
        try {
            // Core schema + the identity spine + login-attempt throttling +
            // the installation identity + the (empty until licensed) remote
            // license cache table, applied and ledger-recorded by name.
            // Deliberately NOT a plain migrate(): that would also pick up
            // any product-specific migration pending in this checkout,
            // which does not belong in a generic install.
            $runner = new \Slate\Data\MigrationRunner(Database::get(), SLATE_ROOT . '/db/migrations');
            $runner->migrate([
                '0001_core_init',
                '0002_identity_core',
                '0011_login_attempts',
                '0014_tenant_profiles',
                '0023_installation_identity',
                '0022_remote_license_cache',
                '0024_remote_license_metadata',
                '0025_remote_license_cache_installation_id',
                '0026_remote_license_cache_signed_payload',
            ]);

            // Tenant/profile/role/installation-identity scaffolding ONLY —
            // no admin account here (INST-04: that must wait until AFTER a
            // license activates, docs/05 §1 Step 6).
            $core = \Slate\Services\Installation\InstallationService::provisionCore();

            // The Installation ID must be durable independent of the
            // database (D18): persisted to .env here, alongside TENANT_ID,
            // so a later database wipe-and-retry (Scenario B/D) reuses the
            // SAME id rather than burning a second activation slot.
            $envPath = SLATE_ROOT . '/.env';
            $envBody = (string) file_get_contents($envPath);
            $envBody = installer_set_env_line($envBody, 'TENANT_ID', (string) $core['tenant_id']);
            $envBody = installer_set_env_line($envBody, 'INSTALLATION_ID', (string) $core['installation_id']);

            if (@file_put_contents($envPath, $envBody) === false) {
                throw new \RuntimeException('Installation data was created, but could not be persisted to .env.');
            }
            @chmod($envPath, 0640);

            header('Location: ' . $_SERVER['PHP_SELF'] . '?step=3');
            exit;
        } catch (\Throwable $e) {
            slate_log('Installer step 2 (install application) failed: ' . $e->getMessage(), 'error');
            $error = 'Installation failed. Please check the server logs and try again.';
        }
    } elseif ($step === 3) {
        $licenseKeyInput = installer_normalize_license_key((string) ($_POST['license_key'] ?? ''));
        if ($licenseKeyInput['error'] !== null) {
            $error = $licenseKeyInput['error'];
        } else {
            $serverUrl = env('LICENSE_SERVER_URL', '');
            $publicKey = env('LICENSE_SERVER_PUBLIC_KEY', '');
            $product   = env('LICENSE_PRODUCT', '');
            $installId = \Slate\Services\Installation\InstallationService::currentInstallationId();

            if ($serverUrl === '' || $publicKey === '' || $product === '' || $installId === null) {
                $error = 'Licensing is not configured for this build. Contact your provider.';
            } else {
                try {
                    $store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
                    $client = new RemoteLicenseClient([
                        'server_url'  => $serverUrl,
                        'public_key'  => $publicKey,
                        'product'     => $product,
                        'license_key' => $licenseKeyInput['value'],
                        'install_id'  => $installId,
                        'domain'      => installer_domain_from_url(SLATE_URL),
                        'app_version' => SLATE_VERSION,
                    ], $store);

                    $result = $client->checkInDetailed();
                    if ($result['ok'] && installer_license_usable($store->readTrustState())) {
                        // Phase 12: persist the key that just verified, as the
                        // License page's recovery re-check already does, so
                        // the unattended check-in (bin/license-check.php,
                        // 06 §5.1) can keep this installation's state fresh.
                        // Without it the cron skips and a new install locks
                        // once the 7-day offline tolerance runs out.
                        $envPath = SLATE_ROOT . '/.env';
                        $envBody = installer_set_env_line((string) file_get_contents($envPath), 'LICENSE_KEY', $licenseKeyInput['value']);
                        if (@file_put_contents($envPath, $envBody) === false) {
                            slate_log('Installer step 3: license verified but LICENSE_KEY could not be persisted to .env', 'error');
                        } else {
                            @chmod($envPath, 0640);
                        }
                        header('Location: ' . $_SERVER['PHP_SELF'] . '?step=4');
                        exit;
                    }
                    if ($result['ok']) {
                        // Verified, but not activatable (05 §1 Step 4): a
                        // bound installation's license is expired,
                        // suspended or revoked. The signed state stays
                        // cached for the Guard; the installer stops here.
                        slate_log('Installer step 3 (license validation): license is not active', 'warning');
                        $error = 'This license is not currently active. Please contact your provider.';
                    } else {
                        slate_log('Installer step 3 (license validation) check-in failed: ' . ($client->lastFailure() ?? 'unknown'), 'warning');
                        $error = $result['reason'] === 'network'
                            ? 'Could not reach the licensing server. Check your connection and try again.'
                            : 'This license key could not be validated. Double-check the key and try again.';
                    }
                } catch (\Throwable $e) {
                    slate_log('Installer step 3 (license validation) failed: ' . $e->getMessage(), 'error');
                    $error = 'This license key could not be validated. Double-check the key and try again.';
                }
            }
        }
    } elseif ($step === 4) {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || $email === '' || strlen($password) < 8) {
            $error = 'Name, email, and a password of at least 8 characters are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            try {
                \Slate\Services\Installation\InstallationService::createAdminAccount(
                    (int) TENANT_ID,
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT)
                );
                header('Location: ' . $_SERVER['PHP_SELF'] . '?step=5');
                exit;
            } catch (\Throwable $e) {
                slate_log('Installer step 4 (admin account) failed: ' . $e->getMessage(), 'error');
                $error = 'Could not create the admin account. Please check the server logs and try again.';
            }
        }
    } elseif ($step === 5) {
        $action = (string) ($_POST['_action'] ?? 'finish');

        if ($action === 'finish_anyway') {
            // Reached only after a partial plugin-activation failure screen
            // the operator has already seen — finish setup regardless;
            // failed plugins are simply never activated, nothing was left
            // half-applied. The license activation and admin account this
            // gates on already succeeded before this step was reachable.
            file_put_contents($installMarker, "Installed: " . date('Y-m-d H:i:s') . " | Kohevo " . SLATE_VERSION . "\n");
            @chmod($installMarker, 0640);
            header('Location: ' . SLATE_URL . '/admin/login.php?installed=1');
            exit;
        }

        $entitlements = [];
        try {
            $store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
            $state = $store->readTrustState();
            $entitlements = $state['trusted'] ? (array) ($state['data']['entitlements'] ?? []) : [];
        } catch (\Throwable $e) {
            $entitlements = [];
        }

        // Auto-activated by the ACTIVATED LICENSE's own entitlements — never
        // an operator checkbox list (docs/05 §1 Step 7; resolves INST-05,
        // "unentitled plugin selection").
        $pluginsOnDisk = PluginLoader::discoverOnDisk();
        $discovered = array_column($pluginsOnDisk, null, 'slug');
        $toActivate = array_values(array_intersect(
            array_map('strval', $entitlements),
            array_keys($discovered)
        ));

        $pluginResults = [];
        foreach ($toActivate as $slug) {
            $res = PluginLoader::installFromDisk($slug);
            $pluginResults[] = [
                'slug'  => $slug,
                'name'  => $discovered[$slug]['name'] ?? $slug,
                'ok'    => !empty($res['ok']),
                'error' => $res['error'] ?? null,
            ];
        }

        $anyFailed = (bool) array_filter($pluginResults, static fn(array $r): bool => !$r['ok']);
        if (!$anyFailed) {
            file_put_contents($installMarker, "Installed: " . date('Y-m-d H:i:s') . " | Kohevo " . SLATE_VERSION . "\n");
            @chmod($installMarker, 0640);
            header('Location: ' . SLATE_URL . '/admin/login.php?installed=1');
            exit;
        }
        // Else fall through and re-render step 5 below with $finishSummary —
        // shows what succeeded/failed and offers "Continue anyway". The
        // marker is deliberately not written until the operator has seen
        // that.
        $finishSummary = $pluginResults;
    }
}

// ── Data needed to render step 5, whether we just fell through from a POST
// above or this is a plain GET landing here.
if ($step === 5 && $finishSummary === null) {
    try {
        $store = new \Slate\Services\Licensing\SlateLicenseCacheStore((int) TENANT_ID);
        $state = $store->readTrustState();
        $licenseSummary = $state['trusted'] ? $state['data'] : null;
    } catch (\Throwable $e) {
        $licenseSummary = null;
    }
}

$licensingConfigured = true;
if ($step === 3) {
    $licensingConfigured = env('LICENSE_SERVER_URL', '') !== ''
        && env('LICENSE_SERVER_PUBLIC_KEY', '') !== ''
        && env('LICENSE_PRODUCT', '') !== ''
        && \Slate\Services\Installation\InstallationService::currentInstallationId() !== null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FBF8F2">
    <title>Install Kohevo</title>
    <?php require_once __DIR__ . '/includes/ui_components.php'; slate_ui_emit_css(); ?>
    <?php require __DIR__ . '/includes/a11y_head.php'; ?>
    <style>
    /* Match the admin's gradient glass canvas: a soft off-white field lit by
       two accent-tinted radial glows, with a frosted card floating on top. */
    body {
        display: flex;
        align-items: flex-start;
        justify-content: center;
        min-height: 100vh;
        min-height: 100dvh;
        padding: var(--space-5) var(--space-4);
        background:
            radial-gradient(60% 50% at 12% 0%, color-mix(in srgb, var(--accent) 16%, transparent), transparent 70%),
            radial-gradient(55% 45% at 100% 100%, color-mix(in srgb, var(--accent) 12%, transparent), transparent 65%),
            var(--bg, #f6f7f9);
        background-attachment: fixed;
    }
    .install-wrap { width: 100%; max-width: 480px; padding-top: var(--space-6); }
    .install-brand {
        text-align: center;
        margin-bottom: var(--space-6);
    }
    .install-brand-mark {
        width: 66px; height: 66px;
        margin: 0 auto var(--space-3);
        background: linear-gradient(150deg, color-mix(in srgb, var(--accent) 86%, #fff), var(--accent));
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--on-accent, #fff);
        font-size: 30px;
        font-weight: 700;
        box-shadow: var(--glow-accent), inset 0 1px 0 rgba(255,255,255,.4);
        letter-spacing: -0.02em;
    }
    .install-title { font-size: 27px; font-weight: 700; letter-spacing: -0.02em; margin: 0; }
    .install-sub   { color: var(--muted); margin: 6px 0 0; font-size: 15px; }
    .step-pill {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 13px;
        background: var(--glass-bg-strong, rgba(255,255,255,.72));
        -webkit-backdrop-filter: blur(var(--glass-blur, 18px));
        backdrop-filter: blur(var(--glass-blur, 18px));
        border: 1px solid var(--glass-border, rgba(255,255,255,.7));
        color: var(--accent);
        border-radius: var(--radius-full);
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .02em;
        margin-bottom: var(--space-3);
    }
    .step-dots { display: inline-flex; gap: 4px; margin-left: 6px; }
    .step-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--accent); opacity: 0.3; transition: opacity .2s; }
    .step-dot.is-on { opacity: 1; }
    .install-card {
        background: var(--glass-bg-strong, rgba(255,255,255,.72));
        -webkit-backdrop-filter: blur(var(--glass-blur, 18px)) saturate(150%);
        backdrop-filter: blur(var(--glass-blur, 18px)) saturate(150%);
        border: 1px solid var(--glass-border, rgba(255,255,255,.7));
        border-radius: var(--radius-lg);
        padding: var(--space-6);
        box-shadow: var(--glass-shadow-lg, 0 14px 44px rgba(31,41,75,.16));
    }
    .install-card h2 { letter-spacing: -0.01em; }
    @media (max-width: 480px) {
        .install-card { padding: var(--space-5) var(--space-4); }
    }
    </style>
</head>
<body>
<div class="install-wrap">
    <div class="install-brand">
        <div class="install-brand-mark" aria-hidden="true"><svg viewBox="0 0 198.64 300" width="28" height="28" fill="currentColor"><path d="M86.6 0L42.5 76.4C25 106.7 0 110 0 150L0 300L52 300L52 150L138.6 300L198.64 300L86.04 104.96L146.64 0Z"/></svg></div>
        <h1 class="install-title">Install Kohevo</h1>
        <p class="install-sub">A lean, modular platform for small businesses.</p>
    </div>

    <div class="text-center mb-4">
        <span class="step-pill">
            Step <?= $step ?> of 5
            <span class="step-dots">
                <span class="step-dot is-on"></span>
                <span class="step-dot <?= $step >= 2 ? 'is-on' : '' ?>"></span>
                <span class="step-dot <?= $step >= 3 ? 'is-on' : '' ?>"></span>
                <span class="step-dot <?= $step >= 4 ? 'is-on' : '' ?>"></span>
                <span class="step-dot <?= $step >= 5 ? 'is-on' : '' ?>"></span>
            </span>
        </span>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= $error ?></div>
    <?php endif; ?>

    <div class="install-card">
    <?php if ($step === 1): ?>
        <h2 style="margin-bottom:var(--space-4)">Database &amp; site URL</h2>
        <form method="post">
            <div class="field">
                <label class="field-label" for="app_url">
                    Application URL <span class="field-required">*</span>
                </label>
                <input type="url" id="app_url" name="app_url" required
                       placeholder="https://your-domain.example"
                       value="<?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . rtrim(dirname($_SERVER['PHP_SELF']), '/')) ?>">
                <div class="field-hint">The public URL where Kohevo will be reached. No trailing slash.</div>
            </div>

            <div class="field-row field-row-2">
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_host">Database host</label>
                    <input type="text" id="db_host" name="db_host" value="localhost">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_port">Port <span class="text-muted">optional</span></label>
                    <input type="text" id="db_port" name="db_port" inputmode="numeric" placeholder="3306">
                </div>
            </div>

            <div class="field">
                <label class="field-label" for="db_name">
                    Database name <span class="field-required">*</span>
                </label>
                <input type="text" id="db_name" name="db_name" required autocomplete="off">
            </div>

            <div class="field-row field-row-2">
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_user">
                        Database user <span class="field-required">*</span>
                    </label>
                    <input type="text" id="db_user" name="db_user" required autocomplete="off">
                </div>
                <div class="field" style="margin-bottom:0">
                    <label class="field-label" for="db_pass">Database password</label>
                    <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                Connect &amp; continue →
            </button>
        </form>

    <?php elseif ($step === 2): ?>
        <h2 style="margin-bottom:var(--space-2)">Install the application</h2>
        <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
            This creates the core database schema and this deployment's unique
            installation identity. No account is created yet — that happens
            after your license is activated.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary btn-lg btn-block">
                Install →
            </button>
        </form>

    <?php elseif ($step === 3): ?>
        <h2 style="margin-bottom:var(--space-2)">License key</h2>
        <?php if (!$licensingConfigured): ?>
            <p class="text-muted" style="margin:0;font-size:14px;">
                Licensing is not configured for this build. Contact your provider
                before continuing.
            </p>
        <?php else: ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                Enter the license key you received when you purchased Kohevo. It
                will be validated against the licensing server before setup
                continues.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="field-label" for="license_key">
                        License key <span class="field-required">*</span>
                    </label>
                    <input type="text" id="license_key" name="license_key" required autofocus
                           autocomplete="off" spellcheck="false">
                </div>
                <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                    Validate &amp; activate →
                </button>
            </form>
        <?php endif; ?>

    <?php elseif ($step === 4): ?>
        <h2 style="margin-bottom:var(--space-2)">Create your admin account</h2>
        <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
            Your license is active. Create the first administrator account to
            finish setting up Kohevo.
        </p>
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label class="field-label" for="name">
                    Your name <span class="field-required">*</span>
                </label>
                <input type="text" id="name" name="name" required autofocus>
            </div>

            <div class="field">
                <label class="field-label" for="email">
                    Email <span class="field-required">*</span>
                </label>
                <input type="email" id="email" name="email" required
                       autocomplete="username" inputmode="email">
            </div>

            <div class="field">
                <label class="field-label" for="password">
                    Password <span class="field-required">*</span>
                </label>
                <input type="password" id="password" name="password" required minlength="8"
                       autocomplete="new-password">
                <div class="field-hint">At least 8 characters.</div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg btn-block mt-3">
                Create account →
            </button>
        </form>

    <?php elseif ($step === 5): ?>
        <h2 style="margin-bottom:var(--space-2)">Finish setup</h2>

        <?php if ($finishSummary !== null): ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                Some included modules couldn't be activated automatically. This
                won't block finishing setup — retry them anytime from
                <strong>Admin → Plugins</strong>.
            </p>
            <ul class="kv-list" style="margin-bottom:var(--space-4);">
                <?php foreach ($finishSummary as $r): ?>
                    <li class="kv-row">
                        <span class="kv-label"><?= htmlspecialchars($r['name']) ?></span>
                        <span class="kv-value" style="text-align:right;">
                            <?php if ($r['ok']): ?>
                                <span style="color:var(--success,#16A34A);font-weight:600;">Activated</span>
                            <?php else: ?>
                                <span style="color:var(--danger,#DC2626);font-weight:600;">Failed</span>
                                <div class="text-muted" style="font-size:12px;"><?= htmlspecialchars((string)$r['error']) ?></div>
                            <?php endif; ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="finish_anyway">
                <button type="submit" class="btn btn-primary btn-lg btn-block">Continue to admin login →</button>
            </form>
        <?php else: ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                Your license is active<?= $licenseSummary && !empty($licenseSummary['plan']) ? ' — plan ' . htmlspecialchars((string)$licenseSummary['plan']) : '' ?>
                and your admin account is ready. Any modules included in your
                license will be activated automatically when you finish.
            </p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="finish">
                <button type="submit" class="btn btn-primary btn-lg btn-block">Finish →</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
