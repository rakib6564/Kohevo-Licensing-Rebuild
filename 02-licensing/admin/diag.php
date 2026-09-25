<?php
/**
 * Slate — settings diagnostic + force-fix.
 *
 * Plain-text-ish page (no admin shell) so nothing can mangle the
 * output. Shows EXACTLY what the live site reads for site_name, which
 * database it's connected to, and a file-version marker so we can
 * confirm the deploy took. One button force-resets site_name.
 *
 * Super Admin only. Disabled by default; delete after use.
 *
 * MARKER: SLATE-DIAG-V3
 */
require_once dirname(__DIR__) . '/config.php';

// Diagnostics are an explicitly enabled, temporary maintenance surface. Production
// deployments must define SLATE_DIAG_ENABLED=true only for a controlled incident.
if (!defined('SLATE_DIAG_ENABLED') || SLATE_DIAG_ENABLED !== true) {
    http_response_code(404);
    exit('Not found.');
}

// Genuinely platform-level (reveals live DB/file state for the whole
// install) — gated by the dedicated platform-admin concept, not the
// generic isSuperAdmin() bypass. See Phase 0E/0G RBAC audit.
Auth::requirePlatformAdmin();

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $new = trim((string)($_POST['new_site_name'] ?? ''));
    $new = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/', '', $new) ?? '';
    $new = strip_tags($new);
    $new = trim(preg_replace('/\s+/', ' ', $new) ?? '');
    if ($new === '') $new = 'Kohevo';
    $new = mb_substr($new, 0, 120);
    Database::setSetting('site_name', $new);
    if (class_exists('AuditLog')) AuditLog::record('settings.diag_reset', 'site_name');
    $flash = 'Saved. site_name is now: ' . $new;
}

$tid = current_tenant_id();
$raw = Database::setting('site_name');
$rawStr = is_string($raw) ? $raw : var_export($raw, true);

// Direct read with no helper, in case Database::setting does something odd.
$direct = null;
try {
    $direct = Database::value(
        "SELECT setting_value FROM settings WHERE tenant_id = ? AND setting_key = 'site_name'",
        [$tid]
    );
} catch (\Throwable $e) {
    $direct = '[query error: ' . $e->getMessage() . ']';
}

$dbName = defined('DB_NAME') ? DB_NAME : '(DB_NAME not defined)';
$hex    = bin2hex(mb_substr((string)$rawStr, 0, 60));
$strip  = strip_tags((string)$rawStr);

?><!doctype html>
<html><head><meta charset="utf-8"><title>Kohevo diag</title>
<style>
body{font-family:ui-monospace,Menlo,Consolas,monospace;background:#0E1117;color:#D8DAE0;padding:32px;line-height:1.6;font-size:13px}
h1{font-family:system-ui,sans-serif;color:#fff}
.box{background:#171B22;border:1px solid #2A2F38;border-radius:8px;padding:16px 20px;margin:14px 0;overflow:auto}
.k{color:#8B93A5}
.v{color:#fff;word-break:break-all;white-space:pre-wrap}
.ok{color:#4ADE80}.bad{color:#F87171}.warn{color:#FBBF24}
input[type=text]{width:100%;padding:10px;font:inherit;background:#0E1117;color:#fff;border:1px solid #2A2F38;border-radius:6px;margin:8px 0}
button{background:#2563EB;color:#fff;border:0;border-radius:6px;padding:10px 16px;font:inherit;cursor:pointer}
a{color:#60A5FA}
</style></head><body>
<h1>Kohevo diagnostic — SLATE-DIAG-V3</h1>

<?php if ($flash): ?><div class="box ok"><?= htmlspecialchars($flash, ENT_QUOTES) ?></div><?php endif; ?>

<div class="box">
<div><span class="k">File version marker:</span> <span class="v ok">SLATE-DIAG-V3</span> &nbsp; (if you see V3, the new files ARE deployed)</div>
<div><span class="k">PHP version:</span> <span class="v"><?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?></span></div>
<div><span class="k">Connected DB (DB_NAME):</span> <span class="v warn"><?= htmlspecialchars((string)$dbName, ENT_QUOTES) ?></span></div>
<div><span class="k">current_tenant_id():</span> <span class="v"><?= (int)$tid ?></span></div>
</div>

<div class="box">
<div class="k">site_name via Database::setting():</div>
<div class="v"><?= htmlspecialchars($rawStr === '' ? '(empty)' : $rawStr, ENT_QUOTES) ?></div>
<br>
<div class="k">site_name via direct SQL (no helper):</div>
<div class="v"><?= htmlspecialchars((string)($direct ?? '(null)'), ENT_QUOTES) ?></div>
<br>
<div><span class="k">length:</span> <span class="v"><?= mb_strlen((string)$rawStr) ?> chars</span></div>
<div><span class="k">first 60 bytes (hex):</span> <span class="v"><?= htmlspecialchars($hex, ENT_QUOTES) ?></span></div>
<div><span class="k">strip_tags() would yield:</span> <span class="v <?= $strip === (string)$rawStr ? 'ok' : 'bad' ?>"><?= htmlspecialchars($strip === '' ? '(empty — value is ALL markup)' : $strip, ENT_QUOTES) ?></span></div>
<div>
  <?php if (preg_match('/<[a-z!\/]/i', (string)$rawStr)): ?>
    <span class="bad">⚠ This value contains raw HTML markup. THIS is what's breaking the Settings page.</span>
  <?php else: ?>
    <span class="ok">✓ No HTML markup detected in the stored value.</span>
  <?php endif; ?>
</div>
</div>

<div class="box">
<form method="post">
<input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
<div class="k">Force-set site_name (HTML stripped automatically on save):</div>
<input type="text" name="new_site_name" value="Chef Gregory's Gourmet Products" maxlength="120">
<button type="submit">Force-reset site_name</button>
</form>
</div>

<div class="box">
<p>After fixing: open <a href="<?= htmlspecialchars(SLATE_URL, ENT_QUOTES) ?>/admin/settings.php">/admin/settings.php</a> in an incognito window.</p>
<p class="warn">If the "Connected DB" above is NOT the database you ran your phpMyAdmin UPDATE on, that's the whole problem — you fixed the wrong database.</p>
</div>

</body></html>
