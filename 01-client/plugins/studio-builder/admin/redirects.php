<?php
/**
 * Kohevo Studio — 301/302 URL Redirects Manager.
 *
 * Dedicated admin page for managing URL redirection rules, preventing 404s
 * during portfolio migrations, and handling vanity shortlinks.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_redirects', 'Redirects');
$currentNav = 'studio-redirects';

$flash = null;

// Read existing redirects from settings
$redirectsRaw = Database::setting('studio_redirects', $tenantId);
$redirects = $redirectsRaw ? json_decode((string) $redirectsRaw, true) : [];
if (!is_array($redirects)) {
    $redirects = [];
}

// POST handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to manage redirects.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'add_redirect') {
            $fromPath = trim((string) ($_POST['from_path'] ?? ''));
            $toPath = trim((string) ($_POST['to_path'] ?? ''));
            $statusCode = (int) ($_POST['status_code'] ?? 301);
            $notes = trim((string) ($_POST['notes'] ?? ''));

            // Normalise paths
            if (!str_starts_with($fromPath, 'http://') && !str_starts_with($fromPath, 'https://')) {
                $fromPath = '/' . ltrim($fromPath, '/');
            }
            if (!str_starts_with($toPath, 'http://') && !str_starts_with($toPath, 'https://')) {
                $toPath = '/' . ltrim($toPath, '/');
            }

            // Reserved route check
            $reserved = ['/admin', '/login', '/logout', '/sitemap.xml', '/robots.txt'];
            if ($fromPath === '' || $toPath === '') {
                $flash = ['type' => 'error', 'msg' => 'Source and destination paths are required.'];
            } elseif ($fromPath === $toPath) {
                $flash = ['type' => 'error', 'msg' => 'Source and destination cannot be identical (redirect loop).'];
            } elseif (in_array($fromPath, $reserved, true)) {
                $flash = ['type' => 'error', 'msg' => "Cannot redirect system reserved route '{$fromPath}'."];
            } else {
                $ruleId = 'r_' . bin2hex(random_bytes(4));
                $redirects[] = [
                    'id' => $ruleId,
                    'from' => $fromPath,
                    'to' => $toPath,
                    'code' => in_array($statusCode, [301, 302], true) ? $statusCode : 301,
                    'notes' => $notes,
                    'hits' => 0,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                Database::setSetting('studio_redirects', json_encode($redirects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $flash = ['type' => 'success', 'msg' => "Redirect rule from '{$fromPath}' to '{$toPath}' saved."];
            }
        } elseif ($action === 'delete_redirect') {
            $ruleId = trim((string) ($_POST['rule_id'] ?? ''));
            if ($ruleId !== '') {
                $redirects = array_values(array_filter($redirects, fn($r) => ($r['id'] ?? '') !== $ruleId));
                Database::setSetting('studio_redirects', json_encode($redirects, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $tenantId);
                $flash = ['type' => 'success', 'msg' => 'Redirect rule removed.'];
            }
        }
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('redirects', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
        <div>
            <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">URL Redirects & Link Hygiene</h2>
            <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Set up 301 Permanent and 302 Temporary redirects to preserve SEO equity and route old URLs.</p>
        </div>

        <button type="button" class="btn btn-primary" onclick="document.getElementById('sb-redirect-drawer').style.display='block'">
            <?= sb_svg('plus', 14) ?> Add Redirect Rule
        </button>
    </div>

    <!-- Add Redirect Drawer / Form -->
    <div id="sb-redirect-drawer" style="display: none; background: #ffffff; border: 1px solid var(--sb-border); border-radius: 14px; padding: 24px; margin-bottom: 28px; box-shadow: 0 4px 16px rgba(0,0,0,0.06);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
            <div style="font-weight: 700; font-size: 1.15rem; color: var(--sb-text); display: flex; align-items: center; gap: 8px;">
                <?= sb_svg('redirects', 20) ?> Create URL Redirection
            </div>
            <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('sb-redirect-drawer').style.display='none'">
                <?= sb_svg('close', 14) ?>
            </button>
        </div>
        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/redirects.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="add_redirect">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 16px;">
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Source Path <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="from_path" class="form-control" placeholder="e.g. /old-portfolio or /work" required>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">Incoming request path to intercept.</span>
                </div>
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Target Destination <span style="color: #ef4444;">*</span></label>
                    <input type="text" name="to_path" class="form-control" placeholder="e.g. /portfolio or https://..." required>
                    <span style="font-size: 0.75rem; color: var(--sb-muted);">New destination URL or relative path.</span>
                </div>
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">HTTP Status Code</label>
                    <select name="status_code" class="form-control" style="background: #ffffff;">
                        <option value="301">301 Moved Permanently (SEO Standard)</option>
                        <option value="302">302 Found / Temporary (Shortlinks)</option>
                    </select>
                </div>
            </div>
            <div style="margin-bottom: 18px;">
                <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="Reason for redirect or migration note...">
            </div>
            <div style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('sb-redirect-drawer').style.display='none'">Cancel</button>
                <button type="submit" class="btn btn-primary"><?= sb_svg('check', 14) ?> Save Rule</button>
            </div>
        </form>
    </div>

    <!-- Redirects Table -->
    <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); overflow: hidden; padding: 0; margin-bottom: 32px;">
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
                <thead>
                    <tr style="background: #f8fafc; border-bottom: 1px solid var(--sb-border);">
                        <th style="padding: 12px 18px; font-weight: 600; color: var(--sb-muted);">Source URL</th>
                        <th style="padding: 12px 18px; font-weight: 600; color: var(--sb-muted);">Target Destination</th>
                        <th style="padding: 12px 18px; font-weight: 600; color: var(--sb-muted);">Type</th>
                        <th style="padding: 12px 18px; font-weight: 600; color: var(--sb-muted);">Notes</th>
                        <th style="padding: 12px 18px; font-weight: 600; color: var(--sb-muted);">Created</th>
                        <th style="padding: 12px 18px; text-align: right; font-weight: 600; color: var(--sb-muted);">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($redirects)): ?>
                        <tr>
                            <td colspan="6" style="padding: 36px; text-align: center; color: var(--sb-muted);">
                                No redirect rules configured. Click "Add Redirect Rule" to map legacy URLs or vanity paths.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($redirects as $rule):
                            $rId = (string) ($rule['id'] ?? '');
                            $from = (string) ($rule['from'] ?? '');
                            $to = (string) ($rule['to'] ?? '');
                            $code = (int) ($rule['code'] ?? 301);
                            $notes = (string) ($rule['notes'] ?? '—');
                            $createdAt = (string) ($rule['created_at'] ?? '');
                        ?>
                            <tr style="border-bottom: 1px solid var(--sb-border);">
                                <td style="padding: 14px 18px; font-family: monospace; font-weight: 600; color: var(--sb-text);">
                                    <?= e($from) ?>
                                </td>
                                <td style="padding: 14px 18px; font-family: monospace; color: var(--sb-accent);">
                                    <?= e($to) ?>
                                </td>
                                <td style="padding: 14px 18px;">
                                    <span style="background: <?= $code === 301 ? '#10b98115' : '#6366f115' ?>; color: <?= $code === 301 ? '#10b981' : '#6366f1' ?>; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700;">
                                        <?= $code ?>
                                    </span>
                                </td>
                                <td style="padding: 14px 18px; color: var(--sb-muted);">
                                    <?= e($notes) ?>
                                </td>
                                <td style="padding: 14px 18px; font-size: 0.8rem; color: var(--sb-muted); white-space: nowrap;">
                                    <?= e($createdAt !== '' ? date('M j, Y', strtotime($createdAt)) : '—') ?>
                                </td>
                                <td style="padding: 14px 18px; text-align: right; white-space: nowrap;">
                                    <?php if ($canEdit): ?>
                                        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/redirects.php')) ?>" onsubmit="return confirm('Remove redirect rule for <?= e($from) ?>?');" style="margin: 0; display: inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="_action" value="delete_redirect">
                                            <input type="hidden" name="rule_id" value="<?= e($rId) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444; padding: 4px 8px;" title="Delete rule">
                                                <?= sb_svg('trash', 12) ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
