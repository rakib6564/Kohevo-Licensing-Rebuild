<?php
/**
 * Kohevo Studio — Dynamic Content & Post Items Console.
 *
 * Manage content entries queryable via Studio's dynamic query loops (`core.query_loop`)
 * and rendered across the complete freelancer portfolio multi-page site:
 * - Profile & Brand Bio
 * - Portfolio Projects
 * - Services & Offerings
 * - Client Endorsements / Testimonials
 * - Case Studies
 *
 * Zero emojis allowed — only clean SVG stroke outline icons.
 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 2) . '/studio-freelancer-portfolio/StudioFreelancerPortfolio.php';
require_once __DIR__ . '/_nav.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;

Auth::require();

$studioActor = StudioActor::fromCurrentSession();
$pageTitle   = __('studio_content', 'Studio Content');
$currentNav  = 'studio-content';
$flash       = null;

$tenantId = (int) (Auth::user()['tenant_id'] ?? (defined('TENANT_ID') ? TENANT_ID : 1));
$typeFilter = (string) ($_GET['type'] ?? 'portfolio');
if (!in_array($typeFilter, ['portfolio', 'service', 'testimonial', 'case_study', 'profile'], true)) {
    $typeFilter = 'portfolio';
}

// ── Handle Action: Sync & Publish Entire Site ───────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_publish_site'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed. Please refresh and try again.'];
    } else {
        try {
            $results = StudioFreelancerPortfolio::publishEntireSite($tenantId, $studioActor->userId());
            $flash = [
                'type' => 'success',
                'msg'  => 'Entire site compiled and published successfully with live dynamic data! All 5 routes are active.',
                'published_routes' => array_keys($results),
            ];
        } catch (\Throwable $e) {
            $flash = ['type' => 'error', 'msg' => 'Site publication failed: ' . $e->getMessage()];
        }
    }
}

// ── Handle Action: Save Profile ─────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_save_profile'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') {
            $flash = ['type' => 'error', 'msg' => 'Full Name is required.'];
        } else {
            $profileData = [
                'name'          => $name,
                'monogram'      => strtoupper(trim((string) ($_POST['monogram'] ?? '')) ?: substr($name, 0, 2)),
                'role'          => trim((string) ($_POST['role'] ?? 'Principal Product Engineer & Architect')),
                'status'        => trim((string) ($_POST['status'] ?? 'Available for Select Product Engagements')),
                'hero_heading'  => trim((string) ($_POST['hero_heading'] ?? 'Designing & Engineering Category-Defining Digital Products.')),
                'hero_subtext'  => trim((string) ($_POST['hero_subtext'] ?? '')),
                'stat_years'    => trim((string) ($_POST['stat_years'] ?? '9+')),
                'stat_apps'     => trim((string) ($_POST['stat_apps'] ?? '42+')),
                'stat_val'      => trim((string) ($_POST['stat_val'] ?? '$180M+')),
                'stat_delivery' => trim((string) ($_POST['stat_delivery'] ?? '100%')),
                'location'      => trim((string) ($_POST['location'] ?? 'San Francisco, CA · Remote Worldwide')),
                'email'         => trim((string) ($_POST['email'] ?? 'contact@rakibhasaan.com')),
            ];
            Database::setSetting('studio_profile', json_encode($profileData), $tenantId);
            $flash = ['type' => 'success', 'msg' => 'Brand profile and bio updated successfully.'];
        }
    }
}

// ── Handle Action: Add Item ─────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_add_item'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '' && $typeFilter !== 'testimonial') {
            $flash = ['type' => 'error', 'msg' => 'Title is required.'];
        } elseif ($typeFilter === 'testimonial' && trim((string) ($_POST['author'] ?? '')) === '') {
            $flash = ['type' => 'error', 'msg' => 'Author name is required.'];
        } else {
            if ($typeFilter === 'portfolio' || $typeFilter === 'case_study') {
                $items = StudioFreelancerPortfolio::getProjects($tenantId);
                $newItem = [
                    'title'       => $title,
                    'category'    => trim((string) ($_POST['category'] ?? 'Fintech / SaaS')),
                    'year'        => trim((string) ($_POST['year'] ?? date('Y'))),
                    'metric'      => trim((string) ($_POST['metric'] ?? '')),
                    'description' => trim((string) ($_POST['description'] ?? '')),
                    'image_url'   => trim((string) ($_POST['image_url'] ?? '')),
                    'tags'        => trim((string) ($_POST['tags'] ?? '')),
                    'link_url'    => trim((string) ($_POST['link_url'] ?? '/case-studies')),
                    'link_text'   => 'View Case Study →',
                ];
                array_unshift($items, $newItem);
                Database::setSetting('studio_content_portfolio', json_encode($items), $tenantId);
                $flash = ['type' => 'success', 'msg' => "Project '{$title}' saved to dynamic data repository."];
            } elseif ($typeFilter === 'service') {
                $items = StudioFreelancerPortfolio::getServicesList($tenantId);
                $nextNum = str_pad((string) (count($items) + 1), 2, '0', STR_PAD_LEFT);
                $newItem = [
                    'number'       => trim((string) ($_POST['number'] ?? $nextNum)),
                    'title'        => $title,
                    'description'  => trim((string) ($_POST['description'] ?? '')),
                    'deliverables' => trim((string) ($_POST['deliverables'] ?? '')),
                ];
                $items[] = $newItem;
                Database::setSetting('studio_content_service', json_encode($items), $tenantId);
                $flash = ['type' => 'success', 'msg' => "Service '{$title}' added successfully."];
            } elseif ($typeFilter === 'testimonial') {
                $items = StudioFreelancerPortfolio::getTestimonialsList($tenantId);
                $newItem = [
                    'author'     => trim((string) ($_POST['author'] ?? 'Verified Client')),
                    'role'       => trim((string) ($_POST['role'] ?? 'Executive Leader')),
                    'quote'      => trim((string) ($_POST['quote'] ?? '')),
                    'rating'     => max(1, min(5, (int) ($_POST['rating'] ?? 5))),
                    'avatar_url' => trim((string) ($_POST['avatar_url'] ?? '')),
                ];
                array_unshift($items, $newItem);
                Database::setSetting('studio_content_testimonial', json_encode($items), $tenantId);
                $flash = ['type' => 'success', 'msg' => "Client endorsement from '{$newItem['author']}' added."];
            }
        }
    }
}

// ── Handle Action: Delete Item ──────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action_delete_item'])) {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => 'Security check failed.'];
    } else {
        $index = (int) ($_POST['item_index'] ?? -1);
        if ($index >= 0) {
            if ($typeFilter === 'portfolio' || $typeFilter === 'case_study') {
                $items = StudioFreelancerPortfolio::getProjects($tenantId);
                if (isset($items[$index])) {
                    array_splice($items, $index, 1);
                    Database::setSetting('studio_content_portfolio', json_encode($items), $tenantId);
                    $flash = ['type' => 'success', 'msg' => 'Item deleted from dynamic repository.'];
                }
            } elseif ($typeFilter === 'service') {
                $items = StudioFreelancerPortfolio::getServicesList($tenantId);
                if (isset($items[$index])) {
                    array_splice($items, $index, 1);
                    Database::setSetting('studio_content_service', json_encode($items), $tenantId);
                    $flash = ['type' => 'success', 'msg' => 'Service offering deleted.'];
                }
            } elseif ($typeFilter === 'testimonial') {
                $items = StudioFreelancerPortfolio::getTestimonialsList($tenantId);
                if (isset($items[$index])) {
                    array_splice($items, $index, 1);
                    Database::setSetting('studio_content_testimonial', json_encode($items), $tenantId);
                    $flash = ['type' => 'success', 'msg' => 'Endorsement deleted.'];
                }
            }
        }
    }
}

// Retrieve data for active view
$currentProfile = StudioFreelancerPortfolio::getProfile($tenantId);
$projects       = StudioFreelancerPortfolio::getProjects($tenantId);
$services       = StudioFreelancerPortfolio::getServicesList($tenantId);
$testimonials   = StudioFreelancerPortfolio::getTestimonialsList($tenantId);

$base = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') : '';

require SLATE_ROOT . '/admin/partials/header.php';
?>

<div class="sb-admin-wrapper">
    <?php sb_render_admin_nav('content'); ?>

    <?php if ($flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?>" role="status" style="margin-bottom: 20px;">
            <div><?= e($flash['msg']) ?></div>
            <?php if (!empty($flash['published_routes'])): ?>
                <div style="margin-top: 10px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <?php foreach ($flash['published_routes'] as $rtSlug): ?>
                        <a href="<?= e($base ? $base . '/' . $rtSlug : '/' . $rtSlug) ?>" target="_blank" class="btn btn-sm btn-outline" style="background: #ffffff;">
                            <?= sb_svg('external', 12) ?>
                            <span>/<?= e($rtSlug) ?> ↗</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Header Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 16px;">
        <div>
            <h1 style="margin: 0 0 4px 0; font-size: 1.4rem; font-weight: 700; color: #0f172a;">Dynamic Content & Data Engine</h1>
            <p style="margin: 0; font-size: 0.88rem; color: #64748b;">Manage portfolio items, client reviews, services, and profile settings that dynamically power your live site.</p>
        </div>
        <form method="post" style="margin: 0;">
            <input type="hidden" name="action_publish_site" value="1">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 8px; font-weight: 600; padding: 10px 18px;">
                <?= sb_svg('cloud-upload', 16) ?>
                <span>Sync & Publish Entire Site (5 Pages)</span>
            </button>
        </form>
    </div>

    <!-- Content Type Selector Pills -->
    <div class="sb-pill-filter" style="margin-bottom: 24px;">
        <a href="content.php?type=portfolio" class="sb-pill-item <?= $typeFilter === 'portfolio' ? 'active' : '' ?>">
            <?= sb_svg('content', 14) ?>
            <span>Portfolio Projects (<?= count($projects) ?>)</span>
        </a>
        <a href="content.php?type=service" class="sb-pill-item <?= $typeFilter === 'service' ? 'active' : '' ?>">
            <?= sb_svg('types-fields', 14) ?>
            <span>Services & Offerings (<?= count($services) ?>)</span>
        </a>
        <a href="content.php?type=testimonial" class="sb-pill-item <?= $typeFilter === 'testimonial' ? 'active' : '' ?>">
            <?= sb_svg('check', 14) ?>
            <span>Client Endorsements (<?= count($testimonials) ?>)</span>
        </a>
        <a href="content.php?type=case_study" class="sb-pill-item <?= $typeFilter === 'case_study' ? 'active' : '' ?>">
            <?= sb_svg('pages', 14) ?>
            <span>Case Studies (<?= count($projects) ?>)</span>
        </a>
        <a href="content.php?type=profile" class="sb-pill-item <?= $typeFilter === 'profile' ? 'active' : '' ?>">
            <?= sb_svg('site-seo', 14) ?>
            <span>Profile & Brand Bio</span>
        </a>
    </div>

    <?php if ($typeFilter === 'profile'): ?>
        <!-- Profile & Brand Bio Settings Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div>
                    <h2>Freelancer Brand & Bio Information</h2>
                    <div class="card-sub">This profile data dynamically feeds into site navigation, hero sections, copyright footers, and contact meta.</div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action_save_profile" value="1">
                <?= csrf_field() ?>
                <div class="field-row field-row-3">
                    <div class="field">
                        <label class="field-label">Full Name <span class="field-required">*</span></label>
                        <input type="text" name="name" required value="<?= e($currentProfile['name']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">Brand Monogram</label>
                        <input type="text" name="monogram" maxlength="4" value="<?= e($currentProfile['monogram']) ?>" placeholder="e.g. RH or ER">
                    </div>
                    <div class="field">
                        <label class="field-label">Professional Role / Title</label>
                        <input type="text" name="role" value="<?= e($currentProfile['role']) ?>">
                    </div>
                </div>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Availability Status</label>
                        <input type="text" name="status" value="<?= e($currentProfile['status']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">Inquiry / Work Email</label>
                        <input type="email" name="email" value="<?= e($currentProfile['email']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Hero Main Heading</label>
                    <input type="text" name="hero_heading" value="<?= e($currentProfile['hero_heading']) ?>">
                </div>
                <div class="field">
                    <label class="field-label">Hero Introduction Subtext</label>
                    <textarea name="hero_subtext" rows="3"><?= e($currentProfile['hero_subtext']) ?></textarea>
                </div>
                <div class="field-row field-row-4">
                    <div class="field">
                        <label class="field-label">Years Experience Metric</label>
                        <input type="text" name="stat_years" value="<?= e($currentProfile['stat_years']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">Shipped Apps Metric</label>
                        <input type="text" name="stat_apps" value="<?= e($currentProfile['stat_apps']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">Client Valuation Metric</label>
                        <input type="text" name="stat_val" value="<?= e($currentProfile['stat_val']) ?>">
                    </div>
                    <div class="field">
                        <label class="field-label">Delivery Metric</label>
                        <input type="text" name="stat_delivery" value="<?= e($currentProfile['stat_delivery']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Location / Timezone</label>
                    <input type="text" name="location" value="<?= e($currentProfile['location']) ?>">
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= sb_svg('check', 14) ?>
                    <span>Save Profile Settings</span>
                </button>
            </form>
        </div>

    <?php elseif ($typeFilter === 'portfolio' || $typeFilter === 'case_study'): ?>
        <!-- Add Project Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div>
                    <h2>Add New <?= $typeFilter === 'case_study' ? 'Case Study' : 'Portfolio Project' ?></h2>
                    <div class="card-sub">Create a project entry with metric badges, tags, and case study links.</div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action_add_item" value="1">
                <?= csrf_field() ?>
                <div class="field-row field-row-3">
                    <div class="field">
                        <label class="field-label">Project Title <span class="field-required">*</span></label>
                        <input type="text" name="title" required placeholder="e.g. NextGen Visual Analytics">
                    </div>
                    <div class="field">
                        <label class="field-label">Category / Domain</label>
                        <input type="text" name="category" placeholder="Fintech / SaaS Platform">
                    </div>
                    <div class="field">
                        <label class="field-label">Release Year</label>
                        <input type="text" name="year" value="<?= date('Y') ?>">
                    </div>
                </div>
                <div class="field-row field-row-3">
                    <div class="field">
                        <label class="field-label">Metric Badge (Highlight)</label>
                        <input type="text" name="metric" placeholder="+185% Daily Active Users">
                    </div>
                    <div class="field">
                        <label class="field-label">Cover Image URL</label>
                        <input type="url" name="image_url" placeholder="https://images.unsplash.com/...">
                    </div>
                    <div class="field">
                        <label class="field-label">Case Study Link URL</label>
                        <input type="text" name="link_url" value="/case-studies" placeholder="/case-studies">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Tags (comma-separated)</label>
                    <input type="text" name="tags" placeholder="React, TypeScript, Canvas, Next.js">
                </div>
                <div class="field">
                    <label class="field-label">Summary Description</label>
                    <textarea name="description" rows="2" placeholder="Brief project summary highlighting the problem solved and technical architecture..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= sb_svg('plus', 14) ?>
                    <span>Save Project Entry</span>
                </button>
            </form>
        </div>

        <!-- Current Projects Grid -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Active Projects (<?= count($projects) ?>)</h2>
                    <div class="card-sub">Rendered dynamically across <code>/portfolio</code> and <code>/case-studies</code>.</div>
                </div>
            </div>
            <div class="sb-feature-grid" style="margin-top: 16px;">
                <?php foreach ($projects as $idx => $item): ?>
                <div class="sb-feature-card">
                    <div>
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= e($item['image_url']) ?>" alt="<?= e($item['title'] ?? '') ?>" style="width: 100%; height: 160px; object-fit: cover; border-radius: 10px; margin-bottom: 14px;">
                        <?php endif; ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span class="badge badge-active"><?= e($item['category'] ?? 'SaaS') ?></span>
                            <?php if (!empty($item['metric'])): ?>
                                <span style="font-size: 0.8rem; font-weight: 600; color: #10b981;"><?= e($item['metric']) ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="sb-feature-title"><?= e($item['title'] ?? 'Project') ?></h3>
                        <p class="sb-feature-desc"><?= e($item['description'] ?? '') ?></p>
                        <?php if (!empty($item['tags'])): ?>
                            <div style="font-size: 0.8rem; color: #64748b; margin-top: 10px;">
                                Tags: <code><?= e($item['tags']) ?></code>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="sb-feature-footer">
                        <span style="font-size: 0.78rem; color: #94a3b8;">Year: <strong><?= e($item['year'] ?? '2026') ?></strong></span>
                        <form method="post" onsubmit="return confirm('Delete this project entry?');" style="margin: 0;">
                            <input type="hidden" name="action_delete_item" value="1">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;">
                                <?= sb_svg('trash', 12) ?>
                                <span>Delete</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($typeFilter === 'service'): ?>
        <!-- Add Service Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div>
                    <h2>Add Service Offering</h2>
                    <div class="card-sub">Define structured deliverables and consulting packages.</div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action_add_item" value="1">
                <?= csrf_field() ?>
                <div class="field-row field-row-2">
                    <div class="field">
                        <label class="field-label">Service Title <span class="field-required">*</span></label>
                        <input type="text" name="title" required placeholder="e.g. Design Systems & Tokens Architecture">
                    </div>
                    <div class="field">
                        <label class="field-label">Index Number</label>
                        <input type="text" name="number" value="<?= str_pad((string) (count($services) + 1), 2, '0', STR_PAD_LEFT) ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Scope Description</label>
                    <textarea name="description" rows="2" placeholder="Explain the high-value impact of this offering..."></textarea>
                </div>
                <div class="field">
                    <label class="field-label">Key Deliverables (comma-separated)</label>
                    <input type="text" name="deliverables" placeholder="Figma Components, Design Tokens, React Architecture, WCAG Compliance">
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= sb_svg('plus', 14) ?>
                    <span>Save Service Offering</span>
                </button>
            </form>
        </div>

        <!-- Current Services Grid -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Active Service Offerings (<?= count($services) ?>)</h2>
                    <div class="card-sub">Rendered dynamically across <code>/services</code> and the main showcase.</div>
                </div>
            </div>
            <div class="sb-feature-grid" style="margin-top: 16px;">
                <?php foreach ($services as $idx => $s): ?>
                <div class="sb-feature-card">
                    <div>
                        <div style="font-size: 1.2rem; font-weight: 800; color: #6366f1; margin-bottom: 8px;"><?= e($s['number'] ?? '01') ?></div>
                        <h3 class="sb-feature-title"><?= e($s['title'] ?? '') ?></h3>
                        <p class="sb-feature-desc"><?= e($s['description'] ?? '') ?></p>
                        <?php if (!empty($s['deliverables'])): ?>
                            <div style="margin-top: 12px; font-size: 0.82rem; color: #475569;">
                                <strong>Deliverables:</strong>
                                <ul style="margin: 6px 0 0 16px; padding: 0;">
                                    <?php foreach (explode(',', $s['deliverables']) as $deliv): ?>
                                        <li><?= e(trim($deliv)) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="sb-feature-footer">
                        <span></span>
                        <form method="post" onsubmit="return confirm('Delete this service?');" style="margin: 0;">
                            <input type="hidden" name="action_delete_item" value="1">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;">
                                <?= sb_svg('trash', 12) ?>
                                <span>Delete</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif ($typeFilter === 'testimonial'): ?>
        <!-- Add Testimonial Card -->
        <div class="card" style="margin-bottom: 24px;">
            <div class="card-header">
                <div>
                    <h2>Add Client Endorsement</h2>
                    <div class="card-sub">Collect and display verified client quotes and founder reviews.</div>
                </div>
            </div>
            <form method="post">
                <input type="hidden" name="action_add_item" value="1">
                <?= csrf_field() ?>
                <div class="field-row field-row-3">
                    <div class="field">
                        <label class="field-label">Client Name <span class="field-required">*</span></label>
                        <input type="text" name="author" required placeholder="e.g. Marcus Vance">
                    </div>
                    <div class="field">
                        <label class="field-label">Role & Company</label>
                        <input type="text" name="role" placeholder="VP of Product, CloudScale Inc">
                    </div>
                    <div class="field">
                        <label class="field-label">Star Rating</label>
                        <select name="rating">
                            <option value="5" selected>5 Stars (Excellent)</option>
                            <option value="4">4 Stars (Great)</option>
                            <option value="3">3 Stars</option>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label class="field-label">Client Avatar URL (Optional)</label>
                    <input type="url" name="avatar_url" placeholder="https://images.unsplash.com/...">
                </div>
                <div class="field">
                    <label class="field-label">Client Quote / Testimonial</label>
                    <textarea name="quote" rows="3" placeholder="Enter endorsement quote..."></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                    <?= sb_svg('plus', 14) ?>
                    <span>Save Client Endorsement</span>
                </button>
            </form>
        </div>

        <!-- Current Testimonials Grid -->
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Active Client Endorsements (<?= count($testimonials) ?>)</h2>
                    <div class="card-sub">Rendered dynamically across <code>/testimonials</code> and portfolio showcase.</div>
                </div>
            </div>
            <div class="sb-feature-grid" style="margin-top: 16px;">
                <?php foreach ($testimonials as $idx => $t): ?>
                <div class="sb-feature-card">
                    <div>
                        <div style="color: #f59e0b; margin-bottom: 8px; font-size: 0.95rem; font-weight: 700;">
                            <?= str_repeat('★', (int) ($t['rating'] ?? 5)) ?>
                        </div>
                        <p class="sb-feature-desc" style="font-style: italic; color: #334155;">“<?= e($t['quote'] ?? '') ?>”</p>
                        <div style="margin-top: 14px; display: flex; align-items: center; gap: 10px;">
                            <?php if (!empty($t['avatar_url'])): ?>
                                <img src="<?= e($t['avatar_url']) ?>" alt="<?= e($t['author'] ?? '') ?>" style="width: 36px; height: 36px; border-radius: 50%; object-fit: cover;">
                            <?php endif; ?>
                            <div>
                                <strong style="font-size: 0.88rem; color: #0f172a; display: block;"><?= e($t['author'] ?? 'Client') ?></strong>
                                <span style="font-size: 0.78rem; color: #64748b;"><?= e($t['role'] ?? '') ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="sb-feature-footer">
                        <span class="badge badge-active" style="background: #ecfdf5; color: #059669;">Verified Client</span>
                        <form method="post" onsubmit="return confirm('Delete this endorsement?');" style="margin: 0;">
                            <input type="hidden" name="action_delete_item" value="1">
                            <input type="hidden" name="item_index" value="<?= $idx ?>">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-sm btn-outline" style="color: #ef4444;">
                                <?= sb_svg('trash', 12) ?>
                                <span>Delete</span>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Query Loop Guide & Integration Note -->
    <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-top: 24px;">
        <h4 style="margin: 0 0 8px 0; font-size: 0.95rem; font-weight: 700; color: #0f172a;">Dynamic Content Architecture</h4>
        <p style="font-size: 0.88rem; color: #64748b; margin: 0 0 10px 0;">
            Any changes saved here immediately update the canonical documents when synchronizing the site. The published pages are served from the high-performance compiler with sub-millisecond TTFB and zero runtime DB overhead for visitors.
        </p>
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <a href="<?= e($base ? $base . '/portfolio' : '/portfolio') ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= sb_svg('external', 12) ?>
                <span>View /portfolio ↗</span>
            </a>
            <a href="<?= e($base ? $base . '/services' : '/services') ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= sb_svg('external', 12) ?>
                <span>View /services ↗</span>
            </a>
            <a href="<?= e($base ? $base . '/case-studies' : '/case-studies') ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= sb_svg('external', 12) ?>
                <span>View /case-studies ↗</span>
            </a>
            <a href="<?= e($base ? $base . '/testimonials' : '/testimonials') ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= sb_svg('external', 12) ?>
                <span>View /testimonials ↗</span>
            </a>
            <a href="<?= e($base ? $base . '/contact' : '/contact') ?>" target="_blank" class="btn btn-sm btn-outline">
                <?= sb_svg('external', 12) ?>
                <span>View /contact ↗</span>
            </a>
        </div>
    </div>
</div>

<?php require SLATE_ROOT . '/admin/partials/footer.php'; ?>
