<?php
/**
 * Kohevo Studio — Themes & Design Tokens Architect.
 *
 * Dedicated admin page for theme presets (Obsidian Dark, Editorial Light,
 * Cyberpunk Electric, Corporate Slate) and design tokens customizer
 * (colors, typography, radii, spacing).
 */

declare(strict_types=1);

require __DIR__ . '/../../../config.php';
require_once __DIR__ . '/_nav.php';

Auth::require();
Auth::requirePerm('studio-builder.view');

$canEdit = Auth::can('studio-builder.edit') || Auth::isSuperAdmin();
$tenantId = current_tenant_id();

$pageTitle = __('studio_themes', 'Themes');
$currentNav = 'studio-themes';

$flash = null;

// Presets
$themePresets = [
    'obsidian_dark' => [
        'id' => 'obsidian_dark',
        'name' => 'Obsidian Dark (Freelancer Pro)',
        'description' => 'Sophisticated deep dark palette with luminous indigo accents, tailored for modern creative developers.',
        'accent' => '#6366f1',
        'accent_secondary' => '#8b5cf6',
        'bg_base' => '#0b0f19',
        'bg_surface' => '#131c2e',
        'text_body' => '#f1f5f9',
        'text_muted' => '#94a3b8',
        'font_heading' => 'Plus Jakarta Sans',
        'font_body' => 'Inter',
        'radius' => '12px',
    ],
    'editorial_light' => [
        'id' => 'editorial_light',
        'name' => 'Editorial Minimal Light',
        'description' => 'Crisp, high-contrast black-and-white editorial aesthetic with electric blue links.',
        'accent' => '#2563eb',
        'accent_secondary' => '#3b82f6',
        'bg_base' => '#ffffff',
        'bg_surface' => '#f8fafc',
        'text_body' => '#0f172a',
        'text_muted' => '#64748b',
        'font_heading' => 'Syne',
        'font_body' => 'Inter',
        'radius' => '8px',
    ],
    'cyberpunk_electric' => [
        'id' => 'cyberpunk_electric',
        'name' => 'Cyberpunk Electric',
        'description' => 'High-octane neon emerald and cyan glow against pure void black for Web3 and AI studios.',
        'accent' => '#10b981',
        'accent_secondary' => '#06b6d4',
        'bg_base' => '#05070d',
        'bg_surface' => '#0d131f',
        'text_body' => '#e2e8f0',
        'text_muted' => '#64748b',
        'font_heading' => 'Space Grotesk',
        'font_body' => 'Space Grotesk',
        'radius' => '6px',
    ],
    'corporate_slate' => [
        'id' => 'corporate_slate',
        'name' => 'Corporate Slate',
        'description' => 'Trustworthy executive navy and refined gray surfaces for enterprise agencies and consultants.',
        'accent' => '#0284c7',
        'accent_secondary' => '#0369a1',
        'bg_base' => '#0f172a',
        'bg_surface' => '#1e293b',
        'text_body' => '#f8fafc',
        'text_muted' => '#94a3b8',
        'font_heading' => 'Outfit',
        'font_body' => 'Plus Jakarta Sans',
        'radius' => '10px',
    ],
];

// Active Theme from Database / Settings
$activeThemeKey = (string) (Database::setting('studio_active_theme', $tenantId) ?: 'obsidian_dark');
if (!isset($themePresets[$activeThemeKey])) {
    $activeThemeKey = 'obsidian_dark';
}

// Current Custom Tokens from DB
$tokensRow = Database::row("SELECT tokens_json FROM studiobuilder_tokens WHERE tenant_id = ? AND token_group = 'global' LIMIT 1", [$tenantId]);
$savedTokens = $tokensRow ? json_decode((string) $tokensRow['tokens_json'], true) : [];
if (!is_array($savedTokens)) {
    $savedTokens = [];
}

