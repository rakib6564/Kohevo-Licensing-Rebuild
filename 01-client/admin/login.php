<?php
/**
 * Slate — admin login.
 */
require_once dirname(__DIR__) . '/config.php';

// If already logged in, send straight to dashboard
if (Auth::check()) {
    header('Location: ' . SLATE_URL . '/admin/');
    exit;
}

$error             = '';
$installed         = isset($_GET['installed']);
$installedPlugins  = max(0, (int)($_GET['plugins'] ?? 0));
$next              = $_GET['next'] ?? (SLATE_URL . '/admin/');
$mfaPending = Auth::mfaPending();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = __('csrf_failed', 'Security check failed. Please try again.');
    } elseif ($mfaPending) {
        $code = trim((string)($_POST['mfa_code'] ?? ''));
        $recovery = trim((string)($_POST['recovery_code'] ?? ''));
        if (Auth::completeMfa($code, $recovery)) {
            AuditLog::record('login.mfa_success', Auth::user()['email']);
            $target = slate_safe_redirect_target($next, SLATE_URL . '/admin/');
            header('Location: ' . $target);
            exit;
        }
        $error = __('login_mfa_invalid', 'That verification code was invalid or has already been used.');
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        if ($email === '' || $password === '') {
            $error = __('login_required', 'Email and password are required.');
        } elseif (!Auth::attemptLogin($email, $password)) {
            if (Auth::lastLoginNeedsMfa()) {
                $mfaPending = true;
            } elseif (Auth::lastLoginWasThrottled()) {
                $error = __('login_throttled', 'Too many login attempts. Please wait a few minutes and try again.');
                AuditLog::record('login.throttled', $email);
            } else {
                $error = __('login_invalid', 'Invalid email or password.');
                AuditLog::record('login.failed', $email);
            }
        } else {
            AuditLog::record('login.success', Auth::user()['email']);
            $target = slate_safe_redirect_target($next, SLATE_URL . '/admin/');
            header('Location: ' . $target);
            exit;
        }
    }
}

$siteName  = Database::setting('site_name') ?: 'Kohevo';
$brandSub  = Database::setting('brand_sublabel') ?: __('admin_login', 'Admin login');

// ── Dynamic branding (admin-managed via Settings → Branding) ──
$accent = preg_match('/^#[0-9a-fA-F]{6}$/', (string)Database::setting('brand_accent_color'))
    ? Database::setting('brand_accent_color') : '#2563EB';

$logoUrls = slate_logo_urls();
$logoUrl  = $logoUrls['light'];

$heroPath = (string)Database::setting('brand_login_image_path');
$heroUrl  = $heroPath !== '' ? SLATE_URL . '/' . ltrim($heroPath, '/') : '';

$tagline  = trim((string)Database::setting('brand_login_tagline'));

$siteHome = rtrim(SLATE_URL, '/');
// Drop a trailing /admin so "Back to website" points at the public root.
$siteHome = preg_replace('#/admin/?$#', '', $siteHome) ?: SLATE_URL;

