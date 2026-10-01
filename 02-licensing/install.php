<?php
/**
 * Slate — install wizard (minimal, two-step).
 *
 * Step 1: collect DB credentials, verify connection, write .env
 * Step 2: run db/schema.sql, create the first admin user, finish
 *
 * Stops itself once .installed marker exists.
 *
 * The polished 7-step install wizard with requirements check, branding,
 * and plugin picker is Stage 6.
 */

define('SLATE_ROOT', __DIR__);
define('SLATE_VERSION', '1.6.2');
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

// ── Hard requirement: ext-sodium ────────────────────────────
// Licence signatures are Ed25519 only; there is no weaker fallback, so an
// install on PHP without sodium could never trust (or issue) a licence.
if (!function_exists('sodium_crypto_sign_verify_detached')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Kohevo cannot be installed: the PHP sodium extension (ext-sodium) is required for licence signature verification and is not loaded.\n"
       . "Enable it (cPanel: Select PHP Version / MultiPHP -> sodium) and reload this page.\n";
    exit;
}

require __DIR__ . '/includes/helpers.php';

$step  = (int)($_GET['step'] ?? 1);
$error = '';

// Steps 2+ need the DB connection step 1 itself just configured — guard
// against landing here (bookmark, back button) before .env exists rather
// than fataling on connect.
if ($step >= 2) {
    if (!file_exists(SLATE_ROOT . '/.env')) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?step=1');
        exit;
    }
    require __DIR__ . '/config.php';
}
// Step 3 (plugin selection) only makes sense once the admin account from
// step 2 actually exists — otherwise send back rather than let someone
// activate plugins on a DB with no admin able to reach them yet. On a
// truly fresh DB (step 2 never run) the `users` table itself doesn't
// exist — that's the same "not ready" case, not a 500.
if ($step === 3) {
    try {
        $hasAdmin = (int) Database::value('SELECT COUNT(*) FROM users', []) > 0;
    } catch (\Throwable $e) {
        $hasAdmin = false;
    }
    if (!$hasAdmin) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?step=2');
        exit;
    }
}
$pluginsOnDisk = ($step === 3) ? PluginLoader::discoverOnDisk() : [];
$pluginResults = null;

// ── Step 1 submit: write .env, redirect to step 2 ───────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 1) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

    if ($dbPort !== '' && !preg_match('/^\d{1,5}$/', $dbPort)) {
        $error = __('core_err_database_port_must_be_numeric', 'Database port must be numeric.');
    } elseif ($dbName === '' || $dbUser === '' || $appUrl === '') {
        $error = __('core_err_please_fill_in_all_required_fields', 'Please fill in all required fields.');
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
            $envBody .= "TENANT_ID=1\n";
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
}