$currentPreset = $themePresets[$activeThemeKey];
$activeTokens = array_merge($currentPreset, $savedTokens);

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed.')];
    } elseif (!$canEdit) {
        $flash = ['type' => 'error', 'msg' => __('forbidden', 'You do not have permission to edit themes.')];
    } else {
        $action = (string) ($_POST['_action'] ?? '');

        if ($action === 'activate_theme') {
            $selectedTheme = trim((string) ($_POST['theme_key'] ?? 'obsidian_dark'));
            if (isset($themePresets[$selectedTheme])) {
                Database::setSetting('studio_active_theme', $selectedTheme, $tenantId);
                $activeThemeKey = $selectedTheme;
                $activeTokens = $themePresets[$selectedTheme];

                // Upsert into studiobuilder_tokens
                Database::query(
                    "INSERT INTO studiobuilder_tokens (tenant_id, token_group, schema_version, tokens_json, compiled_css_vars, updated_by, updated_at)
                     VALUES (?, 'global', 1, ?, '', ?, NOW())
                     ON DUPLICATE KEY UPDATE tokens_json = VALUES(tokens_json), updated_at = NOW()",
                    [
                        $tenantId,
                        json_encode($activeTokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        (int) (Auth::id() ?? 1)
                    ]
                );

                $flash = ['type' => 'success', 'msg' => "Theme '{$themePresets[$selectedTheme]['name']}' activated successfully!"];
            }
        } elseif ($action === 'save_tokens') {
            $accent = trim((string) ($_POST['accent'] ?? '#6366f1'));
            $accentSec = trim((string) ($_POST['accent_secondary'] ?? '#8b5cf6'));
            $bgBase = trim((string) ($_POST['bg_base'] ?? '#0b0f19'));
            $bgSurface = trim((string) ($_POST['bg_surface'] ?? '#131c2e'));
            $textBody = trim((string) ($_POST['text_body'] ?? '#f1f5f9'));
            $textMuted = trim((string) ($_POST['text_muted'] ?? '#94a3b8'));
            $fontHeading = trim((string) ($_POST['font_heading'] ?? 'Plus Jakarta Sans'));
            $fontBody = trim((string) ($_POST['font_body'] ?? 'Inter'));
            $radius = trim((string) ($_POST['radius'] ?? '12px'));

            $customTokens = [
                'accent' => $accent,
                'accent_secondary' => $accentSec,
                'bg_base' => $bgBase,
                'bg_surface' => $bgSurface,
                'text_body' => $textBody,
                'text_muted' => $textMuted,
                'font_heading' => $fontHeading,
                'font_body' => $fontBody,
                'radius' => $radius,
            ];

            // Upsert into studiobuilder_tokens
            Database::query(
                "INSERT INTO studiobuilder_tokens (tenant_id, token_group, schema_version, tokens_json, compiled_css_vars, updated_by, updated_at)
                 VALUES (?, 'global', 1, ?, '', ?, NOW())
                 ON DUPLICATE KEY UPDATE tokens_json = VALUES(tokens_json), updated_at = NOW()",
                [
                    $tenantId,
                    json_encode($customTokens, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    (int) (Auth::id() ?? 1)
                ]
            );

            $activeTokens = array_merge($activeTokens, $customTokens);
            $flash = ['type' => 'success', 'msg' => 'Design tokens saved and recompiled!'];
        }
    }
}

// Portfolio page ID
$portfolioPage = Database::row("SELECT id FROM studiobuilder_pages WHERE tenant_id = ? AND slug = 'portfolio' LIMIT 1", [$tenantId]);
$portfolioPageId = $portfolioPage ? (int) $portfolioPage['id'] : null;

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="content-wrapper">
    <?php sb_render_admin_nav('themes', $portfolioPageId); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>" style="margin-bottom: 24px; border-radius: 10px;">
            <?= e($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- Title & Overview -->
    <div style="margin-bottom: 24px;">
        <h2 style="font-size: 1.3rem; font-weight: 800; color: var(--sb-text); margin: 0 0 4px 0;">Theme Presets & Design System</h2>
        <p style="font-size: 0.88rem; color: var(--sb-muted); margin: 0;">Switch cohesive visual theme suites or fine-tune design tokens compiled straight to CSS variables.</p>
    </div>

    <!-- Theme Selection Grid -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 20px; margin-bottom: 36px;">
        <?php foreach ($themePresets as $key => $preset):
            $isActive = ($key === $activeThemeKey);
        ?>
            <div class="card" style="border-radius: 14px; border: 2px solid <?= $isActive ? 'var(--sb-accent)' : 'var(--sb-border)' ?>; padding: 20px; display: flex; flex-direction: column; justify-content: space-between; position: relative; box-shadow: <?= $isActive ? '0 4px 14px rgba(99, 102, 241, 0.15)' : 'none' ?>;">
                <?php if ($isActive): ?>
                    <span style="position: absolute; top: -11px; right: 16px; background: var(--sb-accent); color: #ffffff; font-size: 0.72rem; font-weight: 700; padding: 2px 8px; border-radius: 9999px; text-transform: uppercase;">
                        Active Theme
                    </span>
                <?php endif; ?>

                <div>
                    <!-- Palette Preview Swatch -->
                    <div style="height: 52px; border-radius: 8px; margin-bottom: 14px; display: flex; overflow: hidden; border: 1px solid rgba(0,0,0,0.1);">
                        <div style="flex: 2; background: <?= e($preset['bg_base']) ?>;"></div>
                        <div style="flex: 1.5; background: <?= e($preset['bg_surface']) ?>;"></div>
                        <div style="flex: 1; background: <?= e($preset['accent']) ?>;"></div>
                        <div style="flex: 1; background: <?= e($preset['accent_secondary']) ?>;"></div>
                    </div>

                    <h3 style="font-size: 1.05rem; font-weight: 700; color: var(--sb-text); margin: 0 0 6px 0;"><?= e($preset['name']) ?></h3>
                    <p style="font-size: 0.82rem; color: var(--sb-muted); line-height: 1.4; margin: 0 0 16px 0;">
                        <?= e($preset['description']) ?>
                    </p>
                </div>

                <div style="border-top: 1px solid var(--sb-border); padding-top: 14px; display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.75rem; color: var(--sb-muted); font-family: monospace;">
                        <?= e($preset['font_heading']) ?>
                    </span>
                    <?php if ($isActive): ?>
                        <span class="btn btn-sm btn-outline" style="color: var(--sb-accent); border-color: var(--sb-accent); cursor: default;">
                            <?= sb_svg('check', 12) ?> Active
                        </span>
                    <?php elseif ($canEdit): ?>
                        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/themes.php')) ?>" style="margin: 0;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="_action" value="activate_theme">
                            <input type="hidden" name="theme_key" value="<?= e($key) ?>">
                            <button type="submit" class="btn btn-sm btn-primary">
                                Apply Theme
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Design Tokens Editor -->
    <div class="card" style="border-radius: 16px; border: 1px solid var(--sb-border); padding: 24px; margin-bottom: 32px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 14px; border-bottom: 1px solid var(--sb-border);">
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 700; color: var(--sb-text); margin: 0 0 4px 0;">Design Tokens Customizer</h3>
                <p style="font-size: 0.85rem; color: var(--sb-muted); margin: 0;">Directly configure global variables utilized by Studio sections, containers, and widgets.</p>
            </div>
        </div>

        <form method="POST" action="<?= e(plugin_url('studio-builder', 'admin/themes.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="_action" value="save_tokens">

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
                <!-- Primary Accent -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Primary Accent (<code>--sb-accent</code>)</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="color" name="accent" value="<?= e($activeTokens['accent']) ?>" style="width: 40px; height: 38px; border: none; border-radius: 6px; cursor: pointer; padding: 0;">
                        <input type="text" value="<?= e($activeTokens['accent']) ?>" class="form-control" style="font-family: monospace;" readonly>
                    </div>
                </div>

                <!-- Secondary Accent -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Secondary Accent</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="color" name="accent_secondary" value="<?= e($activeTokens['accent_secondary']) ?>" style="width: 40px; height: 38px; border: none; border-radius: 6px; cursor: pointer; padding: 0;">
                        <input type="text" value="<?= e($activeTokens['accent_secondary']) ?>" class="form-control" style="font-family: monospace;" readonly>
                    </div>
                </div>

                <!-- Background Base -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Background Base</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="color" name="bg_base" value="<?= e($activeTokens['bg_base']) ?>" style="width: 40px; height: 38px; border: none; border-radius: 6px; cursor: pointer; padding: 0;">
                        <input type="text" value="<?= e($activeTokens['bg_base']) ?>" class="form-control" style="font-family: monospace;" readonly>
                    </div>
                </div>

                <!-- Surface Background -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Card / Surface Background</label>
                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="color" name="bg_surface" value="<?= e($activeTokens['bg_surface']) ?>" style="width: 40px; height: 38px; border: none; border-radius: 6px; cursor: pointer; padding: 0;">
                        <input type="text" value="<?= e($activeTokens['bg_surface']) ?>" class="form-control" style="font-family: monospace;" readonly>
                    </div>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 28px;">
                <!-- Heading Font -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Heading Typography</label>
                    <select name="font_heading" class="form-control" style="background: #ffffff;">
                        <?php foreach (['Plus Jakarta Sans', 'Inter', 'Syne', 'Outfit', 'Space Grotesk'] as $font): ?>
                            <option value="<?= e($font) ?>" <?= ($activeTokens['font_heading'] === $font) ? 'selected' : '' ?>><?= e($font) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Body Font -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Body Typography</label>
                    <select name="font_body" class="form-control" style="background: #ffffff;">
                        <?php foreach (['Inter', 'Plus Jakarta Sans', 'system-ui', 'Space Grotesk'] as $font): ?>
                            <option value="<?= e($font) ?>" <?= ($activeTokens['font_body'] === $font) ? 'selected' : '' ?>><?= e($font) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Border Radius -->
                <div>
                    <label class="form-label" style="font-weight: 600; font-size: 0.85rem;">Border Radius Scale</label>
                    <select name="radius" class="form-control" style="background: #ffffff;">
                        <option value="0px" <?= ($activeTokens['radius'] === '0px') ? 'selected' : '' ?>>Sharp (0px)</option>
                        <option value="6px" <?= ($activeTokens['radius'] === '6px') ? 'selected' : '' ?>>Compact (6px)</option>
                        <option value="10px" <?= ($activeTokens['radius'] === '10px') ? 'selected' : '' ?>>Modern (10px)</option>
                        <option value="14px" <?= ($activeTokens['radius'] === '14px') ? 'selected' : '' ?>>Soft (14px)</option>
                        <option value="20px" <?= ($activeTokens['radius'] === '20px') ? 'selected' : '' ?>>Round (20px)</option>
                    </select>
                </div>
            </div>

            <!-- Live Sandbox Preview -->
            <div style="background: <?= e($activeTokens['bg_base']) ?>; border-radius: <?= e($activeTokens['radius']) ?>; padding: 24px; margin-bottom: 24px; border: 1px solid rgba(255,255,255,0.1);">
                <div style="font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: <?= e($activeTokens['text_muted']) ?>; margin-bottom: 8px;">
                    Interactive Token Preview
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                    <div>
                        <h4 style="font-family: '<?= e($activeTokens['font_heading']) ?>', sans-serif; font-size: 1.35rem; font-weight: 800; color: <?= e($activeTokens['text_body']) ?>; margin: 0 0 4px 0;">
                            Executive Creative Portfolio
                        </h4>
                        <p style="font-family: '<?= e($activeTokens['font_body']) ?>', sans-serif; font-size: 0.85rem; color: <?= e($activeTokens['text_muted']) ?>; margin: 0;">
                            Live dynamic styling preview applying active background, surface, and typography variables.
                        </p>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" style="background: <?= e($activeTokens['accent']) ?>; color: #ffffff; border: none; border-radius: <?= e($activeTokens['radius']) ?>; padding: 8px 16px; font-weight: 600; font-size: 0.85rem; cursor: pointer;">
                            Primary CTA
                        </button>
                        <button type="button" style="background: <?= e($activeTokens['bg_surface']) ?>; color: <?= e($activeTokens['text_body']) ?>; border: 1px solid rgba(255,255,255,0.1); border-radius: <?= e($activeTokens['radius']) ?>; padding: 8px 16px; font-weight: 600; font-size: 0.85rem; cursor: pointer;">
                            Surface Secondary
                        </button>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <?= sb_svg('save', 14) ?> Save Design Tokens
                </button>
            </div>
        </form>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
