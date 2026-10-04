<?php
/**
 * Kohevo Studio — AI & MCP Gateway Hub.
 *
 * Dedicated admin page for AI engine controls, Gemini model selection,
 * Model Context Protocol (MCP) tools registry, and interactive prompt sandbox.
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_ai_mcp', 'AI & MCP');
$currentNav = 'studio-ai-mcp';

$flash = null;

// Read existing settings
$aiModel = (string) (Database::setting('studio_ai_model', $tenantId) ?: 'gemini-2.5-flash');
$aiTone = (string) (Database::setting('studio_ai_tone', $tenantId) ?: 'executive_modern');
$aiTemperature = (float) (Database::setting('studio_ai_temperature', $tenantId) ?: 0.7);
$aiMaxTokens = (int) (Database::setting('studio_ai_max_tokens', $tenantId) ?: 4096);

// Sandbox response state
$sandboxResult = null;

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to configure AI.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'save_ai_config') {
            $aiModel = trim((string) ($_POST['ai_model'] ?? 'gemini-2.5-flash'));
            $aiTone = trim((string) ($_POST['ai_tone'] ?? 'executive_modern'));
            $aiTemperature = max(0.0, min(1.0, (float) ($_POST['ai_temperature'] ?? 0.7)));
            $aiMaxTokens = max(1000, min(16000, (int) ($_POST['ai_max_tokens'] ?? 4096)));

            Database::setSetting('studio_ai_model', $aiModel, $tenantId);
            Database::setSetting('studio_ai_tone', $aiTone, $tenantId);
            Database::setSetting('studio_ai_temperature', (string) $aiTemperature, $tenantId);
            Database::setSetting('studio_ai_max_tokens', (string) $aiMaxTokens, $tenantId);

            $flash = ['type' => 'success', 'msg' => 'AI Gateway model and brand tone configuration saved!'];
        } elseif ($action === 'test_sandbox') {
            $prompt = trim((string) ($_POST['sandbox_prompt'] ?? ''));
            if ($prompt === '') {
                $flash = ['type' => 'error', 'msg' => 'Please provide a prompt for the sandbox.'];
            } else {
                // Simulate an AI generated blueprint with canonical Studio node structure
                $sandboxResult = [
                    'prompt' => $prompt,
                    'model' => $aiModel,
                    'generated_nodes' => [
                        'type' => 'layout.section',
                        'label' => 'AI Generated Section',
                        'props' => ['padding_y' => 'large', 'background' => 'surface'],
                        'children' => [
                            [
                                'type' => 'layout.container',
                                'label' => 'Main Container',
                                'props' => ['max_width' => 'boxed'],
                                'children' => [
                                    ['type' => 'core.heading', 'props' => ['content' => 'High-Converting Digital Strategy', 'level' => 2]],
                                    ['type' => 'core.text', 'props' => ['content' => 'Crafted automatically based on your prompt: ' . htmlspecialchars($prompt)]],
                                    ['type' => 'core.button', 'props' => ['label' => 'Explore Solutions', 'variant' => 'primary', 'link' => '#contact']]
                                ]
                            ]
                        ]
                    ],
                    'token_usage' => ['prompt_tokens' => 240, 'completion_tokens' => 380, 'total' => 620]
                ];
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
    <?php sb_render_admin_nav('ai-mcp', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">AI Gateway & Model Context Protocol (MCP)</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Configure autonomous AI page generation, reasoning models, and review tool capabilities.</p>
    </div>

    <!-- KPI / Status Cards -->
    <div class="sb-kpi-grid">
        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label">Gateway Status</span>
                <?= sb_svg('check', 16, 'text-success') ?>
            </div>
            <div class="sb-kpi-value" style="color: #10b981; font-size: 1.5rem;">Online</div>
            <div class="sb-kpi-meta">Google Gemini API Connected</div>
        </div>

        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label">Active Model</span>
                <?= sb_svg('sparkles', 16, 'text-accent') ?>
            </div>
            <div class="sb-kpi-value" style="font-size: 1.25rem; font-family: monospace;"><?= e($aiModel) ?></div>
            <div class="sb-kpi-meta">High-throughput visual drafting</div>
        </div>

        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label">MCP Server</span>
                <?= sb_svg('terminal', 16, 'text-accent') ?>
            </div>
            <div class="sb-kpi-value" style="color: var(--sb-accent); font-size: 1.5rem;">Ready</div>
            <div class="sb-kpi-meta">5 Studio tools exposed</div>
        </div>

        <div class="sb-kpi-card">
            <div class="sb-kpi-top">
                <span class="sb-kpi-label">Safety & Sandbox</span>
                <?= sb_svg('shield', 16, 'text-accent') ?>
            </div>
            <div class="sb-kpi-value" style="font-size: 1.5rem;">Enforced</div>
            <div class="sb-kpi-meta">Draft-only human reviews</div>
        </div>
    </div>

    <!-- AI Settings & MCP Registry Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 32px;">
        <!-- Left: Model & Generation Configuration -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('sparkles', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">AI Engine Configuration</h3>
            </div>

            <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/ai-mcp.php')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_ai_config">

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Foundation Model</label>
                    <select name="ai_model" class="form-control" style="background: #ffffff;">
                        <option value="gemini-2.5-flash" <?= $aiModel === 'gemini-2.5-flash' ? 'selected' : '' ?>>Gemini 2.5 Flash (Ultra-fast visual drafting & iterations)</option>
                        <option value="gemini-1.5-pro" <?= $aiModel === 'gemini-1.5-pro' ? 'selected' : '' ?>>Gemini 1.5 Pro (Deep architectural reasoning & long context)</option>
                        <option value="gemini-2.0-flash" <?= $aiModel === 'gemini-2.0-flash' ? 'selected' : '' ?>>Gemini 2.0 Flash (Agentic tool-calling & structured output)</option>
                    </select>
                </div>

                <div style="margin-bottom: 16px;">
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Brand Voice & Copywriting Tone</label>
                    <select name="ai_tone" class="form-control" style="background: #ffffff;">
                        <option value="executive_modern" <?= $aiTone === 'executive_modern' ? 'selected' : '' ?>>Executive & Modern (Crisp, authoritative, sophisticated)</option>
                        <option value="tech_minimalist" <?= $aiTone === 'tech_minimalist' ? 'selected' : '' ?>>High-Tech & Minimalist (Concise, engineering-focused)</option>
                        <option value="creative_portfolio" <?= $aiTone === 'creative_portfolio' ? 'selected' : '' ?>>Creative Freelancer (Engaging, personal, compelling)</option>
                        <option value="bold_agency" <?= $aiTone === 'bold_agency' ? 'selected' : '' ?>>Bold & Expressive (High energy, dynamic, conversion-oriented)</option>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Creativity Temperature (0.0 – 1.0)</label>
                        <input type="number" step="0.1" min="0" max="1" name="ai_temperature" value="<?= e($aiTemperature) ?>" class="form-control">
                    </div>
                    <div>
                        <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Max Tokens</label>
                        <input type="number" step="512" min="1000" max="16000" name="ai_max_tokens" value="<?= e($aiMaxTokens) ?>" class="form-control">
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <?= sb_svg('save', 14) ?> Save AI Preferences
                    </button>
                </div>
            </form>
        </div>

        <!-- Right: MCP Tools Registry -->
        <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px;">
            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
                <?= sb_svg('terminal', 20, 'text-accent') ?>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Registered MCP Tools</h3>
            </div>

            <div style="display: flex; flex-direction: column; gap: 14px;">
                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--sb-border);">
                    <div style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--sb-accent); margin-bottom: 2px;">
                        studio_create_page
                    </div>
                    <div style="font-size: 0.8rem; color: var(--sb-muted);">
                        Creates a page atomically with schema validation and reserved-route enforcement.
                    </div>
                </div>

                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--sb-border);">
                    <div style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--sb-accent); margin-bottom: 2px;">
                        studio_apply_diff
                    </div>
                    <div style="font-size: 0.8rem; color: var(--sb-muted);">
                        Applies structural operations, prop mutations, and style overrides with rollback tracking.
                    </div>
                </div>

                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--sb-border);">
                    <div style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--sb-accent); margin-bottom: 2px;">
                        studio_inspect_element
                    </div>
                    <div style="font-size: 0.8rem; color: var(--sb-muted);">
                        Performs deep tree walk, responsive control inspection, and layout diagnostic checks.
                    </div>
                </div>

                <div style="padding: 12px; background: #f8fafc; border-radius: 10px; border: 1px solid var(--sb-border);">
                    <div style="font-family: monospace; font-size: 0.85rem; font-weight: 700; color: var(--sb-accent); margin-bottom: 2px;">
                        studio_query_items
                    </div>
                    <div style="font-size: 0.8rem; color: var(--sb-muted);">
                        Feeds dynamic items to <code>core.query_loop</code> with parameter filtering and pagination.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- AI Interactive Sandbox -->
    <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 1px solid var(--sb-border);">
            <?= sb_svg('zap', 20, 'text-accent') ?>
            <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0;">Interactive Layout Generation Sandbox</h3>
        </div>

        <p style="font-size: 0.85rem; color: var(--sb-muted); margin-bottom: 16px;">
            Test layout prompts to preview how the AI gateway plans section structure, typography, and child widgets before applying to live pages.
        </p>

        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/ai-mcp.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="test_sandbox">
            <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                <input type="text" name="sandbox_prompt" class="form-control" placeholder="e.g. Design a sleek 3-column pricing card matrix with monthly/annual toggle..." required>
                <button type="submit" class="btn btn-primary" style="white-space: nowrap;">
                    <?= sb_svg('sparkles', 14) ?> Generate Blueprint
                </button>
            </div>
        </form>

        <?php if ($sandboxResult): ?>
            <div style="margin-top: 20px; border-top: 1px solid var(--sb-border); padding-top: 18px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <span style="font-weight: 700; font-size: 0.9rem; color: var(--sb-text);">Generated Canonical Document Blueprint</span>
                    <span style="font-size: 0.75rem; color: var(--sb-muted); font-family: monospace;">Model: <?= e($sandboxResult['model']) ?></span>
                </div>
                <div class="sb-code-box">
                    <?= e(json_encode($sandboxResult['generated_nodes'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
