<?php
/**
 * Slate — admin dashboard.
 *
 * Minimal landing page after login. Plugins extend this via the
 * `admin_dashboard_widgets` filter.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();

$pageTitle = __('dashboard', 'Dashboard');
require __DIR__ . '/partials/header.php';

$recent = (Auth::can('audit.view') || Auth::isSuperAdmin())
        ? AuditLog::recent(8)
        : [];

$pluginWidgets = Hook::applyFilters('admin_dashboard_widgets', []);
if (!is_array($pluginWidgets)) $pluginWidgets = [];
$pluginWidgets = array_values(array_filter($pluginWidgets, fn($w) => is_string($w) && trim($w) !== ''));
$user = Auth::user();

// Time-of-day greeting.
$hr    = (int)I18n::localDate('G');
$greet = $hr < 12 ? __('good_morning', 'Good morning')
       : ($hr < 17 ? __('good_afternoon', 'Good afternoon')
       : __('good_evening', 'Good evening'));

// Humanise an audit action code ("forms.updated" → "Forms updated") and pick
// an icon + tone for the feed.
$activityMeta = static function (string $action): array {
    $parts  = explode('.', $action);
    $domain = $parts[0] ?? '';
    $verb   = str_replace('_', ' ', $parts[1] ?? '');
    $label  = trim(ucfirst($domain) . ' ' . $verb);
    $icon = match ($domain) {
        'forms'                => 'clipboard-list',
        'plugins', 'plugin'    => 'box',
        'users', 'user', 'role', 'roles' => 'users',
        'settings'             => 'settings',
        'media'                => 'image',
        'login', 'logout', 'auth' => 'logout',
        default                => 'circle',
    };
    $tone = match ($verb) {
        'created', 'installed', 'activated', 'published' => 'is-success',
        'deleted', 'uninstalled', 'deactivated'         => 'is-danger',
        'updated', 'edited'                             => 'is-info',
        default                                          => '',
    };
    return [$label ?: $action, $icon, $tone];
};
?>

<?php slate_breadcrumbs([['label' => __('dashboard', 'Dashboard')]]); ?>

<style>
/* ════════ Dashboard — premium command-centre. ════════ */

