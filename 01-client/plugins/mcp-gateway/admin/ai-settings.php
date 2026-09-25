<?php
/**
 * MCP Gateway — AI Connection settings.
 *
 * A single generic OpenAI-compatible connector (base URL + API key +
 * model) that both the admin AI Assistant chat and the customer-facing
 * site assistant read from — one place to plug in whichever provider
 * (OpenAI, Anthropic via a compatible proxy, OpenRouter, a self-hosted
 * gateway, ...) rather than a separate integration per surface.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__) . '/McpGatewayAPI.php';
require_once dirname(__DIR__) . '/AiProviderClient.php';

Auth::require();
Auth::requirePerm('mcp-gateway.manage');
McpGatewayAPI::ensureSchema();

$pageTitle  = __('mcp_gateway_ai_settings_nav', 'AI Connection');
$currentNav = 'mcp-gateway-ai-settings';
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('mcp_gateway_security_check_failed', 'Security check failed.')];
    } else {
        $baseUrl = trim((string)($_POST['ai_base_url'] ?? ''));
        $model   = trim((string)($_POST['ai_model'] ?? ''));
        $key     = (string)($_POST['ai_api_key'] ?? '');
        $clearKey = isset($_POST['clear_ai_api_key']) && $_POST['clear_ai_api_key'] === '1';
        $customerEnabled = !empty($_POST['customer_assistant_enabled']);

        Database::setSetting('mcp-gateway.ai_base_url', mb_substr($baseUrl, 0, 300));
        Database::setSetting('mcp-gateway.ai_model', mb_substr($model, 0, 120));
        Database::setSetting('mcp-gateway.customer_assistant_enabled', $customerEnabled ? '1' : '0');

        if ($clearKey) {
            Database::setSetting('mcp-gateway.ai_api_key', '');
        } elseif (trim($key) !== '') {
            Database::setSetting('mcp-gateway.ai_api_key', slate_encrypt_secret(trim($key)));
        }
        // Blank key field with no clear checkbox = leave the saved key untouched.

        AuditLog::record('mcp-gateway.ai_settings_updated', '');
        $flash = ['type' => 'success', 'msg' => __('mcp_gateway_ai_settings_saved', 'AI connection settings saved.')];
    }
}

$hasKey  = (bool) Database::setting('mcp-gateway.ai_api_key');
$baseUrl = (string) Database::setting('mcp-gateway.ai_base_url');
$model   = (string) Database::setting('mcp-gateway.ai_model');
$customerEnabled = (string) Database::setting('mcp-gateway.customer_assistant_enabled') === '1';
$configured = AiProviderClient::isConfigured();

require SLATE_ROOT . '/admin/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('mcp_gateway_ai_settings_nav', 'AI Connection')],
]); ?>

<div class="page-header">
    <div>
        <h1><?= e(__('mcp_gateway_ai_settings_nav', 'AI Connection')) ?></h1>
        <p class="page-header-sub"><?= e(__('mcp_gateway_ai_settings_sub', 'Connect an AI provider once — the admin AI Assistant and the customer-facing site assistant both use this.')) ?></p>
    </div>
</div>

<?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div><?php endif; ?>

<?php if (!$configured): ?>
    <div class="alert alert-warning" role="status"><?= e(__('mcp_gateway_ai_settings_not_configured', 'Not configured yet — fill in the fields below to enable the AI Assistant and the customer site assistant.')) ?></div>
<?php endif; ?>

<section class="card mb-3">
    <div class="card-header"><h2><?= e(__('mcp_gateway_ai_settings_provider_heading', 'Provider')) ?></h2></div>
    <p class="text-muted"><?= __('mcp_gateway_ai_settings_provider_desc', 'Any OpenAI-compatible <code>/chat/completions</code> endpoint works here — OpenAI itself, Anthropic through a compatible proxy, OpenRouter, a self-hosted gateway, etc.') ?></p>
    <form method="post">
        <?= csrf_field() ?>
        <div class="field">
            <label class="field-label" for="ai_base_url"><?= e(__('mcp_gateway_ai_settings_base_url_label', 'API base URL')) ?></label>
            <input type="url" id="ai_base_url" name="ai_base_url" maxlength="300"
                   value="<?= e($baseUrl) ?>" placeholder="https://api.openai.com/v1">
            <div class="field-hint"><?= __('mcp_gateway_ai_settings_base_url_hint', 'Everything up to (not including) <code>/chat/completions</code>.') ?></div>
        </div>
        <div class="field">
            <label class="field-label" for="ai_model"><?= e(__('mcp_gateway_ai_settings_model_label', 'Model')) ?></label>
            <input type="text" id="ai_model" name="ai_model" maxlength="120"
                   value="<?= e($model) ?>" placeholder="gpt-4o-mini">
        </div>
        <div class="field">
            <label class="field-label" for="ai_api_key"><?= e(__('mcp_gateway_ai_settings_api_key_label', 'API key')) ?></label>
            <input type="password" id="ai_api_key" name="ai_api_key" autocomplete="new-password"
                   placeholder="<?= $hasKey ? e(__('mcp_gateway_ai_settings_key_saved_placeholder', '••••••••  (saved — leave blank to keep)')) : 'sk-…' ?>">
            <?php if ($hasKey): ?>
                <label class="field-hint" style="display:flex;gap:6px;align-items:center;margin-top:6px;">
                    <input type="checkbox" name="clear_ai_api_key" value="1"> <?= e(__('mcp_gateway_ai_settings_remove_key', 'Remove saved key')) ?>
                </label>
            <?php endif; ?>
        </div>
        <div class="field">
            <label class="switch-label">
                <span class="switch">
                    <input type="checkbox" name="customer_assistant_enabled" value="1" <?= $customerEnabled ? 'checked' : '' ?>>
                    <span class="switch-track"></span>
                </span>
                <?= e(__('mcp_gateway_ai_settings_enable_customer_assistant', 'Enable the customer-facing site assistant')) ?>
            </label>
            <div class="field-hint"><?= e(__('mcp_gateway_ai_settings_customer_assistant_hint', "Answers visitor questions about services, plans, and their own dashboard using only this site's content — it never sees admin data and won't answer unrelated questions.")) ?></div>
        </div>
        <button type="submit" class="btn btn-primary"><?= e(__('mcp_gateway_ai_settings_save_btn', 'Save')) ?></button>
    </form>
</section>

<p class="text-sm text-muted">
    <?= sprintf(
        __('mcp_gateway_ai_settings_footer', 'The admin %1$s chat can create or edit membership plans, booking services/providers, and more via the same MCP tools an external AI client would use — every action is confirmed before it runs and logged in %2$s.'),
        '<a href="' . e(plugin_url('mcp-gateway', 'admin/chat.php')) . '">' . e(__('mcp_gateway_chat_title', 'AI Assistant')) . '</a>',
        '<a href="' . e(SLATE_URL) . '/admin/audit.php?action=mcp-gateway.">' . e(__('audit_log', 'Audit Log')) . '</a>'
    ) ?>
</p>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