$initial  = e(mb_strtoupper(mb_substr($siteName, 0, 1)));
?>
<!DOCTYPE html>
<html lang="<?= e(I18n::currentLocale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0E1117">
    <title><?= __('login', 'Log in') ?> — <?= e($siteName) ?></title>
    <link rel="icon" href="<?= e(slate_favicon_url()) ?>">
    <?php slate_ui_emit_css(); ?>
    <?php require SLATE_ROOT . '/includes/a11y_head.php'; ?>
    <style>
    /* ── Dynamic brand accent (admin-chosen). Overrides the theme default
          so buttons, focus rings and accents track the configured color. ── */
    :root {
        --accent:       <?= e($accent) ?>;
        --accent-deep:  color-mix(in srgb, <?= e($accent) ?> 82%, #000);
        --accent-hover: color-mix(in srgb, <?= e($accent) ?> 82%, #000);
        --on-accent:    #FFFFFF;
        --ring:         color-mix(in srgb, <?= e($accent) ?> 24%, transparent);
    }

    html, body { height: 100%; }
    body { background: #0E1117; }

    /* ── Split layout ─────────────────────────────────────── */
    .auth-split {
        display: grid;
        grid-template-columns: 1.05fr 1fr;
        min-height: 100vh;
        min-height: 100dvh;
    }

    /* ── Left: brand / image panel ────────────────────────── */
    .auth-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        padding: 44px 44px 48px;
        color: #fff;
        background: linear-gradient(160deg,
            color-mix(in srgb, var(--accent) 92%, #0E1117),
            color-mix(in srgb, var(--accent) 40%, #0E1117) 55%,
            #0E1117 100%);
    }
    <?php if ($heroUrl !== ''): ?>
    .auth-hero {
        background-image: url("<?= e($heroUrl) ?>");
        background-size: cover;
        background-position: center;
    }
    <?php endif; ?>
    /* Fine dot-grid texture — a quiet, premium editorial detail, echoing
       the same device the admin dashboard hero already uses (.dash-hero::before
       in admin/index.php). Fades in toward the tagline. */
    .auth-hero::before {
        content: "";
        position: absolute; inset: 0; pointer-events: none; opacity: .35;
        background-image: radial-gradient(rgba(255,255,255,0.35) 1px, transparent 1.5px);
        background-size: 22px 22px;
        -webkit-mask-image: linear-gradient(180deg, transparent 0%, #000 62%);
                mask-image: linear-gradient(180deg, transparent 0%, #000 62%);
    }
    /* Cinematic scrim — legible over any photo, with a soft sheen top-right
       for depth instead of a flat dark wash. */
    .auth-hero::after {
        content: "";
        position: absolute; inset: 0;
        background:
            radial-gradient(120% 90% at 100% 0%, rgba(255,255,255,0.10), transparent 55%),
            linear-gradient(195deg,
                rgba(6,8,12,0.20) 0%,
                rgba(6,8,12,0.10) 30%,
                rgba(6,8,12,0.78) 100%);
        pointer-events: none;
    }
    .auth-hero-top,
    .auth-hero-bottom { position: relative; z-index: 1; }
    .auth-hero-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
    }
    .auth-back {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 9px 16px;
        border-radius: 999px;
        background: rgba(255,255,255,0.14);
        border: 1px solid rgba(255,255,255,0.22);
        backdrop-filter: blur(10px) saturate(180%);
        -webkit-backdrop-filter: blur(10px) saturate(180%);
        box-shadow: inset 0 1px 0 rgba(255,255,255,0.22);
        color: #fff;
        text-decoration: none;
        font-size: 13px;
        font-weight: 600;
        transition: background 0.15s ease;
        white-space: nowrap;
    }
    .auth-back:hover { background: rgba(255,255,255,0.26); color: #fff; text-decoration: none; }
    .auth-hero-tagline {
        margin: 0;
        max-width: 460px;
        font-family: var(--font-display);
        font-size: clamp(26px, 3vw, 38px);
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -0.02em;
        text-shadow: 0 2px 18px rgba(0,0,0,0.35);
    }
    .auth-hero-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        font-family: var(--font-mono);
        font-size: 11px;
        letter-spacing: 0.22em;
        text-transform: uppercase;
        color: rgba(255,255,255,0.65);
        margin: 0 0 14px;
    }
    .auth-hero-eyebrow::before { content: ""; width: 20px; height: 1px; background: rgba(255,255,255,0.55); }
    .auth-hero-rule {
        display: block;
        width: 56px; height: 3px;
        border-radius: 2px;
        margin-top: 20px;
        background: linear-gradient(90deg, rgba(255,255,255,0.9), rgba(255,255,255,0));
    }

    /* ── Right: form panel ────────────────────────────────── */
    .auth-form-panel {
        position: relative;
        overflow: hidden;
        background:
            radial-gradient(1100px 620px at 85% -10%, color-mix(in srgb, var(--accent) 10%, transparent), transparent 60%),
            radial-gradient(900px 600px at -10% 110%, color-mix(in srgb, var(--accent) 7%, transparent), transparent 55%),
            var(--bg);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px;
    }
    /* Faint echo of the hero's dot-grid, centered behind the glass card. */
    .auth-form-panel::before {
        content: "";
        position: absolute; inset: 0; pointer-events: none; opacity: .6;
        background-image: radial-gradient(color-mix(in srgb, var(--text) 7%, transparent) 1px, transparent 1.5px);
        background-size: 26px 26px;
        -webkit-mask-image: radial-gradient(60% 55% at 50% 42%, #000 0%, transparent 75%);
                mask-image: radial-gradient(60% 55% at 50% 42%, #000 0%, transparent 75%);
    }
    .auth-form-inner {
        position: relative;
        z-index: 1;
        width: 100%;
        max-width: 380px;
        background: var(--glass-bg-strong);
        -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(180%);
        backdrop-filter: blur(var(--glass-blur)) saturate(180%);
        border: 1px solid var(--glass-border);
        border-radius: var(--radius-2xl);
        box-shadow: var(--glass-shadow-lg);
        padding: 40px 36px 32px;
    }
    .auth-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-family: var(--font-mono);
        font-size: 11px;
        letter-spacing: 0.18em;
        text-transform: uppercase;
        color: var(--accent-deep);
        font-weight: 600;
        margin: 0 0 14px;
    }
    .auth-eyebrow::before { content: ""; width: 18px; height: 1px; background: var(--accent); opacity: .8; }
    .auth-form-inner .field input {
        background: color-mix(in srgb, var(--surface) 68%, transparent);
        border-radius: var(--radius);
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .auth-form-inner .field input:hover { border-color: var(--border-stronger); }
    .auth-form-inner .field input:focus {
        outline: none;
        border-color: var(--accent);
        background: var(--surface);
        box-shadow: 0 0 0 4px var(--ring);
    }
    .auth-form-inner .btn-primary {
        background: linear-gradient(135deg, var(--accent), var(--accent-deep));
        border: none;
        box-shadow: 0 10px 26px color-mix(in srgb, var(--accent) 38%, transparent),
                    inset 0 1px 0 rgba(255,255,255,0.25);
        transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
    }
    .auth-form-inner .btn-primary:hover {
        transform: translateY(-1px);
        box-shadow: 0 14px 32px color-mix(in srgb, var(--accent) 42%, transparent),
                    inset 0 1px 0 rgba(255,255,255,0.3);
        filter: brightness(1.03);
    }
    .auth-form-inner .btn-primary:active { transform: translateY(0); }
    .auth-logo {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 28px;
    }
    .auth-logo img { max-height: 44px; max-width: 200px; width: auto; display: block; }
    .auth-logo-mark {
        width: 42px; height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, var(--accent), var(--accent-deep));
        color: var(--on-accent);
        display: grid; place-items: center;
        font-size: 19px; font-weight: 700;
        letter-spacing: -0.02em;
        box-shadow: 0 8px 20px color-mix(in srgb, var(--accent) 35%, transparent);
    }
    .auth-logo-name {
        font-family: var(--font-display);
        font-size: 18px; font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--text);
    }
    .auth-title {
        font-family: var(--font-display);
        font-size: 30px;
        font-weight: 700;
        letter-spacing: -0.03em;
        margin: 0;
        color: var(--text);
    }
    .auth-sub {
        color: var(--muted);
        margin: 8px 0 28px;
        font-size: 14.5px;
    }
    .auth-credit {
        margin: 30px 0 0;
        padding-top: 18px;
        border-top: 1px solid var(--border, #e5e7eb);
        text-align: center;
        font-size: 12px;
        color: var(--muted, #6b7280);
    }
    .auth-credit a { color: var(--accent); font-weight: 600; text-decoration: none; }
    .auth-credit a:hover { text-decoration: underline; }
    /* ── Platform signature (Kohevo) — smaller and separate from .auth-credit
       above it, so the developer attribution and the platform identity read as
       two distinct things, not one merged line. */
    .auth-platform-signature {
        margin: 10px 0 0;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        font-size: 11px;
        font-weight: 600;
        color: var(--muted, #6b7280);
    }
    .auth-platform-signature img { display: block; height: 14px; width: auto; }
    .auth-pass-wrap { position: relative; }
    .auth-pass-wrap input { padding-right: 44px; }
    .auth-pass-toggle {
        position: absolute;
        top: 50%; right: 8px;
        transform: translateY(-50%);
        width: 32px; height: 32px;
        border: 0; background: transparent;
        color: var(--muted);
        display: grid; place-items: center;
        cursor: pointer;
        border-radius: var(--radius-sm);
    }
    .auth-pass-toggle:hover { color: var(--text); background: var(--surface-2); }

    @media (max-width: 860px) {
        .auth-split { grid-template-columns: 1fr; }
        .auth-hero {
            min-height: 220px;
            padding: 24px;
        }
        .auth-hero-tagline { font-size: 22px; }
        .auth-form-panel { padding: 32px 22px; }
        .auth-form-inner { padding: 30px 24px 26px; }
    }
    @media (max-width: 520px) {
        .auth-hero-tagline { display: none; }
        .auth-hero { min-height: 150px; }
    }
    </style>
</head>
<body>
<div class="auth-split">

    <!-- ── Brand / image panel ── -->
    <aside class="auth-hero" aria-hidden="true">
        <div class="auth-hero-top">
            <!-- The tenant logo already renders once in the form card
                 below (.auth-logo) -- this panel no longer repeats it. -->
            <a href="<?= e($siteHome) ?>/" class="auth-back">
                <?= __('back_to_website', 'Back to website') ?>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>
                </svg>
            </a>
        </div>
        <div class="auth-hero-bottom">
            <span class="auth-hero-eyebrow"><?= e($brandSub) ?></span>
            <?php if ($tagline !== ''): ?>
                <p class="auth-hero-tagline"><?= nl2br(e($tagline)) ?></p>
            <?php endif; ?>
            <span class="auth-hero-rule" aria-hidden="true"></span>
        </div>
    </aside>

    <!-- ── Form panel ── -->
    <main class="auth-form-panel">
        <div class="auth-form-inner">
            <div class="auth-logo">
                <?php /* Always the light card behind this logo, regardless of OS theme -- always the light logo variant. */ ?>
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>">
                <?php else: ?>
                    <span class="auth-logo-mark"><?= $initial ?></span>
                    <span class="auth-logo-name"><?= e($siteName) ?></span>
                <?php endif; ?>
            </div>

            <div class="auth-eyebrow"><?= __('admin_secure_access', 'Secure access') ?></div>
            <h1 class="auth-title"><?= __('welcome_back', 'Welcome back') ?></h1>
            <p class="auth-sub">
                <?= __('admin_login_sub', 'Log in to your dashboard.') ?>
            </p>

            <?php if ($installed): ?>
                <div class="alert alert-success" role="status">
                    <?= __('install_success', 'Kohevo is installed. Log in with the account you just created.') ?>
                    <?php if ($installedPlugins > 0): ?>
                        <?= ' ' . sprintf(__('install_plugins_activated', '%d plugin(s) activated.'), $installedPlugins) ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-error" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

                        <?php if ($mfaPending): ?>
                <div class="alert alert-success" role="status">A verification step is required to finish signing in.</div>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="field-label" for="mfa_code">Verification code</label>
                        <input type="text" id="mfa_code" name="mfa_code" required autofocus
                               inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6">
                    </div>
                    <div class="field">
                        <label class="field-label" for="recovery_code">Recovery code <span class="text-muted">(optional)</span></label>
                        <input type="text" id="recovery_code" name="recovery_code" autocomplete="off">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-2">Verify and continue</button>
                </form>
            <?php else: ?>
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="field-label" for="email"><?= __('email', 'Email') ?></label>
                        <input type="email" id="email" name="email" required autofocus
                               value="<?= e($_POST['email'] ?? '') ?>"
                               autocomplete="username" inputmode="email"
                               placeholder="<?= __('email', 'Email') ?>">
                    </div>
                    <div class="field">
                        <label class="field-label" for="password"><?= __('password', 'Password') ?></label>
                        <div class="auth-pass-wrap">
                            <input type="password" id="password" name="password" required
                                   autocomplete="current-password"
                                   placeholder="<?= __('password', 'Password') ?>">
                            <button type="button" class="auth-pass-toggle" id="auth-pass-toggle"
                                    aria-label="<?= __('show_password', 'Show password') ?>">
                                <svg id="auth-eye" width="19" height="19" viewBox="0 0 24 24" fill="none"
                                     stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                     stroke-linejoin="round" aria-hidden="true">
                                    <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block mt-2">
                        <?= __('login', 'Log in') ?>
                    </button>
                </form>
            <?php endif; ?>


            <?php
            $loginOwner    = Database::setting('brand_owner')    ?: 'Rakib Hasan';
            $loginOwnerUrl = Database::setting('brand_owner_url') ?: 'https://rakibhasaan.com';
            if (!preg_match('#^https?://#i', $loginOwnerUrl)) $loginOwnerUrl = 'https://' . ltrim($loginOwnerUrl, '/');
            ?>
            <p class="auth-credit">
                © <?= e(date('Y')) ?> <?= e($siteName) ?> ·
                <?= __('built_by', 'Built &amp; maintained by') ?>
                <a href="<?= e($loginOwnerUrl) ?>" target="_blank" rel="noopener"><?= e($loginOwner) ?></a>
            </p>

            <?php /* Kohevo platform signature — secondary to, and separate from,
                     the "Built & maintained by" developer attribution directly
                     above (that credit is unchanged, not merged with this). Routed
                     entirely through PlatformSignature/PlatformIdentity; this file
                     never names a platform asset path or the literal platform name.
                     MODE_SIGNATURE ("Powered by Kohevo") is the mode its own
                     docblock names for this exact surface. Always the light card
                     behind this row (same as .auth-logo above), so no tile/dark
                     variant handling is needed here. */ ?>
            <p class="auth-platform-signature">
                <?= \Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_SIGNATURE) ?>
            </p>
        </div>
    </main>
</div>

<script>
(function () {
    var btn = document.getElementById('auth-pass-toggle');
    var pwd = document.getElementById('password');
    var eye = document.getElementById('auth-eye');
    if (!btn || !pwd) return;
    var OPEN = '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/>';
    var SHUT = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    btn.addEventListener('click', function () {
        var show = pwd.type === 'password';
        pwd.type = show ? 'text' : 'password';
        eye.innerHTML = show ? SHUT : OPEN;
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
})();
</script>
</body>
</html>