// ── Step 2 submit: run schema, create admin, go to step 3 ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || strlen($password) < 8) {
        $error = __('core_err_name_email_and_a_password_of_at_least_8_characters_are_r', 'Name, email, and a password of at least 8 characters are required.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = __('core_err_invalid_email_address', 'Invalid email address.');
    } else {
        try {
            // Core schema + the identity spine (contacts/identities — customer
            // login depends on these; see db/migrations/0002_identity_core.php)
            // + login-attempt throttling, applied and ledger-recorded by name.
            // Deliberately NOT a plain migrate(): that would also pick up
            // 0003's seed (gated behind its own pre-apply review), and any
            // product-specific migration (Studio, content-builder) that
            // happens to be pending — neither belongs in a generic install.
            $runner = new \Slate\Data\MigrationRunner(Database::get(), SLATE_ROOT . '/db/migrations');
            $runner->migrate(['0001_core_init', '0002_identity_core', '0011_login_attempts']);

            Database::insert('users', [
                'tenant_id'     => 1,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'name'          => $name,
                'role_id'       => 1,
                'status'        => 'active',
            ]);

            header('Location: ' . $_SERVER['PHP_SELF'] . '?step=3');
            exit;
        } catch (\Throwable $e) {
            $error = 'Installation failed: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// ── Step 3 submit: activate selected plugins, then write the marker ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 3) {
    $action = (string)($_POST['_action'] ?? 'apply');

    if ($action === 'finish_anyway') {
        // Reached only after a partial-failure results screen the user has
        // already seen — finish setup regardless; failed plugins are just
        // never-activated, nothing was left half-applied.
        file_put_contents($installMarker, "Installed: " . date('Y-m-d H:i:s') . " | Kohevo " . SLATE_VERSION . "\n");
        @chmod($installMarker, 0640);
        header('Location: ' . SLATE_URL . '/admin/login.php?installed=1');
        exit;
    }

    // Never trust posted slugs directly — only activate what's genuinely
    // on disk with a valid manifest, per discoverOnDisk() itself.
    $discovered = array_column($pluginsOnDisk, null, 'slug');
    $selected   = ($action === 'skip') ? [] : array_values(array_intersect(
        array_map('strval', (array)($_POST['plugins'] ?? [])),
        array_keys($discovered)
    ));

    $pluginResults = [];
    foreach ($selected as $slug) {
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
        $okCount = count($pluginResults);
        header('Location: ' . SLATE_URL . '/admin/login.php?installed=1' . ($okCount ? '&plugins=' . $okCount : ''));
        exit;
    }
    // Else fall through and re-render step 3 below with $pluginResults —
    // shows what succeeded/failed and offers "Continue anyway". The marker
    // is deliberately not written until the user has seen that.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1117">
    <title>Install Kohevo</title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
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
        background: var(--accent, #111111);
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--on-accent, #fff);
        font-size: 30px;
        font-weight: 700;
        box-shadow: var(--glow-accent), inset 0 1px 0 rgba(255,255,255,.2);
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
            Step <?= $step ?> of 3
            <span class="step-dots">
                <span class="step-dot is-on"></span>
                <span class="step-dot <?= $step >= 2 ? 'is-on' : '' ?>"></span>
                <span class="step-dot <?= $step >= 3 ? 'is-on' : '' ?>"></span>
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
        <h2 style="margin-bottom:var(--space-4)">Create your admin account</h2>
        <form method="post">
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
                Install Kohevo →
            </button>
        </form>

    <?php elseif ($step === 3): ?>
        <h2 style="margin-bottom:var(--space-2)">Choose your plugins</h2>

        <?php if ($pluginResults !== null): ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                Some plugins couldn't be activated automatically. This won't block finishing setup — retry
                them anytime from <strong>Admin → Plugins</strong>.
            </p>
            <ul class="kv-list" style="margin-bottom:var(--space-4);">
                <?php foreach ($pluginResults as $r): ?>
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
                <input type="hidden" name="_action" value="finish_anyway">
                <button type="submit" class="btn btn-primary btn-lg btn-block">Continue to admin login →</button>
            </form>

        <?php elseif (!$pluginsOnDisk): ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                No plugins found under <code>plugins/</code>. You can add and activate them anytime from
                Admin → Plugins later.
            </p>
            <form method="post">
                <input type="hidden" name="_action" value="skip">
                <button type="submit" class="btn btn-primary btn-lg btn-block">Finish →</button>
            </form>

        <?php else: ?>
            <p class="text-muted" style="margin:0 0 var(--space-4);font-size:14px;">
                Everything found in <code>plugins/</code> is listed below, pre-selected. Uncheck anything
                you don't want active yet — you can always change this later from Admin → Plugins.
            </p>
            <form method="post">
                <div class="mb-4" style="display:grid;gap:10px;">
                    <?php foreach ($pluginsOnDisk as $p): ?>
                        <label style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--border,rgba(0,0,0,.08));border-radius:var(--radius);cursor:pointer;">
                            <input type="checkbox" name="plugins[]" value="<?= htmlspecialchars($p['slug']) ?>" checked style="margin-top:3px;">
                            <span>
                                <strong style="display:block;">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <span class="text-muted" style="font-weight:400;">v<?= htmlspecialchars($p['version']) ?></span>
                                </strong>
                                <?php if ($p['description'] !== ''): ?>
                                    <span class="text-muted" style="font-size:12.5px;"><?= htmlspecialchars($p['description']) ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" name="_action" value="apply" class="btn btn-primary btn-lg btn-block">
                    Activate selected &amp; finish →
                </button>
                <button type="submit" name="_action" value="skip" class="btn btn-lg btn-block mt-2">
                    Skip for now
                </button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>
</body>
</html>
