<?php
/**
 * Slate — shared error-page renderer.
 *
 * Used by 404.php / 403.php / 500.php at the root and by PublicRouter for
 * unmatched public routes. Renders a branded, full-screen page that matches
 * the landing page: the Branding login image as the background, a frosted
 * glass card, the business logo and accent colour.
 *
 * Branding is pulled from the DB ONLY when it's safely available — every DB
 * read is wrapped so a 500 caused by a database outage still renders (it just
 * falls back to an accent gradient instead of the hero image). Self-contained:
 * no template engine, no plugin code.
 *
 *   slate_render_error(404, 'Not found', 'We couldn\'t find that page.');
 */
if (!function_exists('slate_render_error')) {
    function slate_render_error(int $status, string $title, string $message): void {
        @http_response_code($status);
        $e = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        // Translate the English title/message/labels when the i18n layer is up. On a
        // hard failure (database down, i18n not loaded) fall back to the English as given,
        // so an error page can never itself fail because of translation.
        $tr = static function (string $s): string {
            if ($s === '' || !function_exists('__')) return $s;
            try {
                $key = 'err_' . substr(trim((string)preg_replace('/[^a-z0-9]+/', '_', strtolower($s)), '_'), 0, 60);
                return (string)__($key, $s);
            } catch (\Throwable $ignored) {
                return $s;
            }
        };
        try {
            $pageLang = class_exists('I18n') ? (string)I18n::currentLocale() : 'en';
        } catch (\Throwable $ignored) {
            $pageLang = 'en';
        }
        $title   = $tr($title);
        $message = $tr($message);

        $home    = defined('SLATE_URL') ? rtrim((string)SLATE_URL, '/') : '';
        $accent  = '#111111';
        $hero = $logo = $logoDark = $biz = $siteUrl = '';

        // Best-effort branding — never let it break the error page itself.
        try {
            if (class_exists('Database')) {
                $a = (string)Database::setting('brand_accent_color');
                if (preg_match('/^#[0-9a-fA-F]{6}$/', $a)) $accent = $a;
                $hp = (string)Database::setting('brand_login_image_path');
                if ($hp !== '' && $home !== '') $hero = $home . '/' . ltrim($hp, '/');
                $lp = (string)Database::setting('brand_logo_path');
                if ($lp !== '' && $home !== '') $logo = $home . '/' . ltrim($lp, '/');
                $ldp = (string)Database::setting('brand_logo_dark_path');
                if ($ldp !== '' && $home !== '') $logoDark = $home . '/' . ltrim($ldp, '/');
                $biz     = (string)(Database::setting('business_name') ?: Database::setting('site_name'));
                $siteUrl = (string)Database::setting('landing_website_url');
            }
        } catch (\Throwable $ignored) {
            // DB unavailable (e.g. a real 500) — gradient fallback below.
        }

        // Platform identity (Kohevo) — deliberately NOT inside the try/catch
        // above: PlatformIdentity/PlatformSignature read no setting and touch
        // no database, so tenant branding failing must never affect them (see
        // docs/09-Roadmap/phase-kohevo-identity-p4.md §7). class_exists(),
        // matching this file's own existing Database guard just above, covers
        // the one real dependency: the Slate\ autoloader must have registered
        // class loading (true for every current caller — 404.php/403.php via
        // config.php, 500.php via its own minimal bootstrap, PublicRouter and
        // the forms plugin via their normal full boot).
        $platformSignature = '';
        if (class_exists('\Slate\Services\Content\PlatformSignature')) {
            $platformSignature = \Slate\Services\Content\PlatformSignature::render(
                \Slate\Services\Content\PlatformSignature::MODE_SIGNATURE
            );
        }

        $bg = $hero !== ''
            ? 'background:url("' . $e($hero) . '") center/cover no-repeat;'
            : 'background:radial-gradient(120% 80% at 50% -10%, color-mix(in srgb, var(--accent) 45%, #0E1117), #0E1117);';
        // Self-contained (no template engine, no plugin code, may run
        // before helpers.php loads — see the 500.php minimal bootstrap),
        // so the default fallback is inlined rather than calling
        // slate_favicon_url().
        $favicon = $logo !== '' ? $logo : ($home !== '' ? $home . '/assets/img/kohevo-favicon.ico' : '');
        ?>
<!doctype html>
<html lang="<?= $e($pageLang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $status ?> · <?= $e($title) ?></title>
<?php if ($favicon !== ''): ?><link rel="icon" href="<?= $e($favicon) ?>"><?php endif; ?>
<style>
    :root { --accent: <?= $e($accent) ?>; }
    * { box-sizing: border-box; }
    html, body { margin: 0; height: 100%; }
    body {
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        min-height: 100vh; min-height: 100dvh; color: #fff;
        display: grid; place-items: center; padding: 24px; position: relative; background: #0E1117;
    }
    .bg { position: fixed; inset: 0; z-index: -2; <?= $bg ?> }
    .bg::after { content: ""; position: absolute; inset: 0;
        background:
            linear-gradient(180deg, rgba(8,18,28,.74) 0%, rgba(8,18,28,.6) 45%, rgba(8,18,28,.9) 100%),
            radial-gradient(90% 60% at 50% 0%, color-mix(in srgb, var(--accent) 30%, transparent), transparent 70%);
    }
    .card {
        width: 100%; max-width: 460px; text-align: center; padding: 40px 34px 34px;
        background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.16); border-radius: 18px;
        -webkit-backdrop-filter: blur(16px) saturate(140%); backdrop-filter: blur(16px) saturate(140%);
        box-shadow: 0 22px 60px -22px rgba(0,0,0,.6);
    }
    .logo { height: 46px; width: auto; margin: 0 auto 20px; display: block; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
    .code {
        display: inline-block; font-family: ui-monospace, "SF Mono", Menlo, monospace;
        font-size: 12px; letter-spacing: .14em; font-weight: 700; text-transform: uppercase; color: var(--accent);
        background: color-mix(in srgb, var(--accent) 16%, transparent);
        border: 1px solid color-mix(in srgb, var(--accent) 45%, transparent);
        padding: 5px 12px; border-radius: 999px; margin-bottom: 16px;
    }
    h1 { margin: 0 0 10px; font-size: 27px; font-weight: 800; letter-spacing: -.02em; text-shadow: 0 2px 18px rgba(0,0,0,.3); }
    p { margin: 0 0 24px; color: rgba(255,255,255,.85); font-size: 14.5px; line-height: 1.55; }
    .actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .btn {
        display: inline-flex; align-items: center; gap: 7px; padding: 11px 18px; border-radius: 11px;
        text-decoration: none; font-weight: 650; font-size: 14px; transition: transform .15s, background .15s, border-color .15s;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary { background: var(--accent); color: #fff; box-shadow: 0 10px 26px -10px var(--accent); }
    .btn-primary:hover { filter: brightness(1.06); }
    .btn-ghost { background: rgba(255,255,255,.10); color: #fff; border: 1px solid rgba(255,255,255,.22); }
    .btn-ghost:hover { background: rgba(255,255,255,.18); }
    .biz { margin-top: 22px; font-size: 12px; letter-spacing: .14em; text-transform: uppercase; color: rgba(255,255,255,.6); }
    /* Platform signature (Kohevo) — secondary to the tenant .biz name above it,
       always dark card, so (like the tenant logo above, which already prefers
       its own dark variant) the mark needs a light tile behind it to stay
       legible — same technique as the Phase 2 admin sidebar's dark themes. */
    .platform-signature {
        margin-top: 14px; display: flex; align-items: center; justify-content: center;
        gap: 6px; font-size: 11px; font-weight: 600; letter-spacing: .04em; color: rgba(255,255,255,.6);
    }
    .platform-signature img { display: block; height: 14px; width: auto; background: #fff; border-radius: 4px; padding: 2px 3px; }
</style>
</head>
<body>
    <div class="bg"></div>
    <div class="card">
        <?php
        // Always a dark hero photo or gradient background (this page's own
        // fixed design), not the visitor's OS theme, so it always wants the
        // dark-mode logo variant when set.
        $errLogoUrl = $logoDark !== '' ? $logoDark : $logo;
        ?>
        <?php if ($errLogoUrl !== ''): ?><img class="logo" src="<?= $e($errLogoUrl) ?>" alt="<?= $e($biz) ?>"><?php endif; ?>
        <div class="code"><?= $e($tr('Error')) ?> <?= $status ?></div>
        <h1><?= $e($title) ?></h1>
        <p><?= $e($message) ?></p>
        <div class="actions">
            <a class="btn btn-primary" href="<?= $e($home) ?>/">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>
                <?= $e($tr('Return home')) ?>
            </a>
            <?php if ($siteUrl !== ''): ?>
            <a class="btn btn-ghost" href="<?= $e($siteUrl) ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg>
                <?= $e($tr('Back to website')) ?>
            </a>
            <?php endif; ?>
        </div>
        <?php if ($biz !== ''): ?><div class="biz"><?= $e($biz) ?></div><?php endif; ?>
        <?php // Always rendered, independent of $biz/tenant branding above — the
              // platform identity must remain visible even when tenant branding
              // is unavailable (a DB outage, an unbranded tenant, etc.). ?>
        <?php if ($platformSignature !== ''): ?><div class="platform-signature"><?= $platformSignature ?></div><?php endif; ?>
    </div>
</body>
</html>
        <?php
    }
}

if (!function_exists('slate_maintenance_gate')) {
    /**
     * Public-side maintenance gate. When maintenance mode is enabled, shows the
     * branded 503 page to visitors; a logged-in admin passes through so they can
     * keep working. Best-effort — never blocks if the DB/Auth aren't available.
     * Call this at the top of public entry points (public.php, root index.php).
     */
    function slate_maintenance_gate(): void {
        try {
            if (!class_exists('Database') || Database::setting('maintenance_mode') !== '1') return;
            if (class_exists('Auth') && Auth::check()) return; // admin is logged in → allow through
        } catch (\Throwable $e) {
            return; // not installed / DB unavailable → don't block
        }
        @http_response_code(503);
        @header('Retry-After: 3600');
        slate_render_error(503, 'Down for maintenance',
            "We're making some improvements and will be back shortly. Thanks for your patience.");
        exit;
    }
}

if (!function_exists('slate_license_gate')) {
    /**
     * Public-side remote-license gate (Phase 5 of the remote license
     * server build — see plugins/licensing/ and
     * Slate\Services\Licensing\SlateLicenseCacheStore, which this reads).
     *
     * An install that has never configured remote licensing has no cache
     * row at all and is completely unaffected — matches the existing
     * "no license row = unrestricted" convention already used by the
     * local LicenseService/EntitlementService. Only an explicit, verified,
     * remote-confirmed bad status restricts anything; going stale (no
     * successful check-in past the grace period) restricts too, but
     * silence itself is never treated as "suspended" — see
     * RemoteLicenseClient, which never writes a failed check-in into this
     * same cache.
     *
     * A logged-in admin always passes through, same as the maintenance
     * gate above — restricting must never lock the one person who can
     * actually act on it (contact the provider, etc.) out of their own
     * install.
     */
    function slate_license_gate(): void {
        $graceSeconds = 7 * 86400;
        try {
            if (!class_exists('\Slate\Services\Licensing\SlateLicenseCacheStore')) return;
            $store = new \Slate\Services\Licensing\SlateLicenseCacheStore(current_tenant_id());

            // QA Fix Round 1 (Phase 4, Fix 1/Fix 5): readTrustState()
            // distinguishes "no cache row at all" from "a cache row exists
            // but failed installation-identity verification" -- kept in
            // parity with the 01-client copy of this gate so an untrusted
            // row is never misread as "never configured".
            $state = $store->readTrustState();
            if (!$state['found']) return; // never configured -- unrestricted
            if (!$state['trusted']) {
                $restricted = true; // an active security signal, never "unconfigured"
            } else {
                $cached = $state['data'];
                $licensedStatuses = ['trial', 'active'];
                $restricted = !in_array($cached['status'], $licensedStatuses, true);

                if (!$restricted) {
                    $fetchedAt = strtotime((string) $cached['fetched_at']);
                    $restricted = $fetchedAt === false || (time() - $fetchedAt) > $graceSeconds;
                }
            }
            if (!$restricted) return;

            if (class_exists('Auth') && Auth::check()) return; // admin passes through
        } catch (\Throwable $e) {
            return; // DB/table unavailable -- fail OPEN, never block on infra trouble
        }
        @http_response_code(403);
        slate_render_error(403, 'License inactive',
            "This installation's license is not currently active. Please contact your provider.");
        exit;
    }
}
