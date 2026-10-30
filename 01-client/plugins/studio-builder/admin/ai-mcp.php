<?php
/**
 * Kohevo Studio — AI & MCP.
 *
 * What an AI assistant connected through the MCP gateway can do on this Studio site: every tool, straight from
 * `StudioMcpToolCatalog`, with what it can change and the permission it needs, and where its tokens are managed.
 * Read only: there is no model or prompt configuration here, because Studio does not call an AI model itself.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Module\StudioBuilder\Mcp\StudioMcpToolCatalog;

Auth::require();
Auth::requirePerm('studio-builder.view');

$pageTitle = __('studio_ai_mcp', 'AI & MCP');
$currentNav = 'studio-ai-mcp';

$tools = StudioMcpToolCatalog::tools();
ksort($tools);
$byAccess = [StudioMcpToolCatalog::ACCESS_READ => 0, StudioMcpToolCatalog::ACCESS_WRITE => 0, StudioMcpToolCatalog::ACCESS_DESTRUCTIVE => 0];
$confirmed = 0;
foreach ($tools as $tool) {
    $byAccess[$tool['access']] = ($byAccess[$tool['access']] ?? 0) + 1;
    $confirmed += $tool['requires_confirmation'] ? 1 : 0;
}

$gatewayActive = class_exists('PluginLoader') && PluginLoader::isActive('mcp-gateway');
$gatewayUrl = $gatewayActive ? plugin_url('mcp-gateway', 'admin/index.php') : null;

$accessLabel = static fn(string $access): array => match ($access) {
    StudioMcpToolCatalog::ACCESS_READ        => [__('studio_ai_access_read', 'Reads'), 'muted'],
    StudioMcpToolCatalog::ACCESS_DESTRUCTIVE => [__('studio_ai_access_destructive', 'Can remove or overwrite'), 'danger'],
    default                                  => [__('studio_ai_access_write', 'Changes drafts'), 'info'],
};

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin">

    <div class="sb-page-head">
        <h2><?= e(__('studio_ai_heading', 'AI assistants (MCP)')) ?></h2>
        <p><?= e(__('studio_ai_intro', 'An AI assistant connected through the MCP gateway works on this site with the tools below, within the permissions of the token it uses. Studio does not run an AI model itself.')) ?></p>
    </div>

    <div class="sb-kpi-grid">
        <div class="sb-kpi-card">
            <div class="sb-kpi-top"><span class="sb-kpi-label"><?= e(__('studio_ai_tools', 'Tools')) ?></span><?= sb_svg('terminal', 16, 'text-accent') ?></div>
            <div class="sb-kpi-value"><?= count($tools) ?></div>
            <div class="sb-kpi-meta"><?= e(sprintf(__('studio_ai_tools_meta', '%1$d read, %2$d change drafts, %3$d can remove or overwrite'), $byAccess['read'], $byAccess['write'], $byAccess['destructive'])) ?></div>
        </div>
        <div class="sb-kpi-card">
            <div class="sb-kpi-top"><span class="sb-kpi-label"><?= e(__('studio_ai_confirm', 'Need confirmation')) ?></span><?= sb_svg('shield', 16, 'text-accent') ?></div>
            <div class="sb-kpi-value"><?= $confirmed ?></div>
            <div class="sb-kpi-meta"><?= e(__('studio_ai_confirm_meta', 'The assistant must ask you before using these')) ?></div>
        </div>
        <div class="sb-kpi-card">
            <div class="sb-kpi-top"><span class="sb-kpi-label"><?= e(__('studio_ai_gateway', 'MCP gateway')) ?></span><?= sb_svg($gatewayActive ? 'check' : 'close', 16, $gatewayActive ? 'text-success' : '') ?></div>
            <div class="sb-kpi-value" style="font-size: 1.25rem;"><?= e($gatewayActive ? __('studio_ai_gateway_on', 'Installed') : __('studio_ai_gateway_off', 'Not installed')) ?></div>
            <div class="sb-kpi-meta">
                <?php if ($gatewayUrl !== null): ?>
                    <a href="<?= e($gatewayUrl) ?>"><?= e(__('studio_ai_gateway_tokens', 'Create and revoke tokens')) ?></a>
                <?php else: ?>
                    <?= e(__('studio_ai_gateway_none', 'Install the MCP Gateway plugin to connect an assistant')) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sb-card">
        <div class="sb-card__head">
            <span class="sb-card__icon"><?= sb_svg('shield', 18) ?></span>
            <h3 class="sb-card__title"><?= e(__('studio_ai_rules', 'What an assistant can never do')) ?></h3>
        </div>
        <ul style="margin: 0; padding-left: 18px; font-size: 0.88rem; color: var(--sb-muted); line-height: 1.6;">
            <li><?= e(__('studio_ai_rule_drafts', 'Its changes are saved as drafts. A person checks them (History › AI drafts) and publishes.')) ?></li>
            <li><?= e(__('studio_ai_rule_live', 'It cannot rename, move or delete a published page, whatever its token allows.')) ?></li>
            <li><?= e(__('studio_ai_rule_locks', 'It cannot change a locked section or block.')) ?></li>
            <li><?= e(__('studio_ai_rule_stale', 'Every change names the revision it is based on; a stale change is refused and nothing is overwritten.')) ?></li>
        </ul>
    </div>

    <div class="data-list is-columnar" data-single-open style="margin-bottom: 32px;">
        <?php
        slate_data_list_head(['main' => __('studio_ai_tool', 'Tool'), 'value' => __('studio_ai_permission', 'Permission'), 'badge' => __('studio_ai_access', 'Access')]);
        foreach ($tools as $name => $tool) {
            [$label, $tone] = $accessLabel($tool['access']);
            slate_data_row([
                'avatar'       => strtoupper(substr($tool['access'], 0, 1)),
                'avatar_color' => $tone === 'danger' ? 'danger' : ($tone === 'info' ? 'info' : 'success'),
                'title'        => $name,
                'meta'         => $tool['description'],
                'value'        => $tool['permission'],
                'badge'        => [$label, $tone === 'danger' ? 'danger' : ($tone === 'info' ? 'info' : 'muted')],
                'detail'       => [
                    'description' => ['label' => __('studio_ai_what', 'What it does'), 'value' => $tool['description']],
                    'confirm'     => ['label' => __('studio_ai_confirm', 'Need confirmation'), 'value' => $tool['requires_confirmation'] ? __('yes', 'Yes') : __('no', 'No')],
                    'scope'       => ['label' => __('studio_ai_scope', 'Token scope'), 'html' => '<code>' . e($tool['scope']) . '</code>'],
                ],
            ]);
        }
        ?>
    </div>
    <?php slate_data_list_script(); ?>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
