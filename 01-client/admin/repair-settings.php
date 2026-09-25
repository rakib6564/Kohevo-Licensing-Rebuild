<?php
/**
 * Slate — one-shot settings repair tool.
 *
 * Use this when the admin Settings page renders only the first card
 * (or worse) because a setting value contains HTML / a `<script>` tag /
 * control characters that confuse the page.
 *
 * What it does:
 *   1. Lists every `settings` row whose value contains markup, control
 *      chars, or exceeds a sensible length cap.
 *   2. Lets you reset each suspect row to a safe default (or blank).
 *   3. Audit-logs every change.
 *
 * Delegable via the existing `settings.edit` permission (Phase 0I RBAC
 * audit) — every key this tool can touch is already settable to ANY
 * value via /admin/settings.php under settings.edit; this tool can only
 * reset a flagged key to one fixed, hardcoded safe default, never an
 * arbitrary value, so it grants no capability beyond what settings.edit
 * already permits. No new permission key was introduced for it.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();
if (!Auth::can('settings.edit') && !Auth::isSuperAdmin()) {
    http_response_code(403);
    echo '<h1>403 Forbidden</h1><p>You do not have permission to access this tool.</p>';
    exit;
}

$pageTitle  = 'Repair settings';
$currentNav = 'settings';

$tid   = current_tenant_id();
$flash = null;

// Known settings + safe defaults (only these get the "reset" treatment;
// unknown keys, plugin-owned keys with a `<slug>.` prefix etc. are left alone).
$KNOWN = [
    'site_name'              => 'Kohevo',
    'default_language'       => 'en',
    'brand_sublabel'         => 'Pro Admin',
    'brand_accent_color'     => '#2563EB',
    'brand_logo_path'        => '',
    'brand_login_image_path' => '',
    'brand_login_tagline'    => '',
    'business_name'          => '',
    'business_email'         => '',
    'business_phone'         => '',
    'business_address'       => '',
    'business_hours'         => '',
    'smtp_host'              => '',
    'smtp_port'              => '',
    'smtp_user'              => '',
    'smtp_encryption'        => 'tls',
    'smtp_from_name'         => '',
    'smtp_from_email'        => '',
];

// Detect "dirty" values: HTML tags, control chars, or > 500 chars (200 for site_name/lang).
$isDirty = static function (string $key, $value): ?string {
    $v = is_string($value) ? $value : (string) $value;
    if ($v === '') return null;
    if (preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $v))    return 'contains control characters';
    if (preg_match('/<[a-zA-Z\/!]/', $v))                return 'contains HTML markup';
    $cap = in_array($key, ['site_name','default_language','brand_sublabel','brand_accent_color','smtp_port'], true) ? 120 : 500;
    if (mb_strlen($v) > $cap)                            return 'value too long (' . mb_strlen($v) . ' chars, cap ' . $cap . ')';
    return null;
};

// ── POST: apply ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $reset = $_POST['reset'] ?? [];
        if (!is_array($reset)) $reset = [];
        $count = 0;
        foreach ($reset as $key => $_ignored) {
            if (!array_key_exists($key, $KNOWN)) continue;
            $default = $KNOWN[$key];
            Database::setSetting($key, $default);
            AuditLog::record('settings.repaired', $key, ['reset_to' => $default]);
            $count++;
        }
        $flash = ['type' => 'success', 'msg' => $count > 0
            ? "Reset {$count} setting(s) to safe defaults. Visit /admin/settings.php to fill them back in."
            : "No settings were reset."];
    }
}

// Read current state for every known key
$rows = [];
foreach ($KNOWN as $key => $default) {
    $val = Database::setting($key);
    $rows[] = [
        'key'      => $key,
        'value'    => $val,
        'len'      => is_string($val) ? mb_strlen($val) : 0,
        'default'  => $default,
        'dirty'    => $val !== null ? $isDirty($key, $val) : null,
    ];
}
$dirtyCount = count(array_filter($rows, fn($r) => $r['dirty'] !== null));

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => 'Dashboard', 'href' => SLATE_URL . '/admin/'],
    ['label' => 'Settings',  'href' => SLATE_URL . '/admin/settings.php'],
    ['label' => 'Repair'],
]); ?>

<div class="page-header">
    <div>
        <h1>Repair settings</h1>
        <p class="page-header-sub">Find and reset settings whose values contain HTML or control characters.</p>
    </div>
    <a href="<?= e(SLATE_URL) ?>/admin/settings.php" class="btn">← Back to Settings</a>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<?php if ($dirtyCount === 0): ?>
    <div class="card">
        <div class="empty">
            <div class="empty-title">Nothing to repair</div>
            <p class="text-sm">All known settings look clean. If <code>/admin/settings.php</code> is still broken, something else is wrong — check <code>data/slate.log</code> and the host's PHP error log.</p>
        </div>
    </div>
<?php else: ?>
    <form method="post">
        <?= csrf_field() ?>
        <div class="card">
            <div class="card-header">
                <h2>Suspicious values (<?= (int)$dirtyCount ?>)</h2>
                <span class="text-xs text-muted">Tick the rows you want to reset.</span>
            </div>
            <ul class="kv-list">
                <?php foreach ($rows as $r): if ($r['dirty'] === null) continue; ?>
                    <li class="kv-row" style="align-items:flex-start;">
                        <span class="kv-label" style="display:flex;align-items:flex-start;gap:8px;">
                            <input type="checkbox" name="reset[<?= e($r['key']) ?>]" value="1"
                                   id="r_<?= e($r['key']) ?>" style="margin-top:3px;" checked>
                            <span>
                                <label for="r_<?= e($r['key']) ?>" style="cursor:pointer;">
                                    <code><?= e($r['key']) ?></code>
                                </label>
                                <br>
                                <span class="text-xs text-danger"><?= e($r['dirty']) ?></span>
                            </span>
                        </span>
                        <span class="kv-value" style="max-width:60%;">
                            <code style="display:block;word-break:break-all;font-size:11px;background:var(--surface-2);padding:6px 8px;border-radius:6px;max-height:80px;overflow:auto;">
                                <?= e(mb_substr((string)$r['value'], 0, 400)) ?><?= $r['len'] > 400 ? '…' : '' ?>
                            </code>
                            <div class="text-xs text-muted mt-1">Will reset to: <code><?= e($r['default'] === '' ? '(empty)' : $r['default']) ?></code></div>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <button type="submit" class="btn btn-primary btn-lg">Reset selected settings</button>
    </form>
<?php endif; ?>

<div class="card mt-6">
    <div class="card-header"><h2>All known settings (read-only)</h2></div>
    <ul class="kv-list">
        <?php foreach ($rows as $r): ?>
            <li class="kv-row">
                <span class="kv-label kv-mono"><?= e($r['key']) ?></span>
                <span class="kv-value">
                    <?php if ($r['value'] === null): ?>
                        <span class="text-muted">— not set —</span>
                    <?php else: ?>
                        <code style="font-size:11px;"><?= e(mb_substr((string)$r['value'], 0, 80)) ?><?= mb_strlen((string)$r['value']) > 80 ? '…' : '' ?></code>
                        <span class="text-xs text-muted">(<?= (int)$r['len'] ?> chars<?= $r['dirty'] ? ', <strong>dirty</strong>' : '' ?>)</span>
                    <?php endif; ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