/* ── Hero ─────────────────────────────────────────────── */
.dash-hero {
    position: relative; overflow: hidden;
    border: 1px solid var(--glass-border); border-radius: 20px;
    background:
        radial-gradient(120% 150% at 95% -20%, color-mix(in srgb, var(--accent) 18%, transparent), transparent 52%),
        radial-gradient(90% 130% at -5% 120%, color-mix(in srgb, var(--accent) 9%, transparent), transparent 50%),
        var(--glass-bg-strong);
    -webkit-backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    backdrop-filter: blur(var(--glass-blur)) saturate(165%);
    box-shadow: var(--glass-shadow);
    padding: 30px 32px; margin-bottom: 20px;
    display: flex; align-items: flex-end; justify-content: space-between;
    gap: 24px; flex-wrap: wrap;
}
/* fine dot-grid texture, fading out toward the greeting */
.dash-hero::before {
    content: ""; position: absolute; inset: 0; pointer-events: none; opacity: .5;
    background-image: radial-gradient(color-mix(in srgb, var(--accent) 28%, transparent) 1px, transparent 1.5px);
    background-size: 20px 20px;
    -webkit-mask-image: linear-gradient(120deg, transparent 38%, #000);
            mask-image: linear-gradient(120deg, transparent 38%, #000);
}
/* soft accent glow blob, top-right */
.dash-hero::after {
    content: ""; position: absolute; top: -70px; right: -40px;
    width: 240px; height: 240px; border-radius: 999px; pointer-events: none;
    background: radial-gradient(circle, color-mix(in srgb, var(--accent) 22%, transparent), transparent 70%);
    filter: blur(8px);
}
.dash-hero-main { position: relative; z-index: 1; flex: 1 1 340px; min-width: 0; }
.dash-eyebrow {
    display: inline-flex; align-items: center; gap: 7px;
    font-family: var(--font-mono); font-size: 10.5px; letter-spacing: 0.18em;
    text-transform: uppercase; color: var(--subtle); margin-bottom: 12px;
}
.dash-eyebrow::before {
    content: ""; width: 16px; height: 1px; background: var(--accent); opacity: .7;
}
.dash-title {
    margin: 0; font-family: var(--font-display);
    font-size: 30px; font-weight: 700; letter-spacing: -0.03em; line-height: 1.08;
}
.dash-title .name {
    background: linear-gradient(100deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #8B5CF6));
    -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;
}
.dash-sub { margin: 8px 0 0; color: var(--muted); font-size: 13.5px; }
.dash-hero-side {
    position: relative; z-index: 1;
    display: flex; flex-direction: column; align-items: flex-end; gap: 10px;
}
.dash-status {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 12px; font-weight: 600; color: var(--success);
    background: var(--success-soft); border: 1px solid transparent;
    padding: 6px 12px; border-radius: 999px;
}
.dash-status .dot {
    width: 7px; height: 7px; border-radius: 999px; background: var(--success);
    box-shadow: 0 0 0 0 color-mix(in srgb, var(--success) 60%, transparent);
    animation: dash-pulse 2.4s ease-out infinite;
}
@keyframes dash-pulse {
    0%   { box-shadow: 0 0 0 0 color-mix(in srgb, var(--success) 55%, transparent); }
    70%  { box-shadow: 0 0 0 7px transparent; }
    100% { box-shadow: 0 0 0 0 transparent; }
}
.dash-clock {
    font-family: var(--font-mono); font-size: 12px; color: var(--muted);
    letter-spacing: 0.02em; font-variant-numeric: tabular-nums;
}
.dash-clock .t { color: var(--text); font-weight: 600; }

/* .dash-stat* (KPI cards) now lives in the shared design system —
   includes/ui_components.php — as slate_stat_card()/.dash-stat*, so any
   plugin dashboard can use the same stat-card look. See below for this
   page's own calls. */

/* ── Main + sticky rail layout ─────────────────────────── */
.dash-layout {
    display: grid; grid-template-columns: minmax(0, 1fr) 360px;
    gap: 16px; align-items: start;
}
.dash-main { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.dash-main > .card { margin: 0; }
.dash-rail { position: sticky; top: 80px; display: flex; flex-direction: column; gap: 16px; }

/* ── Quick actions ─────────────────────────────────────── */
.dash-qa { display: flex; flex-direction: column; gap: 8px; }
.dash-qa a {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 12px; border-radius: 12px;
    color: var(--text); font-size: 13.5px; font-weight: 600;
    background: rgba(255,255,255,0.5); border: 1px solid var(--glass-border);
    transition: background .14s ease, border-color .14s ease, transform .14s ease, box-shadow .14s ease;
}
.dash-qa a:hover {
    background: rgba(255,255,255,0.82);
    border-color: color-mix(in srgb, var(--accent) 30%, transparent);
    box-shadow: 0 6px 18px rgba(31,41,75,0.08);
    transform: translateX(2px);
    text-decoration: none;
}
.dash-qa-ico {
    flex: none; width: 38px; height: 38px; border-radius: 11px;
    display: grid; place-items: center; background: var(--accent-soft);
}
.dash-qa-ico svg { width: 18px; height: 18px; color: var(--accent); }
.dash-qa-arr { margin-left: auto; color: var(--subtle); transition: transform .14s ease; }
.dash-qa a:hover .dash-qa-arr { transform: translateX(3px); color: var(--accent); }

/* ── Activity feed ─────────────────────────────────────── */
.dash-feed { list-style: none; margin: 0; padding: 0; }
.dash-feed-item { display: flex; gap: 11px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.dash-feed-item:first-child { padding-top: 0; }
.dash-feed-item:last-child { border-bottom: 0; padding-bottom: 0; }
.dash-feed-ico {
    flex: none; width: 30px; height: 30px; border-radius: 9px;
    display: grid; place-items: center; margin-top: 1px;
    background: var(--surface-2); color: var(--muted); border: 1px solid var(--border);
}
/* color:inherit beats .nav-icon's hard-set sidebar colour so the glyph
   takes the chip's tone colour instead of being invisible on white. */
.dash-feed-ico svg { width: 15px; height: 15px; color: inherit; }
.dash-feed-ico.is-success { background: var(--success-soft); color: #15803D; border-color: transparent; }
.dash-feed-ico.is-danger  { background: var(--danger-soft);  color: #B91C1C; border-color: transparent; }
.dash-feed-ico.is-info    { background: var(--info-soft);    color: #1E40AF; border-color: transparent; }
.dash-feed-body { min-width: 0; flex: 1; }
.dash-feed-title { font-size: 13px; font-weight: 600; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dash-feed-meta { font-size: 11.5px; color: var(--muted); margin-top: 2px; }

@media (max-width: 980px) {
    .dash-layout { grid-template-columns: 1fr; }
    .dash-rail { position: static; }
}
@media (max-width: 720px) {
    .dash-hero { padding: 24px 22px; }
    .dash-hero-side { align-items: flex-start; width: 100%; }
    .dash-title { font-size: 24px; }
}
/* On mobile the topbar already shows "Dashboard", so the single-item
   breadcrumb in the content would just repeat it — hide it here. On desktop
   the breadcrumb relocates into the topbar (this rule doesn't apply). */
@media (max-width: 767px) { main.content .slate-bc { display: none; } }
</style>

<div class="dash-hero">
    <div class="dash-hero-main">
        <div class="dash-eyebrow"><?= __('overview', 'Overview') ?></div>
        <h1 class="dash-title"><?= e($greet) ?>, <span class="name"><?= e(explode(' ', $user['name'])[0]) ?></span>.</h1>
        <p class="dash-sub"><?= __('dashboard_subtitle', 'Here\'s a quick snapshot of your account today.') ?></p>
    </div>
    <div class="dash-hero-side">
        <span class="dash-status"><span class="dot"></span> <?= __('all_systems_operational', 'All systems operational') ?></span>
        <span class="dash-clock" id="dash-clock" data-tz="<?= e(slate_timezone()) ?>" data-time-format="<?= e(slate_time_format()) ?>"><?= e(slate_format_date()) ?> · <span class="t"><?= e(slate_format_time()) ?></span></span>
    </div>
</div>

<?php
/* First-run nudge: email can't be sent until SMTP or OAuth is configured.
   Shown only to settings-admins, only while unconfigured, dismissible per
   browser (14-day cookie). Notifications, password resets and receipts all
   depend on this, so it's worth surfacing on the dashboard. */
$emailConfigured = (string)Database::setting('smtp_host') !== ''
    || (Database::setting('smtp_auth_type') === 'xoauth2'
        && (string)Database::setting('smtp_oauth_refresh_token') !== '');
if (!$emailConfigured && (Auth::can('settings.edit') || Auth::isSuperAdmin())):
?>
<style>
.setup-nudge {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 18px; margin-bottom: 18px;
    background: var(--accent-soft, #F3F4F6);
    border: 1px solid var(--accent, #111111);
    border-radius: var(--radius, 12px);
}
.setup-nudge-ico {
    flex: none; width: 38px; height: 38px; border-radius: 10px;
    display: grid; place-items: center;
    background: var(--accent, #111111); color: #fff;
}
.setup-nudge-ico svg { width: 20px; height: 20px; }
.setup-nudge-body { flex: 1; min-width: 0; }
.setup-nudge-title { font-weight: 650; font-size: 14.5px; color: var(--text); margin-bottom: 2px; }
.setup-nudge-text { font-size: 13px; color: var(--text-2, #475569); line-height: 1.5; }
.setup-nudge-actions { display: flex; align-items: center; gap: 10px; margin-top: 11px; flex-wrap: wrap; }
.setup-nudge-x {
    flex: none; background: none; border: 0; cursor: pointer; color: var(--muted);
    width: 30px; height: 30px; border-radius: 8px; display: grid; place-items: center;
}
.setup-nudge-x:hover { background: rgba(0,0,0,.06); color: var(--text); }
.setup-nudge-x svg { width: 16px; height: 16px; }
</style>
<div class="setup-nudge" id="email-setup-nudge" hidden>
    <span class="setup-nudge-ico">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
    </span>
    <div class="setup-nudge-body">
        <div class="setup-nudge-title"><?= __('setup_email_title', 'Finish setup: email delivery') ?></div>
        <div class="setup-nudge-text"><?= __('setup_email_text', 'Your site can\'t send email yet — notifications, password resets and receipts won\'t go out. Pick a provider (Gmail, Microsoft 365, and more), or connect with OAuth, in a couple of minutes.') ?></div>
        <div class="setup-nudge-actions">
            <a href="<?= e(SLATE_URL) ?>/admin/settings.php?tab=smtp" class="btn btn-primary btn-sm"><?= __('setup_email_cta', 'Set up email') ?></a>
        </div>
    </div>
    <button type="button" class="setup-nudge-x" data-nudge-dismiss aria-label="<?= e(__('dismiss', 'Dismiss')) ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </button>
</div>
<script>
(function () {
    var n = document.getElementById('email-setup-nudge');
    if (!n) return;
    if (document.cookie.indexOf('slate_email_nudge=1') === -1) n.hidden = false;
    var x = n.querySelector('[data-nudge-dismiss]');
    if (x) x.addEventListener('click', function () {
        n.hidden = true;
        document.cookie = 'slate_email_nudge=1; max-age=1209600; path=/; samesite=lax';
    });
})();
</script>
<?php endif; ?>

<?php
// Central Licensing Overview — displays real metrics from the Central
// licensing_* tables (with safe fallback if tables are not yet initialized).
if (Auth::can('licensing.manage') || Auth::isSuperAdmin()):
    $centralStats = [
        'active_licenses'     => 0,
        'bound_installations' => 0,
        'active_plans'        => 0,
        'total_clients'       => 0,
    ];
    try {
        $centralStats['active_licenses']     = (int) Database::value("SELECT COUNT(*) FROM licensing_licenses WHERE status IN ('trial','active')");
        $centralStats['bound_installations'] = (int) Database::value("SELECT COUNT(*) FROM licensing_installations WHERE status = 'active'");
        $centralStats['active_plans']        = (int) Database::value("SELECT COUNT(*) FROM licensing_plans WHERE is_active = 1");
        $centralStats['total_clients']       = (int) Database::value("SELECT COUNT(*) FROM licensing_clients");
    } catch (\Throwable $e) {
        // Safe fallback if licensing tables are not yet provisioned.
    }
    ?>
    <div class="page-header" style="margin-top:24px;">
        <div>
            <h2><?= __('licensing_overview', 'Licensing overview') ?></h2>
            <p class="page-header-sub"><?= __('central_licensing_sub', 'Central authority for plans, issued licenses, and bound client installations.') ?></p>
        </div>
        <a href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>" class="btn"><?= __('manage_licenses', 'Manage licenses') ?></a>
    </div>
    <div class="dash-stats">
        <?php slate_stat_card(['icon' => 'shield', 'number' => $centralStats['active_licenses'], 'label' => __('active_licenses', 'Active licenses'), 'tone' => $centralStats['active_licenses'] > 0 ? 'success' : '']); ?>
        <?php slate_stat_card(['icon' => 'check-circle', 'number' => $centralStats['bound_installations'], 'label' => __('bound_installations', 'Bound installations')]); ?>
        <?php slate_stat_card(['icon' => 'tag', 'number' => $centralStats['active_plans'], 'label' => __('active_plans', 'Active plans')]); ?>
        <?php slate_stat_card(['icon' => 'users', 'number' => $centralStats['total_clients'], 'label' => __('clients', 'Clients')]); ?>
    </div>
<?php endif; ?>

<div class="dash-layout">
    <div class="dash-main">
        <?php if ($pluginWidgets): ?>
            <?php foreach ($pluginWidgets as $widget) echo $widget; ?>
        <?php else: ?>
            <div class="card">
                <div class="card-header"><h2><?= __('welcome', 'Welcome to Kohevo Central') ?></h2></div>
                <p class="text-muted"><?= __('dashboard_intro',
                    'Use this workspace to manage plans, issue licenses, review installations and follow commercial activity.') ?></p>
                <div class="mt-4">
                    <a href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>" class="btn btn-primary"><?= __('manage_licenses', 'Manage licenses') ?></a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <aside class="dash-rail">
        <div class="card">
            <div class="card-header"><h2><?= __('quick_actions', 'Quick actions') ?></h2></div>
            <nav class="dash-qa">
                <?php if (Auth::can('licensing.manage') || Auth::isSuperAdmin()): ?>
                <a href="<?= e(plugin_url('licensing', 'admin/index.php')) ?>">
                    <span class="dash-qa-ico"><?= slate_admin_nav_icon('key') ?></span>
                    <?= __('licensing_overview', 'Licensing overview') ?>
                    <span class="dash-qa-arr">→</span>
                </a>
                <a href="<?= e(plugin_url('licensing', 'admin/licenses.php')) ?>">
                    <span class="dash-qa-ico"><?= slate_admin_nav_icon('shield') ?></span>
                    <?= __('manage_licenses', 'Manage licenses') ?>
                    <span class="dash-qa-arr">→</span>
                </a>
                <a href="<?= e(plugin_url('licensing', 'admin/plans.php')) ?>">
                    <span class="dash-qa-ico"><?= slate_admin_nav_icon('tag') ?></span>
                    <?= __('manage_plans', 'Manage plans') ?>
                    <span class="dash-qa-arr">→</span>
                </a>
                <?php endif; ?>
                <a href="<?= e(SLATE_URL) ?>/admin/audit.php">
                    <span class="dash-qa-ico"><?= slate_admin_nav_icon('clipboard-list') ?></span>
                    <?= __('view_audit_log', 'View audit log') ?>
                    <span class="dash-qa-arr">→</span>
                </a>
            </nav>
        </div>

        <?php if ($recent): ?>
        <div class="card">
            <div class="card-header"><h2><?= __('recent_activity', 'Recent activity') ?></h2></div>
            <ul class="dash-feed">
                <?php foreach ($recent as $row):
                    [$label, $icon, $tone] = $activityMeta((string)($row['action'] ?? ''));
                    $when      = $row['created_at'] ?? '';
                    $whenShort = $when ? slate_format_datetime($when) : '—';
                ?>
                    <li class="dash-feed-item">
                        <span class="dash-feed-ico <?= $tone ?>"><?= slate_admin_nav_icon($icon) ?></span>
                        <div class="dash-feed-body">
                            <div class="dash-feed-title"><?= e($label) ?></div>
                            <div class="dash-feed-meta"><?= e($row['user_name'] ?? 'System') ?> · <?= e($whenShort) ?></div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </aside>
</div>

<script>
(function () {
    var el = document.getElementById('dash-clock'); if (!el) return;
    var t = el.querySelector('.t'); if (!t) return;
    var tz = el.getAttribute('data-tz') || 'UTC';
    var fmt = el.getAttribute('data-time-format') || 'g:i a';
    function formatPhpTime(d) {
        var h = d.getHours(), m = d.getMinutes(), s = d.getSeconds();
        try {
            var parts = new Intl.DateTimeFormat('en-US', {
                timeZone: tz, hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit'
            }).formatToParts(d);
            parts.forEach(function (p) {
                if (p.type === 'hour') h = parseInt(p.value, 10) % 24;
                if (p.type === 'minute') m = parseInt(p.value, 10);
                if (p.type === 'second') s = parseInt(p.value, 10);
            });
        } catch (e) {}
        var h12 = h % 12 || 12;
        var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
        var out = '';
        for (var i = 0; i < fmt.length; i++) {
            var c = fmt.charAt(i);
            if (c === '\\' && i + 1 < fmt.length) { out += fmt.charAt(++i); continue; }
            if (c === 'H') out += pad(h);
            else if (c === 'G') out += h;
            else if (c === 'h') out += pad(h12);
            else if (c === 'g') out += h12;
            else if (c === 'i') out += pad(m);
            else if (c === 's') out += pad(s);
            else if (c === 'a') out += (h >= 12 ? 'pm' : 'am');
            else if (c === 'A') out += (h >= 12 ? 'PM' : 'AM');
            else out += c;
        }
        return out;
    }
    setInterval(function () {
        t.textContent = formatPhpTime(new Date());
    }, 10000);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
