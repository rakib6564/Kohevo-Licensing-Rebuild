<?php
/**
 * Kohevo Studio — Shared Admin Navigation & SVG Icon Library.
 *
 * Provides:
 * - `sb_svg(string $name, int $size = 18)`: Zero-dependency SVG stroke outline icon renderer.
 * - `sb_render_admin_nav(string $activeSlug, ?int $portfolioPageId = null)`: Cohesive header and sub-nav.
 */

declare(strict_types=1);

require_once __DIR__ . '/_media_picker.php';

if (!function_exists('sb_svg')) {
    /**
     * Render a clean SVG stroke outline icon.
     */
    function sb_svg(string $name, int $size = 18, string $class = ''): string
    {
        $icons = [
            'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
            'pages' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
            'content' => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><line x1="12" y1="13" x2="12" y2="22"/>',
            'media' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'templates' => '<rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
            'types-fields' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
            'tag' => '<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/>',
            'themes' => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2c3 0 5.5-2.5 5.5-5.5C22 6 17.5 2 12 2z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="11.5" r="1"/>',
            'seo' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'global-settings' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'ai-mcp' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
            'reviews' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'code-tracking' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'redirects' => '<polyline points="16 3 21 3 21 8"/><line x1="4" y1="20" x2="21" y2="3"/><polyline points="21 16 21 21 16 21"/><line x1="15" y1="15" x2="21" y2="21"/><line x1="4" y1="4" x2="9" y2="9"/>',
            'zap' => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
            'external-link' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
            'plus' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'check' => '<polyline points="20 6 9 17 4 12"/>',
            'trash' => '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'eye' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'edit' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
            'layer' => '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
            'filter' => '<polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/>',
            'copy' => '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'search' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'upload' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'code' => '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
            'arrow-right' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
            'refresh' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
            'sparkles' => '<path d="M12 3v3m0 12v3M3 12h3m12 0h3m-2.6-6.4l-2.1 2.1m-6.6 6.6l-2.1 2.1m0-10.8l2.1 2.1m6.6 6.6l2.1 2.1"/>',
            'history' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'sliders' => '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
            'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'alert-circle' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
            'save' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'close' => '<line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>',
            'palette' => '<path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.6-.7 1.6-1.7 0-.4-.2-.8-.4-1.1-.3-.3-.4-.7-.4-1.1 0-.9.7-1.7 1.7-1.7h2c3 0 5.5-2.5 5.5-5.5C22 6 17.5 2 12 2z"/><circle cx="7.5" cy="11.5" r="1"/><circle cx="12" cy="7.5" r="1"/><circle cx="16.5" cy="11.5" r="1"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'box' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/>',
            'star' => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
            'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            'external' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        ];

        $path = $icons[$name] ?? $icons['overview'];
        $cls = $class !== '' ? ' class="' . htmlspecialchars($class, ENT_QUOTES) . '"' : '';

        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"' . $cls . ' aria-hidden="true">'
            . $path
            . '</svg>';
    }
}

if (!function_exists('sb_render_admin_nav')) {
    /**
     * Render the executive top header and 13 individual page navigation items.
     */
    function sb_render_admin_nav(string $activePage, ?int $portfolioPageId = null): void
    {
        $items = [
            ['id' => 'overview',        'label' => 'Overview',         'file' => 'index.php',          'icon' => 'overview'],
            ['id' => 'pages',           'label' => 'Pages',            'file' => 'pages.php',          'icon' => 'pages'],
            ['id' => 'content',         'label' => 'Content',          'file' => 'content.php',        'icon' => 'content'],
            ['id' => 'media',           'label' => 'Media',            'file' => 'media.php',          'icon' => 'media'],
            ['id' => 'templates',       'label' => 'Templates',        'file' => 'templates.php',      'icon' => 'templates'],
            ['id' => 'types-fields',    'label' => 'Types & fields',   'file' => 'types-fields.php',   'icon' => 'types-fields'],
            ['id' => 'themes',          'label' => 'Themes',           'file' => 'themes.php',         'icon' => 'themes'],
            ['id' => 'seo',             'label' => 'Site & SEO',       'file' => 'seo.php',            'icon' => 'seo'],
            ['id' => 'global-settings', 'label' => 'Global settings',  'file' => 'global-settings.php','icon' => 'global-settings'],
            ['id' => 'ai-mcp',          'label' => 'AI & MCP',         'file' => 'ai-mcp.php',         'icon' => 'ai-mcp'],
            ['id' => 'reviews',         'label' => 'Reviews',          'file' => 'reviews.php',        'icon' => 'reviews'],
            ['id' => 'code-tracking',   'label' => 'Code & tracking',  'file' => 'code-tracking.php',  'icon' => 'code-tracking'],
            ['id' => 'redirects',       'label' => 'Redirects',        'file' => 'redirects.php',      'icon' => 'redirects'],
        ];

        // Code & tracking injects scripts and CSS into every public page: administrators only.
        if (!(Auth::can('studio-builder.admin') || Auth::isSuperAdmin())) {
            $items = array_values(array_filter($items, static fn (array $item): bool => $item['id'] !== 'code-tracking'));
        }

        $targetPageId = $portfolioPageId ?? 1;
        ?>
        <style>
            :root {
                --sb-accent: #6366f1;
                --sb-border: #e2e8f0;
                --sb-text: #0f172a;
                --sb-muted: #64748b;
            }
            .sb-admin-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 16px;
                margin-bottom: 24px;
                padding-bottom: 20px;
                border-bottom: 1px solid var(--sb-border);
            }
            .sb-admin-header-title {
                display: flex;
                align-items: center;
                gap: 12px;
            }
            .sb-admin-badge-engine {
                background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
                color: #ffffff;
                font-size: 0.72rem;
                font-weight: 700;
                padding: 3px 8px;
                border-radius: 6px;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }
            .sb-admin-header-actions {
                display: flex;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
            }
            .sb-admin-nav-bar {
                display: flex;
                align-items: center;
                gap: 6px;
                overflow-x: auto;
                padding: 6px;
                background: #f1f5f9;
                border-radius: 12px;
                margin-bottom: 28px;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
            }
            .sb-admin-nav-tab {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 8px 14px;
                border-radius: 8px;
                font-size: 0.86rem;
                font-weight: 600;
                color: var(--sb-muted);
                text-decoration: none;
                white-space: nowrap;
                transition: all 0.2s ease;
            }
            .sb-admin-nav-tab:hover {
                color: var(--sb-text);
                background: rgba(255, 255, 255, 0.7);
            }
            .sb-admin-nav-tab.active {
                color: var(--sb-accent);
                background: #ffffff;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            }
            .sb-admin-nav-tab svg {
                flex-shrink: 0;
            }
            .sb-kpi-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 16px;
                margin-bottom: 28px;
            }
            .sb-kpi-card {
                background: #ffffff;
                border: 1px solid var(--sb-border);
                border-radius: 14px;
                padding: 20px;
                display: flex;
                flex-direction: column;
                box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
                transition: transform 0.2s ease, box-shadow 0.2s ease;
            }
            .sb-kpi-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 16px -4px rgba(0, 0, 0, 0.06);
            }
            .sb-kpi-top {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 8px;
            }
            .sb-kpi-label {
                font-size: 0.8rem;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: var(--sb-muted);
            }
            .sb-kpi-value {
                font-size: 1.85rem;
                font-weight: 800;
                color: var(--sb-text);
                line-height: 1.1;
                margin-bottom: 4px;
            }
            .sb-kpi-meta {
                font-size: 0.78rem;
                color: var(--sb-muted);
            }
            .sb-feature-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
                gap: 20px;
                margin-bottom: 28px;
            }
            .sb-feature-card {
                background: #ffffff;
                border: 1px solid var(--sb-border);
                border-radius: 16px;
                padding: 24px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
                box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
            }
            .sb-feature-header {
                display: flex;
                align-items: flex-start;
                gap: 14px;
                margin-bottom: 14px;
            }
            .sb-feature-icon-box {
                width: 44px;
                height: 44px;
                border-radius: 10px;
                background: rgba(99, 102, 241, 0.1);
                color: var(--sb-accent);
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }
            .sb-feature-title {
                font-size: 1.1rem;
                font-weight: 700;
                color: var(--sb-text);
                margin: 0 0 4px 0;
            }
            .sb-feature-desc {
                font-size: 0.88rem;
                color: var(--sb-muted);
                line-height: 1.5;
                margin: 0;
            }
            .sb-feature-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-top: 20px;
                padding-top: 16px;
                border-top: 1px solid var(--sb-border);
            }
            .sb-code-box {
                background: #0f172a;
                color: #cbd5e1;
                border-radius: 10px;
                padding: 16px;
                font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
                font-size: 0.85rem;
                line-height: 1.6;
                overflow-x: auto;
                margin: 12px 0;
            }
            .sb-pill-filter {
                display: flex;
                gap: 8px;
                flex-wrap: wrap;
                margin-bottom: 16px;
            }
            .sb-pill-item {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 6px 14px;
                border-radius: 9999px;
                font-size: 0.82rem;
                font-weight: 600;
                background: #f1f5f9;
                color: var(--sb-muted);
                text-decoration: none;
                transition: all 0.2s ease;
            }
            .sb-pill-item:hover,
            .sb-pill-item.active {
                background: var(--sb-accent);
                color: #ffffff;
            }
            @media (max-width: 768px) {
                .sb-admin-header {
                    flex-direction: column;
                    align-items: flex-start;
                }
                .sb-admin-header-actions {
                    width: 100%;
                }
                .sb-admin-header-actions .btn {
                    flex: 1;
                    justify-content: center;
                }
            }
        </style>

        <div class="sb-admin-header">
            <div>
                <div class="sb-admin-header-title">
                    <h1>Kohevo Studio</h1>
                    <span class="sb-admin-badge-engine">v1.0 Pro</span>
                </div>
                <p class="page-header-sub">Visual theme builder, dynamic query loop engine, custom post types & meta fields, and AI workflow hub.</p>
            </div>
            <div class="sb-admin-header-actions">
                <a href="<?= e(plugin_url('studio-builder', 'admin/builder.php') . '?page=' . $targetPageId) ?>" class="btn btn-primary" target="_blank" rel="noopener">
                    <?= sb_svg('zap', 16) ?>
                    <span>Open Canvas Builder</span>
                </a>
                <a href="<?= e(SLATE_URL . '/portfolio') ?>" class="btn btn-outline" target="_blank" rel="noopener">
                    <?= sb_svg('external-link', 16) ?>
                    <span>View Live Site</span>
                </a>
            </div>
        </div>

        <nav class="sb-admin-nav-bar" aria-label="Studio Navigation">
            <?php foreach ($items as $nav):
                $isActive = ($activePage === $nav['id']);
                $url = plugin_url('studio-builder', 'admin/' . $nav['file']);
            ?>
                <a href="<?= e($url) ?>" class="sb-admin-nav-tab <?= $isActive ? 'active' : '' ?>">
                    <?= sb_svg($nav['icon'], 16) ?>
                    <span><?= e($nav['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
    }
}
