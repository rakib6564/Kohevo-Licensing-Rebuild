<?php
/**
 * MCP Gateway — token management.
 *
 * Create/revoke scoped, tenant-pinned tokens for AI agents. Token value is
 * shown exactly once at creation (only its hash is stored) — same pattern
 * as the archived Slate AI Gateway this plugin replaces.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/McpGatewayAPI.php';

Auth::require();
Auth::requirePerm('mcp-gateway.manage');
McpGatewayAPI::ensureSchema();

$pageTitle  = __('mcp_gateway_nav', 'AI Access');
$currentNav = 'mcp-gateway';
$flash = null;
$newToken = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('mcp_gateway_security_check_failed', 'Security check failed.')];
    } elseif (($_POST['_action'] ?? '') === 'create_token') {
        try {
            $newToken = McpGatewayAPI::createToken(
                (string)($_POST['label'] ?? ''), (array)($_POST['scopes'] ?? []), (string)($_POST['expires_at'] ?? '')
            );
            $flash = ['type' => 'success', 'msg' => __('mcp_gateway_index_token_created', 'Token created. Copy it now — Kohevo cannot show it again.')];
        } catch (Throwable $e) {
            $flash = ['type' => 'error', 'msg' => $e->getMessage()];
        }
    } elseif (($_POST['_action'] ?? '') === 'revoke_token') {
        McpGatewayAPI::revokeToken((int)($_POST['token_id'] ?? 0));
        $flash = ['type' => 'success', 'msg' => __('mcp_gateway_index_token_revoked', 'Token revoked immediately.')];
    }
}

$tokens  = McpGatewayAPI::listTokens();
$scopes  = McpGatewayAPI::availableScopes();
$enabled = defined('MCP_GATEWAY_ENABLED') && MCP_GATEWAY_ENABLED;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('mcp_gateway_nav', 'AI Access')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('mcp_gateway_nav', 'AI Access')) ?></h1>
        <p class="page-header-sub"><?= e(__('mcp_gateway_index_sub', 'Create narrowly scoped, tenant-specific credentials for approved AI agents (Claude, Manus, etc.).')) ?></p>
    </div>
</div>

<?php if (!$enabled): ?>
    <div class="alert alert-warning" role="status">
        <?= __('mcp_gateway_index_disabled_notice', 'The MCP gateway is currently <strong>disabled</strong> at the environment level (<code>MCP_GATEWAY_ENABLED</code> is not set to <code>1</code>). Tokens can still be created and revoked here, but no agent can connect until that flag is turned on by whoever manages the server environment.') ?>
    </div>
<?php endif; ?>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if ($newToken): ?>
<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('mcp_gateway_index_copy_token_heading', 'Copy this token now')) ?></h2></div>
    <p class="text-muted"><?= e(__('mcp_gateway_index_copy_token_desc', "Paste it into the AI client's connector/secret configuration. It is shown only once and can be revoked below.")) ?></p>
    <textarea class="mcp-token" readonly><?= e($newToken['token']) ?></textarea>
    <p class="field-help"><?= sprintf(
        __('mcp_gateway_index_endpoint_info', 'Endpoint: %1$s · send it as %2$s'),
        '<code>' . e(rtrim(SLATE_URL, '/') . '/api/v1/mcp') . '</code>',
        '<code>Authorization: Bearer &lt;token&gt;</code>'
    ) ?></p>
</section>
<?php endif; ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('mcp_gateway_index_create_token_heading', 'Create an AI access token')) ?></h2></div>
    <p class="text-muted">
        <?= e(__('mcp_gateway_index_token_safety_note', 'No token, regardless of scopes selected, can change a password, delete a role or a user account, write a live Stripe/SMTP secret key, or deactivate this plugin — those are hard-blocked in code, not by scope choice. Give each AI client its own minimum-access token.')) ?>
    </p>
    <form method="post">
        <input type="hidden" name="_action" value="create_token">
        <?= csrf_field() ?>
        <div class="mcp-grid">
            <div class="field">
                <label class="field-label"><?= e(__('mcp_gateway_index_label_field', 'Label')) ?></label>
                <input name="label" required maxlength="120" placeholder="<?= e(__('mcp_gateway_index_label_placeholder', 'Claude admin assistant')) ?>">
            </div>
            <div class="field">
                <label class="field-label"><?= e(__('mcp_gateway_index_expiry_label', 'Expiry date')) ?> <span class="text-muted"><?= e(__('auth_optional', 'optional')) ?></span></label>
                <input type="date" name="expires_at">
            </div>
        </div>
        <?php if (!$scopes): ?>
            <p class="text-muted"><?= e(__('mcp_gateway_index_no_scopes', 'No scopes are registered yet — install/activate a plugin that provides MCP tools (Booking, Membership, etc.) to see options here.')) ?></p>
        <?php else: ?>
        <div class="mcp-scopes">
            <?php foreach ($scopes as $scopeKey => $scopeLabel): $val = is_int($scopeKey) ? $scopeLabel : $scopeKey; ?>
                <label><input type="checkbox" name="scopes[]" value="<?= e($val) ?>"> <?= e($scopeLabel) ?></label>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <button class="btn btn-primary" type="submit"><?= e(__('mcp_gateway_index_create_token_btn', 'Create token')) ?></button>
    </form>
</section>

<section class="card">
    <div class="card-header"><h2><?= e(__('mcp_gateway_index_issued_tokens_heading', 'Issued tokens')) ?></h2></div>
    <?php if (!$tokens): ?>
        <p class="text-muted"><?= e(__('mcp_gateway_index_no_tokens', 'No AI access tokens have been created for this tenant.')) ?></p>
    <?php else: ?>
        <div class="data-list">
            <?php foreach ($tokens as $token): ?>
                <div class="mcp-row">
                    <div>
                        <strong><?= e($token['label']) ?></strong>
                        <span>
                            <?= e($token['token_prefix']) ?>… · <?= e(implode(', ', $token['scopes']) ?: '—') ?>
                            <?= $token['expires_at'] ? sprintf(__('mcp_gateway_index_expires_fragment', ' · expires %s'), e($token['expires_at'])) : '' ?>
                            <?= $token['last_used_at'] ? sprintf(__('mcp_gateway_index_used_fragment', ' · used %s'), e($token['last_used_at'])) : __('mcp_gateway_index_never_used_fragment', ' · never used') ?>
                        </span>
                    </div>
                    <?php if (!$token['revoked_at']): ?>
                        <form method="post" onsubmit="return confirm(<?= e(json_encode(__('mcp_gateway_index_revoke_confirm', 'Revoke this AI token immediately?'))) ?>);">
                            <input type="hidden" name="_action" value="revoke_token">
                            <?= csrf_field() ?>
                            <input type="hidden" name="token_id" value="<?= (int)$token['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit"><?= e(__('revoke', 'Revoke')) ?></button>
                        </form>
                    <?php else: ?>
                        <span class="text-muted"><?= sprintf(__('mcp_gateway_index_revoked_fragment', 'Revoked %s'), e($token['revoked_at'])) ?></span>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<p class="text-sm text-muted mt-3"><?= sprintf(
    __('mcp_gateway_index_footer', 'Every call made with these tokens — allowed or blocked — is recorded in %s (filter by action prefix <code>mcp-gateway.</code>).'),
    '<a href="' . e(SLATE_URL) . '/admin/audit.php?action=mcp-gateway.">' . e(__('audit_log', 'Audit Log')) . '</a>'
) ?></p>

<style>
.mcp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-bottom:16px}
.mcp-scopes{display:grid;gap:10px;margin:0 0 18px}
.mcp-scopes label{font-size:.92rem}
.mcp-token{display:block;width:100%;min-height:90px;resize:vertical;font:13px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace}
.field-help{margin:7px 0 0;font-size:.82rem;color:var(--muted,#6b7280)}
.mcp-row{display:flex;justify-content:space-between;align-items:center;gap:16px;border-top:1px solid var(--border,#e5e7eb);padding:14px 0}
.mcp-row:first-child{border-top:0}
.mcp-row strong,.mcp-row span{display:block}
.mcp-row span{margin-top:4px;font-size:.82rem;color:var(--muted,#6b7280)}
@media(max-width:720px){.mcp-grid{grid-template-columns:1fr}.mcp-row{display:block}.mcp-row form{margin-top:10px}}
</style>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
