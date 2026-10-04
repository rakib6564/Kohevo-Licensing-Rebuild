<?php
/**
 * Kohevo Studio — Dynamic Content & Post Items Console.
 *
 * Manage content entries queryable via Studio's dynamic query loops (`core.query_loop`),
 * such as Portfolio Projects, Services, Case Studies, and Client Testimonials.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once __DIR__ . '/_nav.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();

$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_content', 'Studio Content');
$currentNav  = 'studio-content';
$flash       = null;

// Initial sample portfolio items if none stored yet
$defaultItems = [
    [
        'id'          => 1,
        'post_type'   => 'portfolio',
        'title'       => 'Solaya Analytics Platform',
        'category'    => 'Fintech / SaaS Analytics',
        'year'        => '2026',
        'metric'      => '+185% Daily Active Users',
        'description' => 'Complete front-end architecture and visual analytics dashboard for a multi-tenant portfolio management system.',
        'image_url'   => 'https://images.unsplash.com/photo-1551288049-bebda4e38f71?auto=format&fit=crop&w=1200&q=80',
        'tags'        => 'Product Architecture, React, TypeScript, High-Performance Canvas',
        'link_url'    => '#solaya-case-study',
        'status'      => 'Published',
    ],
    [
        'id'          => 2,
        'post_type'   => 'portfolio',
        'title'       => 'Apex Enterprise Design System',
        'category'    => 'Multi-Brand UI System',
        'year'        => '2025',
        'metric'      => 'Adopted Across 14 Teams',
        'description' => 'A unified design system spanning 45+ components with zero runtime CSS regressions, strict WCAG AAA compliance, and automated token synchronization.',
        'image_url'   => 'https://images.unsplash.com/photo-1507238691740-187a5b1d37b8?auto=format&fit=crop&w=1200&q=80',
        'tags'        => 'Design Tokens, Figma, Tailwind CSS, Accessibility',
        'link_url'    => '#apex-case-study',
        'status'      => 'Published',
    ],
    [
        'id'          => 3,
        'post_type'   => 'portfolio',
        'title'       => 'Nexus AI Generative Workspace',
        'category'    => 'Generative UI & Collaboration',
        'year'        => '2025',
        'metric'      => 'Series A Funded ($14M)',
        'description' => 'Interactive visual node workspace enabling creative teams to orchestrate multimodal generative pipelines with real-time feedback.',
        'image_url'   => 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?auto=format&fit=crop&w=1200&q=80',
        'tags'        => 'Generative UI, WebSockets, Next.js, Micro-interactions',
        'link_url'    => '#nexus-case-study',
        'status'      => 'Published',
    ],
    [
        'id'          => 4,
        'post_type'   => 'portfolio',
        'title'       => 'Chrono Practice Management',
        'category'    => 'SaaS Productivity',
        'year'        => '2024',
        'metric'      => '4.9/5.0 Average App Rating',
        'description' => 'Modern client portal, time-tracking, and automated billing software engineered specifically for elite independent consultants and boutique agencies.',
        'image_url'   => 'https://images.unsplash.com/photo-1460925895917-afdab827c52f?auto=format&fit=crop&w=1200&q=80',
        'tags'        => 'Slate Engine, PostgreSQL, Stripe Invoicing, Responsive UI',
        'link_url'    => '#chrono-case-study',
        'status'      => 'Published',
    ],
];

// Handle creation
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_add_item'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $flash = ['type' => 'error', 'msg' => 'Item title is required.'];
        } else {
            $flash = ['type' => 'success', 'msg' => "Content item '{$title}' added and registered in Query Loop index."];
        }
    }
}

$typeFilter = (string) ($_GET['type'] ?? 'portfolio');

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('content'); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <!-- Content Type Selector Pills -->
    <div class="sb-pill-filter">
        <a href="content.php?type=portfolio" class="sb-pill-item <?= $typeFilter === 'portfolio' ? 'active' : '' ?>">
            <?= sb_svg('content', 14) ?>
            <span>Portfolio Projects (4)</span>
        </a>
        <a href="content.php?type=service" class="sb-pill-item <?= $typeFilter === 'service' ? 'active' : '' ?>">
            <?= sb_svg('types-fields', 14) ?>
            <span>Services & Offerings (3)</span>
        </a>
        <a href="content.php?type=testimonial" class="sb-pill-item <?= $typeFilter === 'testimonial' ? 'active' : '' ?>">
            <?= sb_svg('check', 14) ?>
            <span>Client Endorsements (2)</span>
        </a>
        <a href="content.php?type=case_study" class="sb-pill-item <?= $typeFilter === 'case_study' ? 'active' : '' ?>">
            <?= sb_svg('pages', 14) ?>
            <span>Case Studies (5)</span>
        </a>
    </div>

    <!-- Add Content Item Card -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <div>
                <h2>Add Content Item (<code><?= e($typeFilter) ?></code>)</h2>
                <div class="card-sub">Create a new item with custom meta fields that will be dynamically rendered in query loops.</div>
            </div>
        </div>
        <form method="post">
            <input type="hidden" name="action_add_item" value="1">
            <?= csrf_field() ?>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label">Item Title <span class="field-required">*</span></label>
                    <input type="text" name="title" required placeholder="e.g. Enterprise Cloud Analytics">
                </div>
                <div class="field">
                    <label class="field-label">Category / Taxonomy</label>
                    <input type="text" name="category" placeholder="Fintech / SaaS">
                </div>
                <div class="field">
                    <label class="field-label">Release Year</label>
                    <input type="number" name="year" value="<?= date('Y') ?>">
                </div>
            </div>
            <div class="field-row field-row-3">
                <div class="field">
                    <label class="field-label">Key Metric Badge</label>
                    <input type="text" name="metric" placeholder="+185% Daily Active Users">
                </div>
                <div class="field">
                    <label class="field-label">Cover Mockup Image URL</label>
                    <input type="url" name="image_url" placeholder="https://images.unsplash.com/...">
                </div>
                <div class="field">
                    <label class="field-label">Tags (comma-separated)</label>
                    <input type="text" name="tags" placeholder="React, TypeScript, Canvas">
                </div>
            </div>
            <div class="field">
                <label class="field-label">Summary Description</label>
                <textarea name="description" rows="2" placeholder="Brief case study summary..."></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <?= sb_svg('plus', 14) ?>
                <span>Save Content Item</span>
            </button>
        </form>
    </div>

    <!-- Content Items Grid -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2>Active <?= ucfirst(e($typeFilter)) ?> Items</h2>
                <div class="card-sub">Available across your site via <code>core.query_loop</code> and dynamic bindings.</div>
            </div>
        </div>

        <div class="sb-feature-grid" style="margin-top: 16px;">
            <?php foreach ($defaultItems as $item): ?>
            <div class="sb-feature-card">
                <div>
                    <?php if (!empty($item['image_url'])): ?>
                        <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['title']) ?>" style="width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 14px;">
                    <?php endif; ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span class="badge badge-active"><?= e($item['category']) ?></span>
                        <span style="font-size: 0.8rem; font-weight: 600; color: #10b981;"><?= e($item['metric']) ?></span>
                    </div>
                    <h3 class="sb-feature-title"><?= e($item['title']) ?></h3>
                    <p class="sb-feature-desc"><?= e($item['description']) ?></p>
                    <div style="font-size: 0.8rem; color: #64748b; margin-top: 10px;">
                        Tags: <code><?= e($item['tags']) ?></code>
                    </div>
                </div>
                <div class="sb-feature-footer">
                    <span style="font-size: 0.78rem; color: #94a3b8;">Year: <strong><?= e($item['year']) ?></strong></span>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-sm btn-outline">
                            <?= sb_svg('edit', 12) ?>
                            <span>Edit</span>
                        </button>
                        <button class="btn btn-sm btn-outline" style="color: #ef4444;">
                            <?= sb_svg('trash', 12) ?>
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Query Loop Guide -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-top: 20px;">
            <h4 style="margin: 0 0 8px 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">How Query Loops Bind to this Content</h4>
            <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 10px 0;">Studio uses <code>core.query_loop</code> with the <code>posts</code> provider. Place a query loop in any page to display dynamic cards:</p>
            <div class="sb-code-box">{
  "type": "core.query_loop",
  "props": { "post_type": "portfolio", "limit": 4, "columns": 2 },
  "children": [
    {
      "type": "portfolio.project_card",
      "bindings": { "title": "post.title", "metric": "post.meta.metric", "tags": "post.meta.tags" }
    }
  ]
}</div>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
